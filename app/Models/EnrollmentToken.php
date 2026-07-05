<?php

namespace App\Models;

use App\Models\Traits\HasOrgScope;
use Illuminate\Database\Eloquent\Model;

class EnrollmentToken extends Model
{
    use HasOrgScope;

    protected $fillable = [
        'org_id',
        'token',
        'expires_at',
        'used_at',
    ];
}
