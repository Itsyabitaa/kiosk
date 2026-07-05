<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceGroupMembership extends Model
{
    protected $fillable = [
        'device_id',
        'group_id',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function group()
    {
        return $this->belongsTo(DeviceGroup::class, 'group_id');
    }
}
