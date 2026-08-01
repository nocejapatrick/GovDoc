<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\OrgUnit;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DocumentRouteController extends Controller
{
    public function forward(Request $request, Document $document): RedirectResponse
    {
        abort_unless($document->current_holder_id === $request->user()->id, 403);

        $data = $request->validate([
            'scope' => ['required', 'in:within_division,cross_division'],
            'to_user_id' => ['required_if:scope,within_division', 'nullable', 'exists:users,id'],
            'to_org_unit_id' => ['required_if:scope,cross_division', 'nullable', 'exists:org_units,id'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($data['scope'] === 'cross_division') {
            $orgUnit = OrgUnit::findOrFail($data['to_org_unit_id']);
            $recipient = $orgUnit->documentFocal(); // throws a clear 422 if none/ambiguous
            $action = 'forwarded_to_focal';
        } else {
            $recipient = User::findOrFail($data['to_user_id']);

            abort_unless(
                $recipient->org_unit_id === $request->user()->org_unit_id,
                422,
                'Use cross-division routing to send outside your division.',
            );

            $action = 'forwarded';
        }

        $document->routes()->create([
            'from_user_id' => $request->user()->id,
            'to_user_id' => $recipient->id,
            'to_org_unit_id' => $recipient->org_unit_id,
            'action' => $action,
            'remarks' => $data['remarks'] ?? null,
        ]);

        $document->update([
            'current_holder_id' => $recipient->id,
            'tracking_status' => 'routed',
        ]);

        activity()->performedOn($document)->causedBy($request->user())
            ->withProperties(['filename' => $document->original_filename, 'to' => $recipient->name])
            ->log($action === 'forwarded_to_focal' ? 'forwarded to focal' : 'forwarded');

        return back();
    }

    public function receive(Request $request, Document $document): RedirectResponse
    {
        abort_unless($document->current_holder_id === $request->user()->id, 403);

        $pendingRoute = $document->routes()
            ->where('to_user_id', $request->user()->id)
            ->whereNull('received_at')
            ->latest()
            ->first();

        abort_unless($pendingRoute, 422, 'This document has not been forwarded to you — nothing to receive.');

        $pendingRoute->update(['received_at' => now()]);
        $document->update(['tracking_status' => 'received']);

        activity()->performedOn($document)->causedBy($request->user())
            ->withProperties(['filename' => $document->original_filename])
            ->log('received');

        return back();
    }

    /** Documents currently sitting with the logged-in user, awaiting action. */
    public function inbox(Request $request)
    {
        $documents = Document::where('current_holder_id', $request->user()->id)
                ->where('tracking_status', '!=', 'draft')   // only things actually routed to you
                ->with('routes.fromUser')
                ->latest()
                ->paginate(15);

        return \Inertia\Inertia::render('documents/Inbox', [
            'documents' => $documents->through(fn (Document $doc) => [
                'id' => $doc->id,
                'original_filename' => $doc->original_filename,
                'tracking_status' => $doc->tracking_status,
                'from' => $doc->routes->first()?->fromUser?->name ?? '—',
                'received_at' => $doc->routes->first()?->received_at?->format('M j, Y H:i'),
            ]),
        ]);
    }

    public function routingOptions(Request $request)
    {
        return response()->json([
            'colleagues' => User::where('org_unit_id', $request->user()->org_unit_id)
                ->where('id', '!=', $request->user()->id)
                ->get(['id', 'name']),
            'divisions' => OrgUnit::where('type', 'division')
                ->where('id', '!=', $request->user()->org_unit_id)
                ->get(['id', 'name']),
        ]);
    }
}