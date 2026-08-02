<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\DocumentRoute;
use App\Models\DocumentVersion;
use App\Models\RoutingCase;
use App\Services\DocumentIndexer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ResetDocumentData extends Command
{
    protected $signature = 'documents:reset {--keep-files : Skip deleting files from MinIO storage}';
    protected $description = 'Wipe all documents, versions, routes, and routing cases for a clean testing slate.';

    public function handle(DocumentIndexer $indexer): int
    {
        if (! $this->confirm('This will permanently delete ALL documents, versions, routes, and routing cases. Continue?')) {
            $this->info('Aborted.');
            return self::SUCCESS;
        }

        if (! $this->option('keep-files')) {
            $this->info('Deleting files from storage...');

            Document::cursor()->each(function (Document $doc) {
                foreach ($doc->versions as $version) {
                    Storage::disk('s3')->delete($version->storage_path);
                }
            });
        }

        $this->info('Clearing OpenSearch index...');
        Document::where('type', 'general')->cursor()->each(function (Document $doc) use ($indexer) {
            try {
                $indexer->delete($doc);
            } catch (\Throwable $e) {
                // index may already be gone/unreachable — non-fatal for a reset
            }
        });

        $this->info('Truncating tables...');

        // Order matters: children before parents, or disable FK checks around it.
        DB::statement('TRUNCATE TABLE document_routes RESTART IDENTITY CASCADE');
        DB::statement('TRUNCATE TABLE document_versions RESTART IDENTITY CASCADE');
        DB::statement('TRUNCATE TABLE documents RESTART IDENTITY CASCADE');
        DB::statement('TRUNCATE TABLE routing_cases RESTART IDENTITY CASCADE');

        $this->info('Done. All document data has been reset.');

        return self::SUCCESS;
    }
}