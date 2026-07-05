<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MdmCommand extends Model
{
    // Cross-platform command types pushed over the real-time channel (and pollable as fallback).
    public const TYPE_LOCK = 'lock_command';
    public const TYPE_UNLOCK = 'unlock_command';
    public const TYPE_REBOOT = 'reboot_command';
    public const TYPE_WIPE = 'wipe_command';
    public const TYPE_POLICY_UPDATE = 'policy_update';
    public const TYPE_REMOVE_PROFILE = 'RemoveProfile';

    public const REMOTE_COMMAND_TYPES = [
        self::TYPE_LOCK,
        self::TYPE_UNLOCK,
        self::TYPE_REBOOT,
        self::TYPE_WIPE,
        self::TYPE_POLICY_UPDATE,
    ];

    protected $fillable = [
        'device_id',
        'command_type',
        'status',
        'payload',
        'delivered_at',
        'acked_at',
        'rollout_id',
    ];

    protected $casts = [
        'payload' => 'array',
        'delivered_at' => 'datetime',
        'acked_at' => 'datetime',
    ];

    /**
     * Get the device associated with this command.
     */
    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id');
    }
}
