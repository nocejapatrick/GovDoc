<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoutingCase extends Model
{
    protected $fillable = ['user_id', 'title', 'current_holder_id', 'tracking_status'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function currentHolder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_holder_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function routes(): HasMany
    {
        return $this->hasMany(DocumentRoute::class)->latest();
    }
}