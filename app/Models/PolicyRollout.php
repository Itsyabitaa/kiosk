<?php

namespace App\Models;

use App\Models\Traits\HasOrgScope;
use Illuminate\Database\Eloquent\Model;

class PolicyRollout extends Model
{
    use HasOrgScope;

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ROLLED_BACK = 'rolled_back';

    protected $fillable = [
        'org_id',
        'policy_id',
        'group_id',
        'rollout_percentage',
        'scheduled_at',
        'status',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'rollout_percentage' => 'integer',
    ];

    public function policy()
    {
        return $this->belongsTo(Policy::class, 'policy_id');
    }

    public function group()
    {
        return $this->belongsTo(DeviceGroup::class, 'group_id');
    }

    public function assignments()
    {
        return $this->hasMany(PolicyAssignment::class, 'rollout_id');
    }
}
