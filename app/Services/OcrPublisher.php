<?php

namespace App\Services;

use App\Models\Document;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class OcrPublisher
{
    private const QUEUE = 'ocr-jobs';

    public function publish(Document $document): void
    {
        $connection = new AMQPStreamConnection(
            config('services.rabbitmq.host'),
            config('services.rabbitmq.port'),
            config('services.rabbitmq.user'),
            config('services.rabbitmq.password'),
        );

        $channel = $connection->channel();

        $channel->queue_declare(self::QUEUE, false, true, false, false);

        $payload = json_encode([
            'document_id' => $document->id,
            'bucket' => config('filesystems.disks.s3.bucket'),
            'key' => $document->storage_path,
            'callback_url' => rtrim(config('services.ocr.callback_base'), '/')
                . "/api/internal/documents/{$document->id}/result",
            'progress_url' => rtrim(config('services.ocr.callback_base'), '/')
                . "/api/internal/documents/{$document->id}/progress",
        ], JSON_THROW_ON_ERROR);

        $channel->basic_publish(
            new AMQPMessage($payload, [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'content_type' => 'application/json',
            ]),
            '',
            self::QUEUE,
        );

        $channel->close();
        $connection->close();
    }
}