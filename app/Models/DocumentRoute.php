<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentRoute extends Model
{
    protected $fillable = [
        'document_id', 'from_user_id', 'to_user_id',
        'to_org_unit_id', 'action', 'remarks', 'received_at',
    ];

    protected $casts = [
        'received_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function toOrgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class, 'to_org_unit_id');
    }
    public function routingCase(): BelongsTo
    {
        return $this->belongsTo(RoutingCase::class);
    }
}