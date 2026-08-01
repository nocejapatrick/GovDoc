<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Position extends Model
{
    protected $fillable = ['title', 'org_unit_id', 'rank'];

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }
}