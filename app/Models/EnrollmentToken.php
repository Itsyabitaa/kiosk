<?php

namespace App\Models;

use App\Models\Traits\HasOrgScope;
use Illuminate\Database\Eloquent\Model;

class EnrollmentToken extends Model
{
    use HasOrgScope;

    protected $fillable = [
        'org_id',
        'policy_id',
        'token',
        'single_use',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'single_use' => 'boolean',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    /**
     * The policy this enrollment token provisions the device to (if any).
     */
    public function policy()
    {
        return $this->belongsTo(Policy::class, 'policy_id');
    }
}
