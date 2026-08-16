<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Models\Document;
use App\Models\Setting;
use App\Services\DocumentIndexer;

class DocumentResultController extends Controller
{
    public function __invoke(Request $request, Document $document): Response
    {
        $expected = hash_hmac(
            'sha256',
            $request->getContent(),
            config('services.ocr.callback_secret'),
        );

        abort_unless(
            hash_equals($expected, (string) $request->header('X-OCR-Signature')),
            403,
            'Invalid signature.',
        );

        if ($request->input('status') === 'failed') {
            $document->update([
                'status' => 'failed',
                'error' => $request->input('error', 'Unknown extraction error.'),
            ]);

            return response()->noContent();
        }

        $document->update([
            'status' => 'processed',
            'extraction_method' => $request->input('method'),
            'page_count' => $request->input('page_count'),
            'extracted_text' => $request->input('text'),
            'error' => null,
        ]);

        if ($document->type === 'general') {
            try {
                app(\App\Services\DocumentIndexer::class)->index($document->refresh());
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if ($document->include_in_llm && Setting::flag('ai_module_enabled')) {
            $document->update(['llm_status' => 'pending']);
            \App\Jobs\EmbedDocument::dispatch($document->refresh());
        }

        return response()->noContent();
    }
}