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
}
