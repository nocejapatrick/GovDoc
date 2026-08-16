<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class OllamaClient
{
    private PendingRequest $client;

    public function __construct()
    {
        $this->client = Http::baseUrl(config('services.ollama.host'))->acceptJson();
    }

    /**
     * @return array<int, float>
     */
    public function embed(string $text): array
    {
        $response = $this->client->timeout(60)->post('/api/embeddings', [
            'model' => config('services.ollama.embed_model'),
            'prompt' => $text,
        ])->throw()->json();

        return $response['embedding'];
    }

    public function chat(string $systemPrompt, string $userPrompt): string
    {
        $response = $this->client->timeout(120)->post('/api/chat', [
            'model' => config('services.ollama.chat_model'),
            'stream' => false,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ])->throw()->json();

        return $response['message']['content'];
    }
}
