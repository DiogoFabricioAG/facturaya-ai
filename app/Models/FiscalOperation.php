<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalOperation extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sunat_notes' => 'array',
            'totals_json' => 'array',
            'payload_json' => 'array',
            'confirmation_json' => 'array',
            'reconciliation_evidence' => 'array',
            'issue_date' => 'date:Y-m-d',
            'issued_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function preview(): BelongsTo
    {
        return $this->belongsTo(FiscalPreview::class, 'preview_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    public function getFullNumberAttribute(): ?string
    {
        if (! $this->series || $this->correlative === null) {
            return null;
        }

        return $this->series.'-'.str_pad((string) $this->correlative, 8, '0', STR_PAD_LEFT);
    }
}
