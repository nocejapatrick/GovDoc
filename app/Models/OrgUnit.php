<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrgUnit extends Model
{
    protected $fillable = ['name', 'type', 'parent_id'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(OrgUnit::class, 'parent_id');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(Position::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * The single user in this division responsible for receiving
     * and forwarding inter-division documents.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    public function documentFocal(): User
    {
        $candidates = User::role('document_focal')
            ->where('org_unit_id', $this->id)
            ->get();

        abort_if($candidates->isEmpty(), 422, "{$this->name} has no Document Focal assigned.");
        abort_if($candidates->count() > 1, 422, "{$this->name} has multiple Document Focals — ask an admin to resolve this.");

        return $candidates->first();
    }
}