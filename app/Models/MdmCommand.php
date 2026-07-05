<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MdmCommand extends Model
{
    protected $fillable = [
        'device_id',
        'command_type',
        'status',
        'payload',
    ];

    /**
     * Get the device associated with this command.
     */
    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id');
    }
}
