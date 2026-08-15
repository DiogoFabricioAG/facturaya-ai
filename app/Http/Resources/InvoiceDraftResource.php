<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceDraftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'company' => $this->whenLoaded('company', fn () => [
                'id' => $this->company->id,
                'ruc' => $this->company->ruc,
                'legal_name' => $this->company->legal_name,
            ]),
            'customer' => [
                'ruc' => $this->customer_ruc,
                'name' => $this->customer_name,
            ],
            'issue_date' => $this->issue_date?->format('Y-m-d'),
            'tax_mode' => $this->tax_mode,
            'currency' => $this->currency,
            'source' => [
                'type' => match ($this->mime_type) {
                    'text/plain' => 'text',
                    'application/vnd.facturaya.source-bundle+json' => 'mixed',
                    default => 'file',
                },
                'name' => $this->original_name,
                'mime_type' => $this->mime_type,
                'extractor' => $this->ai_driver,
            ],
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'position' => $item->position,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => $item->entered_unit_price,
                'unit_value' => $item->unit_value,
                'unit_price_with_igv' => $item->unit_price_with_igv,
                'line_base' => $item->line_base,
                'igv' => $item->igv,
                'line_total' => $item->line_total,
                'confidence' => $item->confidence,
                'source_page' => $item->source_page,
            ])->values()),
            'totals' => [
                'subtotal' => $this->subtotal,
                'igv' => $this->igv,
                'total' => $this->total,
            ],
            'warnings' => $this->warnings ?? [],
            'error' => $this->error_message,
            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoice ? new InvoiceResource($this->invoice) : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
