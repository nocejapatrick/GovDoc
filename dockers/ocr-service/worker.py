"""
SQS worker for the OCR service.

Consumes JSON messages from the "ocr-jobs" queue:

    {
        "document_id": 123,
        "bucket": "documents",
        "key": "uploads/2026/07/contract.pdf",
        "callback_url": "http://host.docker.internal:8000/api/internal/documents/123/result"
    }

Flow per message:
    1. Download the PDF from Floci's S3-compatible API (same boto3 code as real AWS S3)
    2. Run extraction (native text / OCR per page)
    3. POST the result to the Laravel callback, signed with HMAC-SHA256
    4. Delete the message ONLY after the callback succeeds

Retry topology: handled entirely by the queue's redrive policy (set at
creation time — see the floci-init step in dockers/docker-compose.yml),
which moves a message to ocr-jobs-dlq automatically after 3 receives.
No retry queue or manual retry-count header needed, unlike the old
RabbitMQ TTL/dead-letter-exchange setup this replaces.

Failure semantics:
    - Permanent errors (corrupt/encrypted PDF): report "failed" to the
      callback, delete the message. Retrying would never succeed.
    - Transient errors (Floci hiccup, Laravel down): make the message
      visible again in 60s via change_message_visibility, without
      deleting it. SQS counts the receive automatically toward the
      queue's maxReceiveCount.

Environment variables:
    SQS_ENDPOINT_URL       e.g. http://floci:4566
    OCR_JOBS_QUEUE_URL     e.g. http://floci:4566/000000000000/ocr-jobs
    AWS_DEFAULT_REGION     e.g. us-east-1
    OCR_CALLBACK_SECRET    shared secret for HMAC signing
    S3_ENDPOINT_URL        e.g. http://floci:4566
    AWS_ACCESS_KEY_ID      Floci access key (unused in prod — real IAM role there)
    AWS_SECRET_ACCESS_KEY  Floci secret key (unused in prod — real IAM role there)
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
from botocore.config import Config

from extractor import PermanentExtractionError, extract_pdf

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
logger = logging.getLogger("ocr-worker")

QUEUE_URL = os.environ["OCR_JOBS_QUEUE_URL"]
CALLBACK_SECRET = os.environ["OCR_CALLBACK_SECRET"]

RETRY_DELAY_SECONDS = 60

s3 = boto3.client(
    "s3",
    endpoint_url=os.environ["S3_ENDPOINT_URL"],
    config=Config(s3={"addressing_style": "path"}),  # required for Floci (no virtual-host DNS locally)
)

sqs = boto3.client(
    "sqs",
    endpoint_url=os.environ["SQS_ENDPOINT_URL"],
    region_name=os.environ.get("AWS_DEFAULT_REGION", "us-east-1"),
)


def handle(body: bytes) -> None:
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
    logger.info("Worker started, consuming from '%s'", QUEUE_URL)

    while True:
        try:
            response = sqs.receive_message(
                QueueUrl=QUEUE_URL,
                MaxNumberOfMessages=1,
                WaitTimeSeconds=20,  # long poll
            )
        except Exception:
            logger.exception("SQS not reachable, retrying in 3s...")
            time.sleep(3)
            continue

        for message in response.get("Messages", []):
            receipt_handle = message["ReceiptHandle"]

            try:
                handle(message["Body"].encode())
                sqs.delete_message(QueueUrl=QUEUE_URL, ReceiptHandle=receipt_handle)
            except Exception:
                logger.exception("Transient failure; retrying in %ds", RETRY_DELAY_SECONDS)
                sqs.change_message_visibility(
                    QueueUrl=QUEUE_URL,
                    ReceiptHandle=receipt_handle,
                    VisibilityTimeout=RETRY_DELAY_SECONDS,
                )


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        sys.exit(0)
