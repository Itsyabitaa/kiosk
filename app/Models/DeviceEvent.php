<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceEvent extends Model
{
    protected $fillable = [
        'device_id',
        'event_type',
        'status',
        'details',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    /**
     * Get the device associated with this event.
     */
    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id');
    }
}
