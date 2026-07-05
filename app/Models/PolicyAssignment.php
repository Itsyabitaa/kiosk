<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PolicyAssignment extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'policy_id',
        'device_id',
        'assigned_at',
        'status',
    ];

    /**
     * Get the policy version associated with this assignment.
     */
    public function policy()
    {
        return $this->belongsTo(Policy::class, 'policy_id');
    }

    /**
     * Get the device associated with this assignment.
     */
    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id');
    }
}
