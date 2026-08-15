<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceDraftItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'entered_unit_price' => 'decimal:2',
            'unit_value' => 'decimal:6',
            'unit_price_with_igv' => 'decimal:6',
            'line_base' => 'decimal:2',
            'igv' => 'decimal:2',
            'line_total' => 'decimal:2',
            'confidence' => 'decimal:4',
        ];
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(InvoiceDraft::class, 'invoice_draft_id');
    }
}
