<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ruc' => $this->ruc,
            'legal_name' => $this->legal_name,
            'trade_name' => $this->trade_name,
            'fiscal_address' => [
                'ubigeo' => $this->ubigeo,
                'department' => $this->department,
                'province' => $this->province,
                'district' => $this->district,
                'address' => $this->address,
            ],
            'sunat_driver' => $this->sunat_driver,
            'sunat_environment' => $this->sunat_environment,
            'sunat_credentials_configured' => $this->hasSunatCredentials(),
            'default_series' => $this->default_series,
            'default_credit_note_series' => $this->default_credit_note_series,
            'default_boleta_series' => $this->default_boleta_series ?: 'B001',
            'default_boleta_credit_note_series' => $this->default_boleta_credit_note_series ?: 'BC01',
            'active' => $this->active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
