<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\DocumentEmbedder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class EmbedDocument implements ShouldQueue
{
    use Queueable;

    // Embedding calls Ollama once per chunk, sequentially — a large document
    // can take several minutes on a local CPU-bound model, well past the
    // queue worker's default 60s job timeout (which kills the whole worker).
    public int $timeout = 1800;

    public function __construct(private readonly Document $document)
    {
        $this->queue = 'govdoc-jobs';
    }

    public function handle(DocumentEmbedder $embedder): void
    {
        try {
            $embedder->embed($this->document);
            $this->document->update(['llm_status' => 'ready']);
        } catch (\Throwable $e) {
            $this->document->update(['llm_status' => 'failed']);
            report($e);
        }
    }
}
