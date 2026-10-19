<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyProfileBinding extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'config_version' => 'integer',
            'bound_profile_version' => 'integer',
            'bound_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
