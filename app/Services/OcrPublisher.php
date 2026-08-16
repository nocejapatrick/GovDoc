<?php

namespace App\Services;

use App\Models\Document;
use Aws\Sqs\SqsClient;

class OcrPublisher
{
    private SqsClient $sqs;

    public function __construct()
    {
        // Reuses the same credentials/endpoint as the "sqs" queue connection
        // (config/queue.php) so both point at Floci locally and real SQS in prod.
        $connection = config('queue.connections.sqs');

        $this->sqs = new SqsClient([
            'version' => 'latest',
            'region' => $connection['region'],
            'endpoint' => $connection['endpoint'],
            'credentials' => [
                'key' => $connection['key'],
                'secret' => $connection['secret'],
            ],
        ]);
    }

    public function publish(Document $document): void
    {
        $payload = json_encode([
            'document_id' => $document->id,
            'bucket' => config('filesystems.disks.s3.bucket'),
            'key' => $document->storage_path,
            'callback_url' => rtrim(config('services.ocr.callback_base'), '/')
                . "/api/internal/documents/{$document->id}/result",
            'progress_url' => rtrim(config('services.ocr.callback_base'), '/')
                . "/api/internal/documents/{$document->id}/progress",
        ], JSON_THROW_ON_ERROR);

        $this->sqs->sendMessage([
            'QueueUrl' => config('services.ocr.sqs_queue_url'),
            'MessageBody' => $payload,
        ]);
    }
}