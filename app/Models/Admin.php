<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class Admin extends Authenticatable implements JWTSubject
{
    protected $fillable = [
        'org_id',
        'email',
        'password_hash',
        'role',
    ];

    protected $hidden = [
        'password_hash',
    ];

    public function getAuthPassword()
    {
        return $this->password_hash;
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [
            'org_id' => $this->org_id,
            'role' => $this->role,
        ];
    }
}
