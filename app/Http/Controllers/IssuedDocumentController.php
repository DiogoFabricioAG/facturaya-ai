<?php

namespace App\Http\Controllers;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Services\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Read-only views of what a company has issued, for the MeowLab portal (ADR-0006, phase 3).
 * Only the company's current SUNAT environment is shown, so beta tests never mix with
 * production documents.
 */
class IssuedDocumentController extends Controller
{
    private const PER_PAGE = 25;

    /** Invoices, receipts and credit notes in one list, newest first. */
    public function index(Request $request, CompanyContext $context): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'type' => ['nullable', 'in:01,03,07'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            // Stock Cat (MeowLab ADR-0014): the products of each document, to move stock.
            'with_items' => ['nullable', 'boolean'],
        ]);
        $company = $context->company();
        $withItems = (bool) ($filters['with_items'] ?? false);
        $type = $filters['type'] ?? null;
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        $rows = collect();
        if ($type !== '07') {
            $invoices = Invoice::query()
                ->where('company_id', $company->id)
                ->where('sunat_environment', $company->sunat_environment)
                ->when($type, fn ($query) => $query->where('document_type', $type))
                ->whereHas('draft', function ($query) use ($from, $to): void {
                    $query->when($from, fn ($q) => $q->whereDate('issue_date', '>=', $from))
                        ->when($to, fn ($q) => $q->whereDate('issue_date', '<=', $to));
                })
                ->with($withItems ? ['draft.items'] : ['draft'])
                ->latest()
                ->limit(1000)
                ->get();
            $rows = $rows->concat($invoices->map(fn (Invoice $invoice): array => $this->presentInvoice($invoice, $withItems)));
        }
        if ($type === null || $type === '07') {
            $notes = CreditNote::query()
                ->where('company_id', $company->id)
                ->where('sunat_environment', $company->sunat_environment)
                ->when($from, fn ($q) => $q->whereDate('issue_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('issue_date', '<=', $to))
                ->with($withItems ? ['invoice.draft', 'items'] : ['invoice.draft'])
                ->latest()
                ->limit(1000)
                ->get();
            $rows = $rows->concat($notes->map(fn (CreditNote $note): array => $this->presentCreditNote($note, $withItems)));
        }

        $sorted = $rows->sortBy([['issue_date', 'desc'], ['created_at', 'desc']])->values();
        $page = (int) ($filters['page'] ?? 1);

        return response()->json([
            'data' => $sorted->forPage($page, self::PER_PAGE)->values()->map(fn (array $row): array => collect($row)->except('created_at')->all()),
            'meta' => [
                'page' => $page,
                'per_page' => self::PER_PAGE,
                'total' => $sorted->count(),
                'environment' => $company->sunat_environment,
            ],
        ]);
    }

    /**
     * Accepted sales per month of a year: receipts and invoices add, accepted credit notes
     * subtract. Amounts are strings with two decimals, as stored.
     */
    public function monthly(Request $request, CompanyContext $context): JsonResponse
    {
        $year = (int) $request->validate(['year' => ['required', 'integer', 'min:2020', 'max:2100']])['year'];
        $company = $context->company();

        $months = collect(range(1, 12))->mapWithKeys(fn (int $month): array => [$month => [
            'month' => sprintf('%04d-%02d', $year, $month),
            'documents' => 0, 'credit_notes' => 0, 'subtotal' => 0.0, 'igv' => 0.0, 'total' => 0.0,
        ]]);

        $invoices = Invoice::query()
            ->where('company_id', $company->id)
            ->where('sunat_environment', $company->sunat_environment)
            ->where('status', 'accepted')
            ->whereHas('draft', fn ($query) => $query->whereYear('issue_date', $year))
            ->with('draft:id,issue_date,subtotal,igv,total')
            ->get();
        foreach ($invoices as $invoice) {
            $month = (int) $invoice->draft->issue_date->format('n');
            $row = $months[$month];
            $row['documents']++;
            $row['subtotal'] += (float) $invoice->draft->subtotal;
            $row['igv'] += (float) $invoice->draft->igv;
            $row['total'] += (float) $invoice->draft->total;
            $months[$month] = $row;
        }

        $notes = CreditNote::query()
            ->where('company_id', $company->id)
            ->where('sunat_environment', $company->sunat_environment)
            ->where('status', 'accepted')
            ->whereYear('issue_date', $year)
            ->get(['issue_date', 'subtotal', 'igv', 'total']);
        foreach ($notes as $note) {
            $month = (int) $note->issue_date->format('n');
            $row = $months[$month];
            $row['credit_notes']++;
            $row['subtotal'] -= (float) $note->subtotal;
            $row['igv'] -= (float) $note->igv;
            $row['total'] -= (float) $note->total;
            $months[$month] = $row;
        }

        return response()->json([
            'data' => $months->values()->map(fn (array $row): array => [
                ...$row,
                'subtotal' => number_format($row['subtotal'], 2, '.', ''),
                'igv' => number_format($row['igv'], 2, '.', ''),
                'total' => number_format($row['total'], 2, '.', ''),
            ]),
            'meta' => ['year' => $year, 'currency' => 'PEN', 'environment' => $company->sunat_environment],
        ]);
    }

    /** @return array<int, array{description: string, quantity: string}> */
    private function items(iterable $items): array
    {
        $rows = [];
        foreach ($items as $item) {
            $rows[] = ['description' => trim((string) $item->description), 'quantity' => number_format((float) $item->quantity, 3, '.', '')];
        }

        return $rows;
    }

    private function presentInvoice(Invoice $invoice, bool $withItems = false): array
    {
        $draft = $invoice->draft;
        $type = $invoice->document_type ?: '01';

        return [
            ...($withItems ? ['items' => $this->items($draft?->items ?? [])] : []),
            'id' => $invoice->id,
            'kind' => 'invoice',
            'document_type' => $type,
            'number' => $invoice->number,
            'issue_date' => $draft?->issue_date?->format('Y-m-d'),
            'customer' => [
                'document_type' => $draft?->customer_document_type,
                'document_number' => $draft?->customer_ruc,
                'name' => $draft?->customer_name,
            ],
            'currency' => $draft?->currency ?? 'PEN',
            'subtotal' => (string) ($draft?->subtotal ?? '0.00'),
            'igv' => (string) ($draft?->igv ?? '0.00'),
            'total' => (string) ($draft?->total ?? '0.00'),
            'payment_method' => $draft?->payment_method,
            'status' => $invoice->status,
            'sunat_message' => $invoice->sunat_message,
            'related_number' => null,
            'files' => [
                'pdf' => $invoice->status === 'accepted',
                'xml' => (bool) $invoice->xml_path,
                'cdr' => (bool) $invoice->cdr_path,
            ],
            'created_at' => $invoice->created_at?->toIso8601String(),
        ];
    }

    private function presentCreditNote(CreditNote $note, bool $withItems = false): array
    {
        $draft = $note->invoice?->draft;

        return [
            ...($withItems ? ['items' => $this->items($note->items)] : []),
            'id' => $note->id,
            'kind' => 'credit_note',
            'document_type' => '07',
            'number' => $note->number,
            'issue_date' => $note->issue_date?->format('Y-m-d'),
            'customer' => [
                'document_type' => $draft?->customer_document_type,
                'document_number' => $draft?->customer_ruc,
                'name' => $draft?->customer_name,
            ],
            'currency' => $note->currency ?? 'PEN',
            'subtotal' => (string) $note->subtotal,
            'igv' => (string) $note->igv,
            'total' => (string) $note->total,
            'payment_method' => null,
            'status' => $note->status,
            'sunat_message' => $note->sunat_message,
            'related_number' => $note->invoice?->number,
            'files' => [
                'pdf' => false,
                'xml' => (bool) $note->xml_path,
                'cdr' => (bool) $note->cdr_path,
            ],
            'created_at' => $note->created_at?->toIso8601String(),
        ];
    }
}
