<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\OcrPublisher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use App\Services\DocumentIndexer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Http;
use App\Models\User;
use App\Models\OrgUnit;

class DocumentController extends Controller
{
    public function index(Request $request): Response
    {
        $documents = Document::query()
                    ->where(fn ($q) => $q
                        ->where('user_id', $request->user()->id))
                    ->latest()
                    ->paginate(10);

        return Inertia::render('documents/Index', [
            'documents' => $documents->through(fn (Document $doc) => [
                'id' => $doc->id,
                'user_id'=> $doc->user_id,
                'original_filename' => $doc->original_filename,
                'status' => $doc->status,
                'extraction_method' => $doc->extraction_method,
                'page_count' => $doc->page_count,
                'error' => $doc->error,
                'size_kb' => (int) round($doc->size_bytes / 1024),
                'created_at' => $doc->created_at->format('M j, Y H:i'),
                'progress_page' => $doc->progress_page,
                'progress_total' => $doc->progress_total,
                'visibility' => $doc->visibility,
                'owner' => $doc->user->name,
                'current_holder_id' => $doc->current_holder_id,
                'current_holder_name' => $doc->currentHolder?->name,
                'tracking_status' => $doc->tracking_status,
                'has_been_routed' => $doc->routing_case_id !== null,
            ]),
        ]);
    }

    public function store(Request $request, OcrPublisher $publisher): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:204800'], // 50 MB
            'visibility' => ['required', 'in:private,public'],
        ]);

        $file = $request->file('file');

        $path = $file->store('uploads/' . now()->format('Y/m'), 's3');

        $document = $request->user()->documents()->create([
            'original_filename' => $file->getClientOriginalName(),
            'storage_path' => $path,
            'size_bytes' => $file->getSize(),
            'status' => 'pending',
            'visibility' => $request->input('visibility'),
            'current_holder_id' => $request->user()->id,
        ]);

        try {
            $publisher->publish($document);
        } catch (\Throwable $e) {
            $document->update(['status' => 'failed', 'error' => 'Could not queue for processing. Try re-uploading.']);
            report($e);
            return response()->json(['message' => 'Upload stored, but processing could not be queued.'], 500); 
        }
        return response()->json(['id' => $document->id], 201);
    }

    public function destroy(Request $request, Document $document): RedirectResponse
    {
        abort_unless(
            $document->user_id === $request->user()->id || $document->visibility === 'public',
            403,
        );

        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        if ($document->type === 'general') {
            app(\App\Services\DocumentIndexer::class)->delete($document);
        }

        Storage::disk('s3')->delete($document->storage_path);
        $document->delete();

        return back();
    }

    public function search(Request $request, DocumentIndexer $indexer): Response
    {
        $query = trim($request->string('query')->toString());
        
        $results = $query === ''
            ? ['total' => 0, 'hits' => []]
            : $indexer->search($request->user()->id, $query);
        return Inertia::render('documents/Search', [
            'query' => $query,
            'results' => $results,
        ]);
    }
    
    public function view(Request $request, Document $document): StreamedResponse
    {
        abort_unless(
            $document->user_id === $request->user()->id || $document->visibility === 'public',
            403,
        );
        
        activity()
            ->performedOn($document)
            ->causedBy($request->user())
            ->withProperties(['filename' => $document->original_filename])
            ->log('viewed');

        return Storage::disk('s3')->response(
            $document->storage_path,
            $document->original_filename,
            ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . addslashes($document->original_filename) . '"'],
        );
    }

    public function retry(Request $request, Document $document): RedirectResponse
    {
        abort_unless($document->user_id === $request->user()->id, 403);
        abort_unless($document->status === 'failed', 422, 'Only failed documents can be retried.');

        $document->update([
            'status' => 'pending',
            'error' => null,
            'progress_page' => null,
            'progress_total' => null,
        ]);

        activity()
            ->performedOn($document)
            ->causedBy($request->user())
            ->withProperties(['filename' => $document->original_filename])
            ->log('retried');

        try {
            app(OcrPublisher::class)->publish($document);
        } catch (\Throwable $e) {
            $document->update(['status' => 'failed', 'error' => 'Could not queue for processing. Try again.']);
            report($e);
        }

        return back();
    }

    public function routing(Request $request, Document $document): Response
    {
        $userId = $request->user()->id;

        $hasBeenInvolved = $document->routingCase->routes()
            ->where(fn ($q) => $q->where('from_user_id', $userId)->orWhere('to_user_id', $userId))
            ->exists();

        abort_unless(
            $document->user_id === $userId              // uploaded it
                || $document->visibility === 'public'     // publicly visible
                || $document->current_holder_id === $userId  // currently holds it
                || $hasBeenInvolved,                          // was ever a sender/recipient in its routing
            403,
        );

        $latestRoute = $document->routingCase->routes()->latest()->first();
        // dd($document->current_holder_id);
        $isCurrentHolder = $document->current_holder_id === $request->user()->id;
        $hasUnreceivedIncoming = $latestRoute
        && $latestRoute->to_user_id === $request->user()->id
        && $latestRoute->received_at === null;

        return Inertia::render('documents/Routing', [
            'document' => [
                'id' => $document->id,
                'original_filename' => $document->original_filename,
                'tracking_status' => $document->tracking_status,
                'current_holder' => $document->currentHolder?->name,
                'current_holder_id' => $document->current_holder_id,
                'current_org_unit' => $document->currentHolder?->orgUnit?->name,
            ],
            'can_receive' => $isCurrentHolder && $hasUnreceivedIncoming,
            'can_forward' => $isCurrentHolder && ! $hasUnreceivedIncoming,
            'is_current_holder' => $isCurrentHolder,
            'is_focal' => $request->user()->hasRole('document_focal'),
            'colleagues' => $isCurrentHolder
                ? User::where('org_unit_id', $request->user()->org_unit_id)
                    ->where('id', '!=', $request->user()->id)
                    ->get(['id', 'name'])
                : [],
            'divisions' => $isCurrentHolder
                ? OrgUnit::where('type', 'division')
                    ->where('id', '!=', $request->user()->org_unit_id)
                    ->get(['id', 'name'])
                : [],
            'trail' => $document->routingCase->routes()
                ->with(['fromUser.orgUnit', 'toUser.orgUnit'])
                ->oldest()
                ->get()
                ->map(fn ($route) => [
                    'id' => $route->id,
                    'from' => $route->fromUser?->name ?? 'Uploaded',
                    'from_division' => $route->fromUser?->orgUnit?->name,
                    'to' => $route->toUser->name,
                    'to_division' => $route->toUser->orgUnit?->name,
                    'action' => $route->action,
                    'remarks' => $route->remarks,
                    'sent_at' => $route->created_at->format('M j, Y H:i'),
                    'received_at' => $route->received_at?->format('M j, Y H:i'),
                ]),
        ]);
    }


    public function raw(Request $request, Document $document): StreamedResponse
    {
        abort_unless($document->isAccessibleBy($request->user()), 403);

        $path = $document->currentVersion?->storage_path ?? $document->storage_path;
    
        return Storage::disk('s3')->response(
            $path,
            $document->original_filename,
            ['Content-Type' => 'application/pdf'],
        );
    }


    public function applySignature(Request $request, Document $document): RedirectResponse | JsonResponse
    {
        abort_unless($document->isAccessibleBy($request->user()), 403);

        $data = $request->validate([
            'signature' => ['required', 'image', 'max:2048'],
            'page' => ['required', 'integer', 'min:1'],
            'x' => ['required', 'numeric'],
            'y' => ['required', 'numeric'],
            'width' => ['required', 'numeric'],
            'height' => ['required', 'numeric'],
            'render_scale' => ['required', 'numeric'],
        ]);

        $sourcePath = $document->currentVersion?->storage_path ?? $document->storage_path;
        $currentPdf = Storage::disk('s3')->get($sourcePath);

        $response = Http::attach('pdf', $currentPdf, 'document.pdf')
            ->attach('signature', file_get_contents($data['signature']->getRealPath()), 'signature.png')
            ->timeout(60)
            ->post(config('services.ocr.host') . '/sign', [
                'page' => $data['page'],
                'x' => $data['x'],
                'y' => $data['y'],
                'width' => $data['width'],
                'height' => $data['height'],
                'render_scale' => $data['render_scale'],
            ]);

        $response->throw();

        $nextVersionNumber = $document->versions()->max('version_number') + 1;
        $newPath = "signed/{$document->id}/v{$nextVersionNumber}.pdf";

        Storage::disk('s3')->put($newPath, $response->body());

        $newVersion = $document->versions()->create([
            'created_by' => $request->user()->id,
            'storage_path' => $newPath,
            'version_number' => $nextVersionNumber,
            'label' => "Signed by {$request->user()->name}",
        ]);

        $document->update(['current_version_id' => $newVersion->id]);

        if ($document->routing_case_id) {
            $document->routingCase->update(['tracking_status' => 'signed']);
        }

        activity()->performedOn($document)->causedBy($request->user())
                    ->withProperties(['filename' => $document->original_filename, 'version' => $nextVersionNumber])
                    ->log('signed');
                $redirectUrl = $document->routing_case_id
            ? "/routing/{$document->routing_case_id}"
            : "/documents/{$document->id}";

        if ($request->wantsJson()) {
            return response()->json(['redirect' => $redirectUrl]);
        }

        return redirect($redirectUrl);
    }

   public function showSignPage(Request $request, Document $document): Response
    {
        abort_unless($document->isAccessibleBy($request->user()), 403);

        return Inertia::render('documents/Sign', [
            'document' => [
                'id' => $document->id,
                'original_filename' => $document->original_filename,
            ],
        ]);
    }
}