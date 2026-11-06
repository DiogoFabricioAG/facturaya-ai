<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Despatch extends Model
{
    use HasUlids;

    /** Units accepted for transported goods: SUNAT catalog 03 codes (UN/ECE Rec. 20) with their usual Spanish name. */
    public const UNITS = [
        'NIU' => 'Unidad', 'KGM' => 'Kilogramo', 'MTR' => 'Metro', 'LTR' => 'Litro', 'PR' => 'Par',
        'BG' => 'Bolsa', 'BX' => 'Caja', 'PK' => 'Paquete', 'SET' => 'Juego', 'DZN' => 'Docena',
        'CEN' => 'Ciento', 'C62' => 'Pieza', 'GLL' => 'Galón', 'MTK' => 'Metro cuadrado',
        'MTQ' => 'Metro cúbico', 'TU' => 'Tubo', 'BJ' => 'Balde', 'TNE' => 'Tonelada',
    ];

    protected $guarded = [];

    protected $hidden = ['xml_path', 'cdr_path'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'fiscal_revision' => 'integer', 'gre_revision' => 'integer', 'correlative' => 'integer'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
