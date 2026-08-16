<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\OrgUnit;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DocumentRouteController extends Controller
{
    public function receive(Request $request, Document $document): RedirectResponse
    {
        abort_unless($document->current_holder_id === $request->user()->id, 403);

        $pendingRoute = $document->routingCase->routes()
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
        $userId = $request->user()->id;

        $documents = Document::where(function ($query) use ($userId) {
                $query->where('current_holder_id', $userId)   // it's with me right now
                    ->orWhereHas('routes', function ($q) use ($userId) {
                        $q->where('from_user_id', $userId)   // I sent it at some point
                            ->orWhere('to_user_id', $userId);   // or I received it at some point
                    });
            })
        ->whereHas('routes') 
        ->with(['currentHolder.orgUnit', 'routes' => function ($query) {
            $query->latest();
        }])
        ->latest()
        ->paginate(15);


        return \Inertia\Inertia::render('documents/Inbox', [
            'documents' => $documents->through(fn (Document $doc) => [
                'id' => $doc->id,
                'original_filename' => $doc->original_filename,
                'tracking_status' => $doc->tracking_status,
                'from' => $doc->routes->first()?->fromUser?->name ?? '—',
                'received_at' => $doc->routes->first()?->received_at?->format('M j, Y H:i'),
                'current_holder_id' => $doc->current_holder_id,
                'is_mine_to_act_on' => $doc->current_holder_id === $userId,
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