<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'number' => $this->number,
            'status' => $this->status,
            'sunat' => [
                'code' => $this->sunat_code,
                'message' => $this->sunat_message,
                'notes' => $this->sunat_notes ?? [],
            ],
            'files' => [
                'pdf' => $this->status === 'accepted' ? route('api.invoices.file', [$this->id, 'pdf']) : null,
                'xml' => $this->xml_path ? route('api.invoices.file', [$this->id, 'xml']) : null,
                'cdr' => $this->cdr_path ? route('api.invoices.file', [$this->id, 'cdr']) : null,
            ],
            'credit_notes' => CreditNoteResource::collection($this->whenLoaded('creditNotes')),
            'issued_at' => $this->issued_at?->toIso8601String(),
        ];
    }
}
