<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceTag extends Model
{
    protected $fillable = [
        'device_id',
        'tag',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id');
    }
}
