<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Despatch extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $hidden = ['xml_path', 'cdr_path'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'fiscal_revision' => 'integer', 'gre_revision' => 'integer', 'correlative' => 'integer'];
    }
}
