<?php

namespace App\Models\Traits;

use App\Models\Scopes\OrgScope;

trait HasOrgScope
{
    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new OrgScope);

        static::creating(function ($model) {
            if (empty($model->org_id) && auth()->check()) {
                $model->org_id = auth()->user()->org_id;
            }
        });
    }
}
