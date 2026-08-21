<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SunatTaxpayer extends Model
{
    protected $primaryKey = 'ruc';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
        ];
    }
}
