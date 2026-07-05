<?php

namespace App\Models;

use App\Models\Traits\HasOrgScope;
use Illuminate\Database\Eloquent\Model;

class Policy extends Model
{
    use HasOrgScope;

    protected $fillable = [
        'org_id',
        'name',
        'group_id',
        'policy_type',
        'target',
        'restrictions',
        'version',
        'status',
    ];

    protected $casts = [
        'restrictions' => 'array',
        'version' => 'integer',
    ];

    /**
     * Get the group (original version) of the policy.
     */
    public function group()
    {
        return $this->belongsTo(Policy::class, 'group_id');
    }

    /**
     * Get all versions belonging to this policy group.
     */
    public function versions()
    {
        return $this->hasMany(Policy::class, 'group_id', 'group_id');
    }
}
