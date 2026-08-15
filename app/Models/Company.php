<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Company extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $hidden = [
        'sol_user',
        'sol_password',
        'certificate_path',
    ];

    protected function casts(): array
    {
        return [
            'sol_user' => 'encrypted',
            'sol_password' => 'encrypted',
            'active' => 'boolean',
        ];
    }

    public function apiTokens(): HasMany
    {
        return $this->hasMany(CompanyApiToken::class);
    }

    public function drafts(): HasMany
    {
        return $this->hasMany(InvoiceDraft::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    public function sequences(): HasMany
    {
        return $this->hasMany(InvoiceSequence::class);
    }

    public function hasSunatCredentials(): bool
    {
        return filled($this->certificate_path)
            && Storage::disk('local')->exists($this->certificate_path)
            && filled($this->sol_user)
            && filled($this->sol_password);
    }
}
