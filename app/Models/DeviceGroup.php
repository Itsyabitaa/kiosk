<?php

namespace App\Models;

use App\Models\Traits\HasOrgScope;
use Illuminate\Database\Eloquent\Model;

class DeviceGroup extends Model
{
    use HasOrgScope;

    protected $fillable = [
        'org_id',
        'name',
    ];

    /**
     * Devices that are explicit members of this group.
     */
    public function devices()
    {
        return $this->belongsToMany(Device::class, 'device_group_memberships', 'group_id', 'device_id')
            ->withTimestamps();
    }

    public function memberships()
    {
        return $this->hasMany(DeviceGroupMembership::class, 'group_id');
    }
}
