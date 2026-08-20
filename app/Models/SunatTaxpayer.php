<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class SunatTaxpayer extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'ruc';

    protected $keyType = 'string';

    protected $guarded = [];
}
