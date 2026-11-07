<?php

namespace App\Services;

use App\Contracts\SunatGateway;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\FiscalOperation;
use App\Models\FiscalPreview;
use App\Models\Invoice;
use App\Models\InvoiceDraft;
use App\Services\Sunat\SunatGatewayManager;
use App\Support\CanonicalJson;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class FiscalOperationService
{
    private const SUPPORTED_CONTRACT_VERSION = '1';

    public function __construct(
        private readonly InvoiceSequenceService $sequences,
        private readonly SunatGatewayManager $gateways,
        private readonly InvoicePdfService $invoicePdfs,
        private readonly CreditNotePdfService $creditNotePdfs,
        private readonly ?SunatGateway $gatewayOverride = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function issue(Company $company, array $input): array
    {
        $contractVersion = (string) ($input['contract_version'] ?? '');
        if ($contractVersion !== self::SUPPORTED_CONTRACT_VERSION) {
            $this->fail(422, 'unsupported_contract_version');
        }

        $documentType = (string) ($input['document_type'] ?? '');
        if (! in_array($documentType, ['01', '03', '07'], true)) {
            $this->fail(422, 'unsupported_document_type');
        }

        if ($company->sunat_driver !== 'fake') {
            $this->fail(503, 'real_sunat_emission_disabled');
        }

        $environment = (string) $company->sunat_environment;
        if (! in_array($environment, ['beta', 'production'], true)) {
            $this->fail(500, 'sunat_environment_invalid');
        }

        $operationId = (string) ($input['operation_id'] ?? '');
        $idempotencyKey = (string) ($input['idempotency_key'] ?? '');
        $confirmation = (array) ($input['confirmation'] ?? []);
        $previewDigest = (string) ($confirmation['preview_digest'] ?? '');
        $actorId = (string) ($confirmation['actor_id'] ?? '');
        $confirmedAt = (string) ($confirmation['confirmed_at'] ?? '');

        if ($operationId === '' || $idempotencyKey === '' || $previewDigest === '' || $actorId === '' || $confirmedAt === '') {
            $this->fail(422, 'validation_failed');
        }

        // TX1: Claim preview, allocate sequence, and persist operation in 'processing' status
        $claim = DB::transaction(function () use (
            $company,
            $operationId,
            $idempotencyKey,
            $documentType,
            $environment,
            $previewDigest,
            $actorId,
            $confirmedAt,
            $confirmation,
            $input,
        ): array {
            // 1. Idempotency verification by operation_id or idempotency_key
            $existingOp = FiscalOperation::query()
                ->where('company_id', $company->id)
                ->where(function ($query) use ($operationId, $idempotencyKey): void {
                    $query->where('operation_id', $operationId)
                        ->orWhere('idempotency_key', $idempotencyKey);
                })
                ->lockForUpdate()
                ->first();

            // 2. Preview lookup and exact validation
            $preview = FiscalPreview::query()
                ->where('company_id', $company->id)
                ->where('operation_id', $operationId)
                ->where('preview_digest', $previewDigest)
                ->lockForUpdate()
                ->first();

            if ($preview === null) {
                if ($existingOp !== null) {
                    $preview = $existingOp->preview;
                }
                if ($preview === null) {
                    $this->fail(409, 'preview_digest_mismatch');
                }
            }

            // Strict payload & totals verification against locked preview
            if (isset($input['payload']) && is_array($input['payload'])) {
                $this->assertPayloadMatchesPreview($input['payload'], $preview);
            }
            if (isset($input['totals']) && is_array($input['totals'])) {
                if (
                    (string) ($input['totals']['total'] ?? '') !== (string) ($preview->totals_json['total'] ?? '')
                    || (string) ($input['totals']['subtotal'] ?? '') !== (string) ($preview->totals_json['subtotal'] ?? '')
                ) {
                    $this->fail(409, 'preview_digest_mismatch');
                }
            }

            // Canonical request hash bound to exact preview payload, actor, confirmation timestamp and idempotency key
            $requestHash = CanonicalJson::digest([
                'company_id' => $company->id,
                'environment' => $environment,
                'operation_id' => $operationId,
                'idempotency_key' => $idempotencyKey,
                'document_type' => $documentType,
                'preview_id' => $preview->id,
                'preview_digest' => $previewDigest,
                'confirmation_actor' => $actorId,
                'confirmation_confirmed_at' => $confirmedAt,
                'payload' => $preview->payload_json,
            ]);

            // If existing operation matches, return it without re-dispatching
            if ($existingOp !== null) {
                if ($existingOp->operation_id !== $operationId || $existingOp->idempotency_key !== $idempotencyKey) {
                    $this->fail(409, 'idempotency_conflict');
                }

                if ($existingOp->request_hash !== $requestHash) {
                    $this->fail(409, 'idempotency_conflict');
                }

                return [
                    'already_resolved' => true,
                    'op' => $existingOp,
                ];
            }

            if ($preview->document_type !== $documentType) {
                $this->fail(409, 'preview_digest_mismatch');
            }

            if ($preview->consumed_at !== null) {
                $this->fail(409, 'preview_consumed');
            }

            try {
                $confirmedDt = Carbon::parse($confirmedAt);
                if ($confirmedDt->greaterThan($preview->valid_until)) {
                    $this->fail(409, 'preview_expired');
                }
            } catch (HttpResponseException $e) {
                throw $e;
            } catch (Throwable) {
                $this->fail(422, 'validation_failed');
            }

            $now = now('UTC');
            if ($preview->valid_until->utc()->lessThanOrEqualTo($now)) {
                $this->fail(409, 'preview_expired');
            }

            // 3. Mark preview as consumed (Atomic CAS)
            $preview->update([
                'consumed_at' => $now->toDateTimeString(),
            ]);

            // 4. Allocate sequence and persist records in TX1
            if ($documentType === '07') {
                $originData = (array) ($preview->payload_json['origin'] ?? []);
                $originSeries = strtoupper(trim((string) ($originData['series'] ?? '')));
                $originCorrelative = (int) ($originData['correlative'] ?? 0);

                $originInvoice = Invoice::query()
                    ->where('company_id', $company->id)
                    ->where('series', $originSeries)
                    ->where('correlative', $originCorrelative)
                    ->where('status', 'accepted')
                    ->with(['draft.items'])
                    ->first();

                if ($originInvoice === null || $originInvoice->draft === null) {
                    $this->fail(422, 'origin_invoice_not_found');
                }

                $series = $originInvoice->document_type === '03'
                    ? ($company->default_boleta_credit_note_series ?: 'BC01')
                    : $company->default_credit_note_series;
                $correlative = $this->sequences->next($company, $series, $environment);

                $issueDate = $preview->issue_date->format('Y-m-d');
                $reasonCode = (string) ($preview->payload_json['reason_code'] ?? '01');
                $reasonDescription = (string) ($preview->payload_json['reason_description'] ?? 'Anulación de la operación');

                $creditNote = CreditNote::create([
                    'company_id' => $company->id,
                    'sunat_environment' => $environment,
                    'invoice_id' => $originInvoice->id,
                    'series' => $series,
                    'correlative' => $correlative,
                    'issue_date' => $issueDate,
                    'reason_code' => $reasonCode,
                    'reason_description' => $reasonDescription,
                    'currency' => $originInvoice->draft->currency,
                    'subtotal' => $preview->totals_json['subtotal'],
                    'igv' => $preview->totals_json['igv'],
                    'total' => $preview->totals_json['total'],
                    'status' => 'processing',
                ]);

                foreach ($originInvoice->draft->items as $idx => $draftItem) {
                    $creditNote->items()->create([
                        'invoice_draft_item_id' => $draftItem->id,
                        'position' => $idx + 1,
                        'description' => $draftItem->description,
                        'quantity' => $draftItem->quantity,
                        'entered_unit_price' => $draftItem->entered_unit_price,
                        'unit_value' => $draftItem->unit_value,
                        'unit_price_with_igv' => $draftItem->unit_price_with_igv,
                        'line_base' => $draftItem->line_base,
                        'igv' => $draftItem->igv,
                        'line_total' => $draftItem->line_total,
                    ]);
                }

                $fiscalOp = FiscalOperation::create([
                    'company_id' => $company->id,
                    'operation_id' => $operationId,
                    'idempotency_key' => $idempotencyKey,
                    'document_type' => $documentType,
                    'sunat_environment' => $environment,
                    'preview_id' => $preview->id,
                    'preview_digest' => $previewDigest,
                    'request_hash' => $requestHash,
                    'status' => 'processing',
                    'payload_json' => $preview->payload_json,
                    'confirmation_json' => $confirmation,
                    'totals_json' => $preview->totals_json,
                    'credit_note_id' => $creditNote->id,
                    'series' => $series,
                    'correlative' => $correlative,
                    'issue_date' => $issueDate,
                    'origin_number' => $originInvoice->number,
                ]);

                return [
                    'already_resolved' => false,
                    'op' => $fiscalOp,
                    'document_type' => '07',
                    'credit_note_id' => $creditNote->id,
                ];
            } else {
                $series = $documentType === '03'
                    ? ($company->default_boleta_series ?: 'B001')
                    : $company->default_series;
                $correlative = $this->sequences->next($company, $series, $environment);

                $customer = (array) ($preview->payload_json['customer'] ?? []);
                $issueDate = $preview->issue_date->format('Y-m-d');

                $draft = InvoiceDraft::create([
                    'company_id' => $company->id,
                    'customer_ruc' => (string) ($customer['number'] ?? ''),
                    'customer_name' => (string) ($customer['name'] ?? ''),
                    'customer_document_type' => (string) ($customer['document_type'] ?? '0'),
                    'issue_date' => $issueDate,
                    'tax_mode' => $preview->tax_mode,
                    'currency' => $preview->currency,
                    'status' => 'issuing',
                    'source_path' => 'api/fiscal-operations',
                    'original_name' => 'fiscal_operation_'.$operationId,
                    'mime_type' => 'application/json',
                    'ai_driver' => 'manual',
                    'subtotal' => $preview->totals_json['subtotal'],
                    'igv' => $preview->totals_json['igv'],
                    'total' => $preview->totals_json['total'],
                    'document_type' => $documentType,
                ]);

                foreach ($preview->lines_json as $idx => $line) {
                    $draft->items()->create([
                        'position' => $idx + 1,
                        'description' => $line['description'],
                        'quantity' => $line['quantity'],
                        'entered_unit_price' => $line['unit_price_with_igv'] ?? $line['unit_value'],
                        'unit_value' => $line['unit_value'],
                        'unit_price_with_igv' => $line['unit_price_with_igv'],
                        'line_base' => $line['line_base'],
                        'igv' => $line['igv'],
                        'line_total' => $line['line_total'],
                    ]);
                }

                $invoice = Invoice::create([
                    'company_id' => $company->id,
                    'sunat_environment' => $environment,
                    'document_type' => $documentType,
                    'invoice_draft_id' => $draft->id,
                    'series' => $series,
                    'correlative' => $correlative,
                    'status' => 'processing',
                ]);

                $fiscalOp = FiscalOperation::create([
                    'company_id' => $company->id,
                    'operation_id' => $operationId,
                    'idempotency_key' => $idempotencyKey,
                    'document_type' => $documentType,
                    'sunat_environment' => $environment,
                    'preview_id' => $preview->id,
                    'preview_digest' => $previewDigest,
                    'request_hash' => $requestHash,
                    'status' => 'processing',
                    'payload_json' => $preview->payload_json,
                    'confirmation_json' => $confirmation,
                    'totals_json' => $preview->totals_json,
                    'invoice_id' => $invoice->id,
                    'series' => $series,
                    'correlative' => $correlative,
                    'issue_date' => $issueDate,
                ]);

                return [
                    'already_resolved' => false,
                    'op' => $fiscalOp,
                    'document_type' => $documentType,
                    'draft_id' => $draft->id,
                    'invoice_id' => $invoice->id,
                ];
            }
        });

        // If operation already resolved, return existing response without calling gateway
        if ($claim['already_resolved']) {
            return $this->responseData($claim['op']);
        }

        // 5. Gateway Dispatch OUTSIDE of Transaction:
        // Even if gateway throws or connection drops, the operation record is already committed!
        /** @var FiscalOperation $fiscalOp */
        $fiscalOp = $claim['op'];
        try {
            $gateway = $this->gatewayOverride ?? $this->gateways->for($company);
            if ($claim['document_type'] === '07') {
                $creditNote = CreditNote::findOrFail($claim['credit_note_id']);
                $res = $gateway->issueCreditNote($creditNote);

                DB::transaction(function () use ($fiscalOp, $creditNote, $res): void {
                    $creditNote->update([
                        'status' => $res['status'],
                        'sunat_code' => $res['code'] ?? null,
                        'sunat_message' => $res['message'] ?? null,
                        'sunat_notes' => $res['notes'] ?? null,
                        'xml_path' => $res['xml_path'] ?? null,
                        'cdr_path' => $res['cdr_path'] ?? null,
                        'issued_at' => ($res['status'] ?? '') === 'accepted' ? now() : null,
                    ]);

                    $fiscalOp->update([
                        'status' => $res['status'] ?? 'unknown',
                        'sunat_code' => $res['code'] ?? null,
                        'sunat_message' => $res['message'] ?? null,
                        'sunat_notes' => $res['notes'] ?? null,
                        'xml_path' => $res['xml_path'] ?? null,
                        'cdr_path' => $res['cdr_path'] ?? null,
                        'issued_at' => ($res['status'] ?? '') === 'accepted' ? now() : null,
                    ]);
                });

                if (($res['status'] ?? '') === 'accepted') {
                    try {
                        $freshCn = $creditNote->fresh();
                        $pdfBinary = $this->creditNotePdfs->render($freshCn);
                        $pdfPath = "credit_notes/{$creditNote->id}/{$creditNote->number}.pdf";
                        Storage::disk('local')->put($pdfPath, $pdfBinary);
                        $fiscalOp->update(['pdf_path' => $pdfPath]);
                    } catch (Throwable $pdfEx) {
                        Log::warning('fiscal_pdf_generation_failed');
                    }
                }
            } else {
                $draft = InvoiceDraft::findOrFail($claim['draft_id']);
                $invoice = Invoice::findOrFail($claim['invoice_id']);
                $res = $gateway->issue($draft, $invoice);

                DB::transaction(function () use ($fiscalOp, $draft, $invoice, $res): void {
                    $invoice->update([
                        'status' => $res['status'],
                        'sunat_code' => $res['code'] ?? null,
                        'sunat_message' => $res['message'] ?? null,
                        'sunat_notes' => $res['notes'] ?? null,
                        'xml_path' => $res['xml_path'] ?? null,
                        'cdr_path' => $res['cdr_path'] ?? null,
                        'issued_at' => ($res['status'] ?? '') === 'accepted' ? now() : null,
                    ]);
                    $draft->update([
                        'status' => ($res['status'] ?? '') === 'accepted' ? 'issued' : 'rejected',
                    ]);
                    $fiscalOp->update([
                        'status' => $res['status'] ?? 'unknown',
                        'sunat_code' => $res['code'] ?? null,
                        'sunat_message' => $res['message'] ?? null,
                        'sunat_notes' => $res['notes'] ?? null,
                        'xml_path' => $res['xml_path'] ?? null,
                        'cdr_path' => $res['cdr_path'] ?? null,
                        'issued_at' => ($res['status'] ?? '') === 'accepted' ? now() : null,
                    ]);
                });

                if (($res['status'] ?? '') === 'accepted') {
                    try {
                        $freshInv = $invoice->fresh();
                        $pdfBinary = $this->invoicePdfs->render($freshInv);
                        $pdfPath = "invoices/{$invoice->id}/{$invoice->number}.pdf";
                        Storage::disk('local')->put($pdfPath, $pdfBinary);
                        $fiscalOp->update(['pdf_path' => $pdfPath]);
                    } catch (Throwable $pdfEx) {
                        Log::warning('fiscal_pdf_generation_failed');
                    }
                }
            }
        } catch (Throwable $e) {
            Log::warning('fiscal_provider_uncertain');
            // On gateway crash / timeout, record 'unknown' in a separate transaction.
            // Do NOT roll back the FiscalOperation: preserve identity so queries find it as unknown.
            try {
                $fiscalOp->update([
                    'status' => 'unknown',
                    'sunat_code' => 'GATEWAY_COMMUNICATION_ERROR',
                    'sunat_message' => 'Fallo de comunicacion con el proveedor fiscal',
                ]);
            } catch (Throwable $updateEx) {
                Log::error('fiscal_result_persist_failed');
            }

            $this->fail(500, 'provider_gateway_communication_failed');
        }

        return $this->responseData($fiscalOp->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function show(Company $company, string $operationId): array
    {
        $op = FiscalOperation::query()
            ->where('company_id', $company->id)
            ->where('operation_id', $operationId)
            ->first();

        if ($op === null) {
            $this->fail(404, 'operation_not_found');
        }

        return $this->responseData($op);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function reconcile(Company $company, string $operationId, array $input = []): array
    {
        $op = FiscalOperation::query()
            ->where('company_id', $company->id)
            ->where('operation_id', $operationId)
            ->first();

        if ($op === null) {
            $this->fail(404, 'operation_not_found');
        }

        // A company token CANNOT manually declare not_issued or submit operator authorization
        if (
            array_key_exists('evidence_ref', $input) ||
            array_key_exists('operator_ref', $input) ||
            array_key_exists('verified', $input) ||
            (($input['target_status'] ?? '') === 'not_issued')
        ) {
            $this->fail(403, 'manual_resolution_forbidden_for_company_token');
        }

        // If already resolved, return as is
        if (in_array($op->status, ['accepted', 'rejected'], true)) {
            return $this->responseData($op);
        }

        // Check if underlying invoice / credit note exists and is accepted (Atomic CAS)
        if ($op->invoice_id !== null) {
            $inv = Invoice::find($op->invoice_id);
            if ($inv !== null && $inv->status === 'accepted') {
                $pdfPath = $op->pdf_path;

                FiscalOperation::query()
                    ->where('id', $op->id)
                    ->where('status', '!=', 'accepted')
                    ->update([
                        'status' => 'accepted',
                        'sunat_code' => $inv->sunat_code,
                        'sunat_message' => $inv->sunat_message,
                        'sunat_notes' => $inv->sunat_notes,
                        'xml_path' => $inv->xml_path,
                        'cdr_path' => $inv->cdr_path,
                        'pdf_path' => $pdfPath,
                        'issued_at' => $inv->issued_at,
                    ]);

                $resolved = $op->fresh();
                $this->ensurePdfGenerated($resolved);

                return $this->responseData($resolved->fresh());
            }
        }

        if ($op->credit_note_id !== null) {
            $cn = CreditNote::find($op->credit_note_id);
            if ($cn !== null && $cn->status === 'accepted') {
                $pdfPath = $op->pdf_path;

                FiscalOperation::query()
                    ->where('id', $op->id)
                    ->where('status', '!=', 'accepted')
                    ->update([
                        'status' => 'accepted',
                        'sunat_code' => $cn->sunat_code,
                        'sunat_message' => $cn->sunat_message,
                        'sunat_notes' => $cn->sunat_notes,
                        'xml_path' => $cn->xml_path,
                        'cdr_path' => $cn->cdr_path,
                        'pdf_path' => $pdfPath,
                        'issued_at' => $cn->issued_at,
                    ]);

                $resolved = $op->fresh();
                $this->ensurePdfGenerated($resolved);

                return $this->responseData($resolved->fresh());
            }
        }

        // Without conclusive provider confirmation, company token query cannot change status
        return $this->responseData($op);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function adminDeclareNotIssued(string $operationId, array $input): array
    {
        $op = FiscalOperation::query()
            ->where('operation_id', $operationId)
            ->first();

        if ($op === null) {
            $this->fail(404, 'operation_not_found');
        }

        // Atomic CAS check: Cannot overwrite if already accepted
        if ($op->status === 'accepted') {
            $this->fail(409, 'operation_already_accepted');
        }

        if ($op->status !== 'unknown') {
            $this->fail(409, 'operation_not_uncertain');
        }

        if ($op->invoice_id !== null) {
            $inv = Invoice::find($op->invoice_id);
            if ($inv !== null && $inv->status === 'accepted') {
                $this->fail(409, 'operation_already_accepted');
            }
        }

        if ($op->credit_note_id !== null) {
            $cn = CreditNote::find($op->credit_note_id);
            if ($cn !== null && $cn->status === 'accepted') {
                $this->fail(409, 'operation_already_accepted');
            }
        }

        $evidenceRef = trim((string) ($input['evidence_ref'] ?? ''));
        if ($evidenceRef === '') {
            $this->fail(422, 'evidence_reference_required');
        }

        $operatorRef = trim((string) ($input['operator_ref'] ?? 'platform_admin'));

        $updated = FiscalOperation::query()
            ->where('id', $op->id)
            ->where('status', 'unknown')
            ->update([
                'status' => 'not_issued',
                'reconciliation_evidence' => [
                    'evidence_ref' => $evidenceRef,
                    'operator_ref' => $operatorRef,
                    'verified' => true,
                    'notes' => $input['notes'] ?? null,
                    'reconciled_at' => now('UTC')->toIso8601String(),
                    'authorized_by' => 'platform.admin',
                ],
            ]);

        if (! $updated) {
            $this->fail(409, 'operation_already_accepted');
        }

        return $this->responseData($op->fresh());
    }

    public function file(Company $company, string $operationId, string $type): StreamedResponse
    {
        $op = FiscalOperation::query()
            ->where('company_id', $company->id)
            ->where('operation_id', $operationId)
            ->first();

        if ($op === null || $op->status !== 'accepted') {
            $this->fail(404, 'file_not_found');
        }

        $path = match ($type) {
            'pdf' => $op->pdf_path,
            'xml' => $op->xml_path,
            'cdr' => $op->cdr_path,
            default => null,
        };

        if ($type === 'pdf' && ($path === null || ! Storage::disk('local')->exists($path))) {
            $path = $this->ensurePdfGenerated($op);
        }

        if ($path === null || ! Storage::disk('local')->exists($path)) {
            $this->fail(404, 'file_not_found');
        }

        $filename = match ($type) {
            'pdf' => $op->full_number.'.pdf',
            'xml' => $op->full_number.'.xml',
            'cdr' => 'R-'.$op->full_number.'.xml',
            default => basename($path),
        };

        return Storage::disk('local')->download($path, $filename);
    }

    private function ensurePdfGenerated(FiscalOperation $op): ?string
    {
        if ($op->status !== 'accepted') {
            return null;
        }

        if ($op->pdf_path && Storage::disk('local')->exists($op->pdf_path)) {
            return $op->pdf_path;
        }

        if ($op->invoice_id !== null) {
            $inv = Invoice::find($op->invoice_id);
            if ($inv !== null && $inv->status === 'accepted') {
                try {
                    $binary = $this->invoicePdfs->render($inv);
                    $path = "invoices/{$inv->id}/{$inv->number}.pdf";
                    Storage::disk('local')->put($path, $binary);
                    $op->update(['pdf_path' => $path]);

                    return $path;
                } catch (Throwable $e) {
                    Log::warning('fiscal_pdf_generation_failed');
                }
            }
        }

        if ($op->credit_note_id !== null) {
            $cn = CreditNote::find($op->credit_note_id);
            if ($cn !== null && $cn->status === 'accepted') {
                try {
                    $binary = $this->creditNotePdfs->render($cn);
                    $path = "credit_notes/{$cn->id}/{$cn->number}.pdf";
                    Storage::disk('local')->put($path, $binary);
                    $op->update(['pdf_path' => $path]);

                    return $path;
                } catch (Throwable $e) {
                    Log::warning('fiscal_pdf_generation_failed');
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function responseData(FiscalOperation $op): array
    {
        $artifacts = [];
        if ($op->status === 'accepted') {
            $pdfPath = $op->pdf_path;
            $pdfAvailable = ! empty($pdfPath) && Storage::disk('local')->exists($pdfPath);
            $xmlAvailable = ! empty($op->xml_path) && Storage::disk('local')->exists($op->xml_path);
            $cdrAvailable = ! empty($op->cdr_path) && Storage::disk('local')->exists($op->cdr_path);

            $artifacts[] = [
                'kind' => 'pdf',
                'availability' => $pdfAvailable ? 'available' : 'pending',
                'artifact_id' => $pdfAvailable ? 'pdf_'.$op->id : null,
                'mime_type' => 'application/pdf',
                'filename' => $op->full_number.'.pdf',
                'size_bytes' => $pdfAvailable ? Storage::disk('local')->size($pdfPath) : null,
            ];
            $artifacts[] = [
                'kind' => 'xml',
                'availability' => $xmlAvailable ? 'available' : 'pending',
                'artifact_id' => $xmlAvailable ? 'xml_'.$op->id : null,
                'mime_type' => 'application/xml',
                'filename' => $op->full_number.'.xml',
                'size_bytes' => $xmlAvailable ? Storage::disk('local')->size($op->xml_path) : null,
            ];
            $artifacts[] = [
                'kind' => 'cdr',
                'availability' => $cdrAvailable ? 'available' : 'pending',
                'artifact_id' => $cdrAvailable ? 'cdr_'.$op->id : null,
                'mime_type' => 'application/xml',
                'filename' => 'R-'.$op->full_number.'.xml',
                'size_bytes' => $cdrAvailable ? Storage::disk('local')->size($op->cdr_path) : null,
            ];
        }

        $doc = null;
        if ($op->series && $op->correlative !== null) {
            $doc = [
                'series' => $op->series,
                'number' => str_pad((string) $op->correlative, 8, '0', STR_PAD_LEFT),
                'issue_date' => $op->issue_date?->format('Y-m-d'),
                'provider_status' => $op->status,
                'provider_code' => $op->sunat_code,
                'provider_message' => $op->sunat_message,
                'origin_number' => $op->origin_number,
                'totals' => $op->totals_json,
            ];
        }

        $error = null;
        if (in_array($op->status, ['rejected', 'error', 'unknown'], true) && $op->sunat_code) {
            $error = [
                'code' => $op->sunat_code ?: 'provider_error',
                'message' => $op->sunat_message ?: 'Error reportado por el proveedor',
            ];
        }

        return [
            'operation_id' => $op->operation_id,
            'status' => $op->status,
            'document_type' => $op->document_type,
            'document' => $doc,
            'artifacts' => $artifacts,
            'error' => $error,
            'reconciliation_evidence' => $op->reconciliation_evidence,
        ];
    }

    /**
     * @param  array<string, mixed>  $inputPayload
     */
    private function assertPayloadMatchesPreview(array $inputPayload, FiscalPreview $preview): void
    {
        // 1. Verify customer
        if (isset($inputPayload['customer']) && is_array($inputPayload['customer'])) {
            $prevCust = (array) ($preview->payload_json['customer'] ?? []);
            foreach (['document_type', 'number', 'name'] as $field) {
                if (isset($inputPayload['customer'][$field])) {
                    $inVal = (string) $inputPayload['customer'][$field];
                    $prevVal = (string) ($prevCust[$field] ?? '');
                    if ($inVal !== $prevVal) {
                        $this->fail(409, 'preview_payload_mismatch');
                    }
                }
            }
        }

        // 2. Verify issue_date
        if (isset($inputPayload['issue_date'])) {
            $inDate = (string) $inputPayload['issue_date'];
            $prevDate = $preview->issue_date->format('Y-m-d');
            if ($inDate !== $prevDate) {
                $this->fail(409, 'preview_payload_mismatch');
            }
        }

        // 3. Verify currency
        if (isset($inputPayload['currency'])) {
            if ((string) $inputPayload['currency'] !== (string) $preview->currency) {
                $this->fail(409, 'preview_payload_mismatch');
            }
        }

        // 4. Verify origin for credit note
        if ($preview->document_type === '07' && isset($inputPayload['origin']) && is_array($inputPayload['origin'])) {
            $prevOrigin = (array) ($preview->payload_json['origin'] ?? []);
            $inSeries = strtoupper(trim((string) ($inputPayload['origin']['series'] ?? '')));
            $inCorrelative = (int) ($inputPayload['origin']['correlative'] ?? 0);
            $prevSeries = strtoupper(trim((string) ($prevOrigin['series'] ?? '')));
            $prevCorrelative = (int) ($prevOrigin['correlative'] ?? 0);
            if ($inSeries !== $prevSeries || $inCorrelative !== $prevCorrelative) {
                $this->fail(409, 'preview_payload_mismatch');
            }
        }

        // 5. Verify items
        if (isset($inputPayload['items']) && is_array($inputPayload['items'])) {
            $prevItems = (array) ($preview->payload_json['items'] ?? []);
            if (count($inputPayload['items']) !== count($prevItems)) {
                $this->fail(409, 'preview_payload_mismatch');
            }
            foreach ($inputPayload['items'] as $idx => $inItem) {
                $prevItem = (array) ($prevItems[$idx] ?? []);
                if (isset($inItem['total_price']) && isset($prevItem['total_price'])) {
                    if ((string) $inItem['total_price'] !== (string) ($prevItem['total_price'] ?? '')) {
                        $this->fail(409, 'preview_payload_mismatch');
                    }
                }
            }
        }
    }

    private function fail(int $status, string $code): never
    {
        throw new HttpResponseException(
            response()->json(['code' => $code, 'message' => $code], $status),
        );
    }
}
