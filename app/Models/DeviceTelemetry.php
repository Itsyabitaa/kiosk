<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceTelemetry extends Model
{
    protected $table = 'device_telemetry';

    protected $fillable = [
        'device_id',
        'battery_level',
        'connectivity_type',
        'signal_strength',
        'app_version',
        'os_version',
        'recorded_at',
    ];

    protected $casts = [
        'battery_level' => 'integer',
        'signal_strength' => 'integer',
        'recorded_at' => 'datetime',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id');
    }
}
