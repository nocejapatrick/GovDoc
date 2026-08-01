<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Contracts\Activity;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'tracking_status'
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

    public function routes(): HasMany
    {
        return $this->hasMany(DocumentRoute::class)->latest();
    }
}