<?php

namespace App\Services;

use App\Models\Document;

class DocumentEmbedder
{
    private const CHUNK_SIZE = 1000;

    private const CHUNK_OVERLAP = 150;

    public function __construct(private readonly OllamaClient $ollama) {}

    public function embed(Document $document): void
    {
        $document->chunks()->delete();

        $chunks = $this->chunk((string) $document->extracted_text);

        foreach ($chunks as $index => $content) {
            $document->chunks()->create([
                'chunk_index' => $index,
                'content' => $content,
                'embedding' => $this->ollama->embed($content),
            ]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function chunk(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        $chunks = [];
        $length = mb_strlen($text);
        $start = 0;

        while ($start < $length) {
            $end = min($start + self::CHUNK_SIZE, $length);

            // Prefer breaking on whitespace rather than mid-word, when not at the very end.
            if ($end < $length) {
                $breakAt = mb_strrpos(mb_substr($text, $start, $end - $start), ' ');
                if ($breakAt !== false && $breakAt > 0) {
                    $end = $start + $breakAt;
                }
            }

            $chunks[] = trim(mb_substr($text, $start, $end - $start));

            if ($end >= $length) {
                break;
            }

            $start = max($end - self::CHUNK_OVERLAP, $start + 1);
        }

        return array_values(array_filter($chunks, fn ($c) => $c !== ''));
    }
}
