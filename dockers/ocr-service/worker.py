"""
RabbitMQ worker for the OCR service.

Consumes JSON messages from the "ocr-jobs" queue:

    {
        "document_id": 123,
        "bucket": "documents",
        "key": "uploads/2026/07/contract.pdf",
        "callback_url": "http://host.docker.internal:8000/api/internal/documents/123/result"
    }

Flow per message:
    1. Download the PDF from MinIO (S3-compatible, same boto3 code as AWS)
    2. Run extraction (native text / OCR per page)
    3. POST the result to the Laravel callback, signed with HMAC-SHA256
    4. Ack the message ONLY after the callback succeeds

Retry topology (declared by this worker on startup, idempotent):

    ocr-jobs        main work queue
    ocr-jobs-retry  holding pen with a 60s TTL; expired messages are
                    dead-lettered back into ocr-jobs (i.e. delayed retry)
    ocr-jobs-dlq    final resting place after MAX_RETRIES failed attempts

Failure semantics:
    - Permanent errors (corrupt/encrypted PDF): report "failed" to the
      callback, ack. Retrying would never succeed.
    - Transient errors (MinIO hiccup, Laravel down): republish to the
      retry queue with an incremented x-retry-count header; after
      MAX_RETRIES the message goes to the DLQ instead.

Environment variables:
    RABBITMQ_URL           e.g. amqp://guest:guest@rabbitmq:5672/%2F
    OCR_CALLBACK_SECRET    shared secret for HMAC signing
    S3_ENDPOINT_URL        e.g. http://minio:9000
    AWS_ACCESS_KEY_ID      MinIO access key
    AWS_SECRET_ACCESS_KEY  MinIO secret key
"""

import hashlib
import hmac
import json
import logging
import os
import sys
import time

import boto3
import httpx
import pika
from botocore.config import Config

from extractor import PermanentExtractionError, extract_pdf

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
logger = logging.getLogger("ocr-worker")

RABBITMQ_URL = os.environ["RABBITMQ_URL"]
CALLBACK_SECRET = os.environ["OCR_CALLBACK_SECRET"]

QUEUE = "ocr-jobs"
RETRY_QUEUE = "ocr-jobs-retry"
DLQ = "ocr-jobs-dlq"
RETRY_DELAY_MS = 60_000
MAX_RETRIES = 3

s3 = boto3.client(
    "s3",
    endpoint_url=os.environ["S3_ENDPOINT_URL"],
    config=Config(s3={"addressing_style": "path"}),  # required for MinIO
)


def declare_topology(channel: pika.channel.Channel) -> None:
    channel.queue_declare(queue=DLQ, durable=True)

    channel.queue_declare(
        queue=RETRY_QUEUE,
        durable=True,
        arguments={
            "x-message-ttl": RETRY_DELAY_MS,
            "x-dead-letter-exchange": "",
            "x-dead-letter-routing-key": QUEUE,  # expired -> back to main queue
        },
    )

    channel.queue_declare(queue=QUEUE, durable=True)


def on_message(channel, method, properties, body: bytes) -> None:
    try:
        handle(channel, properties, body)
    except Exception:
        logger.exception("Transient failure")
        retry_or_dead_letter(channel, properties, body)

    # In every path the original message is done: success, permanent
    # failure (reported), republished to retry, or moved to the DLQ.
    channel.basic_ack(delivery_tag=method.delivery_tag)


def handle(channel, properties, body: bytes) -> None:
    job = json.loads(body)
    document_id = job["document_id"]
    bucket, key = job["bucket"], job["key"]
    callback_url = job["callback_url"]
    progress_url = job.get("progress_url")
    on_progress = make_progress_reporter(progress_url) if progress_url else None

    logger.info("Processing document %s (s3://%s/%s)", document_id, bucket, key)

    try:
        pdf = s3.get_object(Bucket=bucket, Key=key)["Body"].read()
        result = extract_pdf(pdf, filename=key.rsplit("/", 1)[-1], on_progress=on_progress)
    except PermanentExtractionError as exc:
        logger.warning("Permanent failure for document %s: %s", document_id, exc)
        send_callback(callback_url, {
            "document_id": document_id,
            "status": "failed",
            "error": str(exc),
        })
        return

    send_callback(callback_url, {
        "document_id": document_id,
        "status": "processed",
        "method": result["method"],
        "page_count": result["page_count"],
        "duration_seconds": result["duration_seconds"],
        "text": result["text"],
        "pages": [
            {"page": p["page"], "method": p["method"], "char_count": p["char_count"]}
            for p in result["pages"]
        ],
    })

    logger.info(
        "Document %s done: %d pages, method=%s, %.2fs",
        document_id, result["page_count"], result["method"], result["duration_seconds"],
    )


def retry_or_dead_letter(channel, properties, body: bytes) -> None:
    headers = dict(properties.headers or {})
    attempts = int(headers.get("x-retry-count", 0))

    if attempts >= MAX_RETRIES:
        logger.error("Message exceeded %d retries; moving to DLQ", MAX_RETRIES)
        target, headers["x-retry-count"] = DLQ, attempts
    else:
        target, headers["x-retry-count"] = RETRY_QUEUE, attempts + 1
        logger.info("Scheduling retry %d/%d in %ds", attempts + 1, MAX_RETRIES, RETRY_DELAY_MS // 1000)

    channel.basic_publish(
        exchange="",
        routing_key=target,
        body=body,
        properties=pika.BasicProperties(
            delivery_mode=pika.DeliveryMode.Persistent,
            content_type="application/json",
            headers=headers,
        ),
    )


def send_callback(url: str, payload: dict) -> None:
    body = json.dumps(payload)
    signature = hmac.new(
        CALLBACK_SECRET.encode(), body.encode(), hashlib.sha256
    ).hexdigest()

    response = httpx.post(
        url,
        content=body,
        headers={
            "Content-Type": "application/json",
            "X-OCR-Signature": signature,
        },
        timeout=60,
    )
    response.raise_for_status()

def make_progress_reporter(progress_url: str):
    last_sent = 0.0

    def report(page: int, total: int) -> None:
        nonlocal last_sent
        now = time.monotonic()

        # Throttle: at most one ping every 2s, but always send the final page.
        if now - last_sent < 2 and page != total:
            return
        last_sent = now

        body = json.dumps({"page": page, "total": total})
        signature = hmac.new(CALLBACK_SECRET.encode(), body.encode(), hashlib.sha256).hexdigest()

        try:
            httpx.post(
                progress_url,
                content=body,
                headers={"Content-Type": "application/json", "X-OCR-Signature": signature},
                timeout=5,
            )
        except Exception:
            pass  # progress is decoration — never let it affect the job

    return report


def main() -> None:
    # RabbitMQ may still be booting when the container starts.
    while True:
        try:
            connection = pika.BlockingConnection(pika.URLParameters(RABBITMQ_URL))
            break
        except pika.exceptions.AMQPConnectionError:
            logger.info("RabbitMQ not ready, retrying in 3s...")
            time.sleep(3)

    channel = connection.channel()
    declare_topology(channel)

    # One message at a time: OCR is CPU-bound, and this makes
    # horizontal scaling (multiple workers) distribute work evenly.
    channel.basic_qos(prefetch_count=1)
    channel.basic_consume(queue=QUEUE, on_message_callback=on_message)

    logger.info("Worker started, consuming from '%s'", QUEUE)
    channel.start_consuming()


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        sys.exit(0)
