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

    /**
     * Get the events logged for this device.
     */
    public function events()
    {
        return $this->hasMany(DeviceEvent::class, 'device_id');
    }

    /**
     * Get the MDM commands for this device.
     */
    public function mdmCommands()
    {
        return $this->hasMany(MdmCommand::class, 'device_id');
    }

    /**
     * Groups this device explicitly belongs to.
     */
    public function groups()
    {
        return $this->belongsToMany(DeviceGroup::class, 'device_group_memberships', 'device_id', 'group_id')
            ->withTimestamps();
    }

    /**
     * Free-form tags applied to this device.
     */
    public function tags()
    {
        return $this->hasMany(DeviceTag::class, 'device_id');
    }

    /**
     * Telemetry snapshots reported by this device.
     */
    public function telemetry()
    {
        return $this->hasMany(DeviceTelemetry::class, 'device_id');
    }
}
