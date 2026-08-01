<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\DocumentIndexer;
use Illuminate\Console\Command;

class ReindexDocuments extends Command
{
    protected $signature = 'documents:reindex';
    protected $description = 'Index all processed documents into OpenSearch';

    public function handle(DocumentIndexer $indexer): int
    {
        $count = 0;

        foreach (Document::where('status', 'processed')->cursor() as $document) {
            $indexer->index($document);
            $count++;
        }

        $this->info("Indexed {$count} documents.");

        return self::SUCCESS;
    }
}