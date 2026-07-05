<?php

namespace App\Models;

use App\Models\Traits\HasOrgScope;
use Illuminate\Database\Eloquent\Model;

class Alert extends Model
{
    use HasOrgScope;

    public const TYPE_TAMPER = 'tamper';
    public const TYPE_OFFLINE = 'offline';
    public const TYPE_TELEMETRY_STOPPED = 'telemetry_stopped';

    public const STATUS_OPEN = 'open';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'org_id',
        'device_id',
        'type',
        'severity',
        'message',
        'details',
        'status',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id');
    }
}
