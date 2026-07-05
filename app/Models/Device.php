<?php

namespace App\Models;

use App\Models\Traits\HasOrgScope;
use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    use HasOrgScope;

    protected $fillable = [
        'org_id',
        'device_uid',
        'hardware_fingerprint',
        'platform',
        'enrollment_status',
        'last_seen_at',
    ];

    /**
     * Get the policy assignment for this device.
     */
    public function policyAssignment()
    {
        return $this->hasOne(PolicyAssignment::class, 'device_id');
    }

    /**
     * Get the assigned policy version for this device.
     */
    public function assignedPolicy()
    {
        return $this->hasOneThrough(
            Policy::class,
            PolicyAssignment::class,
            'device_id',
            'id',
            'id',
            'policy_id'
        );
    }
}
