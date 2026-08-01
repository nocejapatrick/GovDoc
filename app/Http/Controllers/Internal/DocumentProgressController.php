<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DocumentProgressController extends Controller
{
    public function __invoke(Request $request, Document $document): Response
    {
        $expected = hash_hmac('sha256', $request->getContent(), config('services.ocr.callback_secret'));

        abort_unless(hash_equals($expected, (string) $request->header('X-OCR-Signature')), 403);

        if ($document->status === 'pending') {
            $document->update([
                'progress_page' => (int) $request->input('page'),
                'progress_total' => (int) $request->input('total'),
            ]);
        }

        return response()->noContent();
    }
}