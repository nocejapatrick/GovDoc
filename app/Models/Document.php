<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Contracts\Activity;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\DocumentVersion;


class Document extends Model
{
    use LogsActivity;

    protected $fillable = [
        'user_id',
        'original_filename',
        'storage_path',
        'size_bytes',
        'status',
        'extraction_method',
        'page_count',
        'extracted_text',
        'error',
        'progress_page',
        'progress_total',
        'visibility',
        'current_holder_id', 
        'tracking_status',
        'type',
        'routing_case_id',
        'current_version_id',
        'include_in_llm',
        'llm_status',
    ];

    protected $casts = [
        'include_in_llm' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['original_filename', 'status', 'visibility'])
            ->dontLogEmptyChanges();
    }

    public function beforeActivityLogged(Activity $activity, string $eventName): void
    {
        $activity->properties = $activity->properties->put('filename', $this->original_filename);
    }
    public function currentHolder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_holder_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderBy('version_number');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class)->orderBy('chunk_index');
    }
    public function routingCase(): BelongsTo
    {
        return $this->belongsTo(RoutingCase::class);
    }
    public function isAccessibleBy(User $user): bool
    {
        if ($this->user_id === $user->id || $this->visibility === 'public') {
            return true;
        }

        if ($this->routing_case_id) {
            return $this->routingCase->current_holder_id === $user->id
                || $this->routingCase->routes()
                    ->where(fn ($q) => $q->where('from_user_id', $user->id)->orWhere('to_user_id', $user->id))
                    ->exists();
        }

        return false; // no case, not owner, not public — no access
    }
}