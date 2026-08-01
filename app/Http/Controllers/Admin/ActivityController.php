<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class ActivityController extends Controller
{
    public function index(Request $request): Response
{
    $activities = Activity::with(['causer', 'subject'])
            ->when(!$request->user()->hasRole('admin'), function ($query) use ($request) {
                $documentIds = $request->user()->documents()->pluck('id');
                $query->where('subject_type', \App\Models\Document::class)
                    ->whereIn('subject_id', $documentIds);
            })
            ->latest()
            ->paginate(25);

        return Inertia::render('admin/Activity', [
            'activities' => $activities->through(fn (Activity $a) => [
                'id' => $a->id,
                'description' => $a->description,
                'user' => $a->causer?->name ?? 'System',
                'document' => $a->properties['filename']
                    ?? $a->subject?->original_filename
                    ?? '(unknown)',
                'changes' => $a->attribute_changes?->toArray() ?? [],
                'when' => $a->created_at->format('M j, Y H:i:s'),
            ]),
        ]);
    }
}