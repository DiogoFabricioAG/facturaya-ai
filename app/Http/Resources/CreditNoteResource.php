<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CreditNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'invoice_id' => $this->invoice_id,
            'number' => $this->number,
            'environment' => $this->sunat_environment,
            'affected_document' => $this->whenLoaded('invoice', fn () => $this->invoice->number),
            'issue_date' => $this->issue_date?->format('Y-m-d'),
            'reason' => [
                'code' => $this->reason_code,
                'description' => $this->reason_description,
            ],
            'currency' => $this->currency,
            'totals' => [
                'subtotal' => $this->subtotal,
                'igv' => $this->igv,
                'total' => $this->total,
            ],
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'invoice_draft_item_id' => $item->invoice_draft_item_id,
                'position' => $item->position,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => $item->entered_unit_price,
                'line_base' => $item->line_base,
                'igv' => $item->igv,
                'line_total' => $item->line_total,
            ])->values()),
            'status' => $this->status,
            'sunat' => [
                'code' => $this->sunat_code,
                'message' => $this->sunat_message,
                'notes' => $this->sunat_notes ?? [],
            ],
            'files' => [
                'xml' => $this->xml_path ? route('api.credit-notes.file', [$this->id, 'xml']) : null,
                'cdr' => $this->cdr_path ? route('api.credit-notes.file', [$this->id, 'cdr']) : null,
            ],
            'issued_at' => $this->issued_at?->toIso8601String(),
        ];
    }
}
