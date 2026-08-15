<?php

namespace App\Services;

use App\Models\InvoiceDraft;
use Illuminate\Support\Facades\DB;

final class InvoiceDraftCalculator
{
    public function __construct(private readonly IgvCalculator $calculator) {}

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function replaceItems(InvoiceDraft $draft, array $items, string $taxMode): InvoiceDraft
    {
        $normalized = array_map(static fn (array $item): array => [
            'quantity' => $item['quantity'],
            'unit_price' => $item['unit_price'] ?? $item['entered_unit_price'],
        ], $items);

        $document = $this->calculator->calculateDocument($normalized, $taxMode);

        DB::transaction(function () use ($draft, $items, $taxMode, $document): void {
            $draft->items()->delete();

            foreach ($items as $index => $item) {
                $calculated = $document['items'][$index];

                $draft->items()->create([
                    'position' => $index + 1,
                    'description' => trim((string) $item['description']),
                    ...$calculated,
                    'confidence' => $item['confidence'] ?? null,
                    'source_page' => $item['source_page'] ?? null,
                ]);
            }

            $draft->update([
                'tax_mode' => $taxMode,
                'subtotal' => $document['subtotal'],
                'igv' => $document['igv'],
                'total' => $document['total'],
                'status' => 'review_required',
                'error_message' => null,
            ]);
        });

        return $draft->fresh(['items', 'invoice']);
    }
}
