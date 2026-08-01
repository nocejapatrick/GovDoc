<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class DocumentIndexer
{
    private PendingRequest $client;
    private string $index;

    public function __construct()
    {
        $this->client = Http::baseUrl(config('services.opensearch.host'))->acceptJson();
        $this->index = config('services.opensearch.index');
    }

    public function ensureIndex(): void
    {
        if ($this->client->head("/{$this->index}")->successful()) {
            return;
        }

        $this->client->put("/{$this->index}", [
            'mappings' => [
                'properties' => [
                    'user_id' => ['type' => 'integer'],
                    'filename' => ['type' => 'text'],
                    'text' => ['type' => 'text', 'analyzer' => 'english'],
                    'method' => ['type' => 'keyword'],
                    'created_at' => ['type' => 'date']
                ],
            ],
        ])->throw();
    }

    public function index(Document $document): void
    {
        $this->ensureIndex();

        $this->client->put("/{$this->index}/_doc/{$document->id}", [
            'user_id' => $document->user_id,
            'filename' => $document->original_filename,
            'text' => $document->extracted_text,
            'method' => $document->extraction_method,
            'created_at' => $document->created_at->toIso8601String(),
            'visibility' => $document->visibility,
            'owner' => $document->user->name,
        ])->throw();
    }

    public function delete(Document $document): void
    {
        $this->client->delete("/{$this->index}/_doc/{$document->id}");
    }

    public function search(int $userId, string $query, int $size = 10): array
    {
        $this->ensureIndex();

        $response = $this->client->post("/{$this->index}/_search", [
            'size' => $size,
            'query' => [
                'bool' => [
                    'must' => [[
                        'bool' => [
                            'should' => [
                                [
                                    'multi_match' => [
                                        'query' => $query,
                                        'fields' => ['text', 'filename^2'],
                                        'type' => 'bool_prefix',
                                    ],
                                ],
                                [
                                    'multi_match' => [
                                        'query' => $query,
                                        'fields' => ['text', 'filename^2'],
                                        'fuzziness' => 'AUTO',
                                    ],
                                ],
                            ],
                            'minimum_should_match' => 1,
                        ],
                    ]],
                    'filter' => [[
                        'bool' => [
                            'should' => [
                                ['term' => ['user_id' => $userId]],
                                ['term' => ['visibility' => 'public']],
                            ],
                            'minimum_should_match' => 1,
                        ],
                    ]],
                ],
            ],
            'highlight' => [
                'encoder' => 'html',
                'fields' => [
                    'text' => [
                        'fragment_size' => 160,
                        'number_of_fragments' => 3,
                    ],
                ],
            ],
        ])->throw()->json();

        return [
            'total' => $response['hits']['total']['value'] ?? 0,
            'hits' => collect($response['hits']['hits'] ?? [])->map(fn ($hit) => [
                'id' => (int) $hit['_id'],
                'filename' => $hit['_source']['filename'],
                'method' => $hit['_source']['method'],
                'created_at' => $hit['_source']['created_at'],
                'score' => $hit['_score'],
                'snippets' => $hit['highlight']['text'] ?? [],
                'visibility' => $hit['_source']['visibility'] ?? 'private',
                'owner' => $hit['_source']['owner'] ?? null,
            ])->values()->all(),
        ];
    }
}