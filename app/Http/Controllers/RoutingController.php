<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\OrgUnit;
use App\Models\RoutingCase;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\JsonResponse;

class RoutingController extends Controller
{
    public function index(Request $request): Response
    {
        $userId = $request->user()->id;

        $cases = RoutingCase::where(function ($query) use ($userId) {
                $query->where('user_id', $userId)
                    ->orWhere('current_holder_id', $userId)
                    ->orWhereHas('routes', function ($q) use ($userId) {
                        $q->where('from_user_id', $userId)->orWhere('to_user_id', $userId);
                    });
            })
            ->withCount('documents')
            ->with('currentHolder')
            ->latest()
            ->paginate(15);

        return Inertia::render('routing/Index', [
            'cases' => $cases->through(fn (RoutingCase $case) => [
                'id' => $case->id,
                'title' => $case->title ?? 'Untitled routing',
                'file_count' => $case->documents_count,
                'tracking_status' => $case->tracking_status,
                'current_holder' => $case->currentHolder?->name,
                'is_mine_to_act_on' => $case->current_holder_id === $userId,
                'created_at' => $case->created_at->format('M j, Y'),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:204800'],
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $case = RoutingCase::create([
            'user_id' => $request->user()->id,
            'title' => $request->input('title'),
            'current_holder_id' => $request->user()->id,
            'tracking_status' => 'draft',
        ]);

            \Log::info('Case created', ['case_id' => $case->id]);

        foreach ($request->file('files') as $file) {
            \Log::info('Attaching file', ['case_id' => $case->id, 'filename' => $file->getClientOriginalName()]);
            $this->attachFile($case, $file, $request->user()->id);
            
        }

        activity()->performedOn($case)->causedBy($request->user())
            ->withProperties(['file_count' => count($request->file('files'))])
            ->log('routing case created');

        if ($request->wantsJson()) {
            return response()->json(['id' => $case->id], 201);
        }

        return redirect("/routing/{$case->id}");
    }

    /** Add a supporting file to a case already in transit. */
    public function addFile(Request $request, RoutingCase $case): RedirectResponse
    {
        abort_unless($case->current_holder_id === $request->user()->id, 403);

        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:204800'],
        ]);

        $document = $this->attachFile($case, $request->file('file'), $request->user()->id);

        activity()->performedOn($case)->causedBy($request->user())
            ->withProperties(['filename' => $document->original_filename])
            ->log('file added to case');

        return back();
    }

    private function attachFile(RoutingCase $case, $file, int $userId): Document
    {
        $path = $file->store('routed/' . now()->format('Y/m'), 's3');

        $document = Document::create([
            'user_id' => $userId,
            'routing_case_id' => $case->id,
            'original_filename' => $file->getClientOriginalName(),
            'storage_path' => $path,
            'size_bytes' => $file->getSize(),
            'status' => 'processed',
            'type' => 'routed',
        ]);

        $version = $document->versions()->create([
            'created_by' => $userId,
            'storage_path' => $path,
            'version_number' => 1,
            'label' => 'Original upload',
        ]);

        $document->update(['current_version_id' => $version->id]);

        return $document;
    }

    public function show(Request $request, RoutingCase $case): Response
    {
        $userId = $request->user()->id;

        $hasBeenInvolved = $case->routes()
            ->where(fn ($q) => $q->where('from_user_id', $userId)->orWhere('to_user_id', $userId))
            ->exists();

        abort_unless(
            $case->user_id === $userId
                || $case->current_holder_id === $userId
                || $hasBeenInvolved,
            403,
        );

        $latestRoute = $case->routes()->latest()->first();
        $isCurrentHolder = $case->current_holder_id === $userId;
        $hasUnreceivedIncoming = $latestRoute
            && $latestRoute->to_user_id === $userId
            && $latestRoute->received_at === null;

        return Inertia::render('routing/Show', [
            'routingCase' => [
                'id' => $case->id,
                'title' => $case->title ?? 'Untitled routing',
                'tracking_status' => $case->tracking_status,
                'current_holder' => $case->currentHolder?->name,
                'current_holder_id' => $case->current_holder_id,
                'current_org_unit' => $case->currentHolder?->orgUnit?->name,
            ],
            'documents' => $case->documents->map(fn (Document $doc) => [
                'id' => $doc->id,
                'original_filename' => $doc->original_filename,
            ]),
            'can_receive' => $isCurrentHolder && $hasUnreceivedIncoming,
            'can_forward' => $isCurrentHolder && ! $hasUnreceivedIncoming,
            'is_current_holder' => $isCurrentHolder,
            'is_focal' => $request->user()->hasRole('document_focal'),
            'colleagues' => $isCurrentHolder
                ? User::where('org_unit_id', $request->user()->org_unit_id)->where('id', '!=', $userId)->get(['id', 'name'])
                : [],
            'divisions' => $isCurrentHolder
                ? OrgUnit::where('type', 'division')->where('id', '!=', $request->user()->org_unit_id)->get(['id', 'name'])
                : [],
            'trail' => $case->routes()
                ->with(['fromUser.orgUnit', 'toUser.orgUnit'])
                ->oldest()
                ->get()
                ->map(fn ($route) => [
                    'id' => $route->id,
                    'from' => $route->fromUser?->name ?? 'Started',
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

    public function forward(Request $request, RoutingCase $case): RedirectResponse
    {
        abort_unless($case->current_holder_id === $request->user()->id, 403);

        $latestRoute = $case->routes()->latest()->first();
        $hasUnreceivedIncoming = $latestRoute
            && $latestRoute->to_user_id === $request->user()->id
            && $latestRoute->received_at === null;

        abort_if($hasUnreceivedIncoming, 422, 'You must mark this case as received before forwarding it.');

        $data = $request->validate([
            'scope' => ['required', 'in:within_division,cross_division'],
            'to_user_id' => ['required_if:scope,within_division', 'nullable', 'exists:users,id'],
            'to_org_unit_id' => ['required_if:scope,cross_division', 'nullable', 'exists:org_units,id'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($data['scope'] === 'cross_division') {
            abort_unless($request->user()->hasRole('document_focal'), 403, 'Only your division\'s Document Focal can send to another division.');

            $orgUnit = OrgUnit::findOrFail($data['to_org_unit_id']);
            $recipient = $orgUnit->documentFocal();
            $action = 'forwarded_to_focal';
        } else {
            $recipient = User::findOrFail($data['to_user_id']);
            abort_unless($recipient->org_unit_id === $request->user()->org_unit_id, 422, 'Use cross-division routing to send outside your division.');
            $action = 'forwarded';
        }

        $case->routes()->create([
            'from_user_id' => $request->user()->id,
            'to_user_id' => $recipient->id,
            'to_org_unit_id' => $recipient->org_unit_id,
            'action' => $action,
            'remarks' => $data['remarks'] ?? null,
        ]);

        $case->update([
            'current_holder_id' => $recipient->id,
            'tracking_status' => 'routed',
        ]);

        activity()->performedOn($case)->causedBy($request->user())
            ->withProperties(['to' => $recipient->name])
            ->log($action === 'forwarded_to_focal' ? 'forwarded to focal' : 'forwarded');

        return back();
    }

    public function receive(Request $request, RoutingCase $case): RedirectResponse
    {
        abort_unless($case->current_holder_id === $request->user()->id, 403);

        $case->update(['tracking_status' => 'received']);

        $case->routes()
            ->where('to_user_id', $request->user()->id)
            ->whereNull('received_at')
            ->latest()
            ->first()
            ?->update(['received_at' => now()]);

        activity()->performedOn($case)->causedBy($request->user())->log('received');

        return back();
    }
}