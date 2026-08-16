<?php

namespace App\Jobs;

use App\Models\DocumentChunk;
use App\Services\OllamaClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Pgvector\Laravel\Distance;

class AnswerAssistantQuestion implements ShouldQueue
{
    use Queueable;

    private const TOP_K = 6;

    // A local CPU-bound model can take several minutes, especially on a cold
    // start while Ollama loads the model into memory — well past the queue
    // worker's default 60s job timeout.
    public int $timeout = 1800;

    public function __construct(
        private readonly string $queryId,
        private readonly int $userId,
        private readonly string $question,
    ) {
        $this->queue = 'assistant-questions';
    }

    public function handle(OllamaClient $ollama): void
    {
        try {
            $questionEmbedding = $ollama->embed($this->question);

            $chunks = DocumentChunk::query()
                ->whereHas('document', function ($query) {
                    $query->where('include_in_llm', true)
                        ->where('llm_status', 'ready')
                        ->where(fn ($q) => $q->where('user_id', $this->userId)->orWhere('visibility', 'public'));
                })
                ->with('document:id,original_filename')
                ->nearestNeighbors('embedding', $questionEmbedding, Distance::Cosine)
                ->take(self::TOP_K)
                ->get();

            if ($chunks->isEmpty()) {
                $this->store('ready', [
                    'answer' => "I couldn't find anything in your AI-enabled documents to answer that. Try including more documents in the AI assistant, or rephrase your question.",
                    'sources' => [],
                ]);

                return;
            }

            $context = $chunks->map(fn (DocumentChunk $chunk) => sprintf(
                "From \"%s\":\n%s",
                $chunk->document->original_filename,
                $chunk->content,
            ))->implode("\n\n---\n\n");

            $answer = $ollama->chat(
                systemPrompt: "You are a helpful assistant answering questions about the user's uploaded documents. "
                    ."Answer only using the context below. If the answer isn't in the context, say you don't know. "
                    ."Be concise.\n\nContext:\n{$context}",
                userPrompt: $this->question,
            );

            // nearestNeighbors() orders by closest match first, so the first chunk's
            // document is the single most relevant source — cite only that one rather
            // than every document touched by the top-K chunks used for context.
            $this->store('ready', [
                'answer' => $answer,
                'sources' => [$chunks->first()->document->original_filename],
            ]);
        } catch (\Throwable $e) {
            $this->store('failed', [
                'answer' => 'Something went wrong asking the assistant. Please try again.',
                'sources' => [],
            ]);
            report($e);
        }
    }

    /**
     * @param  array{answer: string, sources: array<int, string>}  $result
     */
    private function store(string $status, array $result): void
    {
        Cache::put("assistant_query:{$this->queryId}", [
            'status' => $status,
            ...$result,
        ], now()->addMinutes(15));
    }
}
