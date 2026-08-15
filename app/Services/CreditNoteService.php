<?php

namespace App\Services;

use App\Http\Requests\StoreCreditNoteRequest;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Services\Sunat\SunatGatewayManager;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CreditNoteService
{
    public function __construct(
        private readonly IgvCalculator $calculator,
        private readonly InvoiceSequenceService $sequences,
        private readonly SunatGatewayManager $gateways,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function issue(Invoice $invoice, array $payload): CreditNote
    {
        $invoice->loadMissing(['company', 'draft.items']);

        if ($invoice->status !== 'accepted') {
            throw ValidationException::withMessages([
                'invoice' => 'Solo se puede emitir una nota de crédito sobre una factura aceptada por SUNAT.',
            ]);
        }

        if ($payload['issue_date'] < $invoice->draft->issue_date->format('Y-m-d')) {
            throw ValidationException::withMessages([
                'issue_date' => 'La nota no puede tener una fecha anterior a la factura afectada.',
            ]);
        }

        $isFull = in_array($payload['reason_code'], StoreCreditNoteRequest::FULL_REASON_CODES, true);
        $requestedItems = $isFull
            ? $invoice->draft->items->map(fn ($item) => [
                'invoice_draft_item_id' => $item->id,
                'quantity' => $item->quantity,
                'unit_price' => $item->entered_unit_price,
            ])->all()
            : ($payload['items'] ?? []);

        $creditNote = DB::transaction(function () use ($invoice, $payload, $requestedItems, $isFull): CreditNote {
            $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $activeNotes = $lockedInvoice->creditNotes()
                ->whereIn('status', ['processing', 'accepted'])
                ->with('items')
                ->get();

            if ($isFull && $activeNotes->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'reason_code' => 'La factura ya tiene una nota activa; no puede anularse o devolverse por completo.',
                ]);
            }

            $sourceItems = $invoice->draft->items->keyBy('id');
            $creditedQuantities = $activeNotes->flatMap->items
                ->groupBy('invoice_draft_item_id')
                ->map(fn ($items) => $items->reduce(
                    fn (BigDecimal $sum, $item) => $sum->plus((string) $item->quantity),
                    BigDecimal::zero(),
                ));
            $calculationInput = [];
            $normalizedItems = [];

            foreach ($requestedItems as $requested) {
                $source = $sourceItems->get((int) $requested['invoice_draft_item_id']);

                if (! $source) {
                    throw ValidationException::withMessages([
                        'items' => 'Uno de los conceptos no pertenece a la factura afectada.',
                    ]);
                }

                $quantity = BigDecimal::of((string) $requested['quantity']);
                $credited = $creditedQuantities->get($source->id, BigDecimal::zero());
                $available = BigDecimal::of((string) $source->quantity)->minus($credited);

                if ($quantity->isGreaterThan($available)) {
                    throw ValidationException::withMessages([
                        'items' => 'La cantidad indicada para "'.$source->description.'" supera la cantidad disponible ('.$available.').',
                    ]);
                }

                if (BigDecimal::of((string) $requested['unit_price'])->isGreaterThan(BigDecimal::of((string) $source->entered_unit_price))) {
                    throw ValidationException::withMessages([
                        'items' => 'El precio acreditado de "'.$source->description.'" no puede superar su precio original.',
                    ]);
                }

                $calculationInput[] = [
                    'quantity' => (string) $requested['quantity'],
                    'unit_price' => (string) $requested['unit_price'],
                ];
                $normalizedItems[] = ['source' => $source];
            }

            $document = $this->calculator->calculateDocument($calculationInput, $invoice->draft->tax_mode);
            $alreadyCredited = $activeNotes->reduce(
                fn (BigDecimal $sum, CreditNote $note) => $sum->plus((string) $note->total),
                BigDecimal::zero(),
            );
            $availableTotal = BigDecimal::of((string) $invoice->draft->total)->minus($alreadyCredited);

            if (BigDecimal::of($document['total'])->isGreaterThan($availableTotal)) {
                throw ValidationException::withMessages([
                    'items' => 'El importe de la nota supera el saldo disponible de la factura ('.$availableTotal.').',
                ]);
            }

            $series = $invoice->company->default_credit_note_series;
            $creditNote = CreditNote::create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
                'series' => $series,
                'correlative' => $this->sequences->next($invoice->company, $series),
                'issue_date' => $payload['issue_date'],
                'reason_code' => $payload['reason_code'],
                'reason_description' => trim($payload['reason_description']),
                'currency' => $invoice->draft->currency,
                'subtotal' => $document['subtotal'],
                'igv' => $document['igv'],
                'total' => $document['total'],
                'status' => 'processing',
            ]);

            foreach ($normalizedItems as $index => $normalized) {
                $creditNote->items()->create([
                    'invoice_draft_item_id' => $normalized['source']->id,
                    'position' => $index + 1,
                    'description' => $normalized['source']->description,
                    ...$document['items'][$index],
                ]);
            }

            return $creditNote;
        });

        try {
            $result = $this->gateways->for($invoice->company)->issueCreditNote($creditNote);
            $creditNote->update([
                'status' => $result['status'],
                'sunat_code' => $result['code'],
                'sunat_message' => $result['message'],
                'sunat_notes' => $result['notes'],
                'xml_path' => $result['xml_path'],
                'cdr_path' => $result['cdr_path'],
                'issued_at' => $result['status'] === 'accepted' ? now() : null,
            ]);
        } catch (Throwable $exception) {
            report($exception);
            $creditNote->update([
                'status' => 'error',
                'sunat_code' => 'APPLICATION_ERROR',
                'sunat_message' => $exception->getMessage(),
            ]);
        }

        return $creditNote->fresh(['invoice', 'items']);
    }
}
