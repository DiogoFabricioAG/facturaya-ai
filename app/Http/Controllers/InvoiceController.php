<?php

namespace App\Http\Controllers;

use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\InvoiceDraft;
use App\Services\CompanyContext;
use App\Services\InvoiceSequenceService;
use App\Services\InvoicePdfService;
use App\Services\Sunat\SunatGatewayManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class InvoiceController extends Controller
{
    public function store(
        InvoiceDraft $invoiceDraft,
        InvoiceSequenceService $sequences,
        SunatGatewayManager $gateways,
        CompanyContext $context,
    ): JsonResponse {
        abort_unless($context->owns($invoiceDraft->company_id), 404);
        $company = $context->company();
        $invoice = $invoiceDraft->invoice;
        $statusCode = 201;

        if ($invoice) {
            if ($invoice->sunat_environment !== $company->sunat_environment) {
                return response()->json([
                    'message' => 'Esta factura pertenece al entorno SUNAT '.$invoice->sunat_environment.' y no puede reenviarse desde '.$company->sunat_environment.'.',
                ], 409);
            }

            if ($invoice->status !== 'error') {
                return (new InvoiceResource($invoice))->response();
            }

            if ($invoiceDraft->status !== 'issue_failed' || ! $invoiceDraft->items()->exists()) {
                return response()->json(['message' => 'La factura no está disponible para reintentar.'], 409);
            }

            $claimed = Invoice::query()
                ->whereKey($invoice->id)
                ->where('status', 'error')
                ->update([
                    'status' => 'processing',
                    'sunat_code' => null,
                    'sunat_message' => null,
                    'sunat_notes' => null,
                    'cdr_path' => null,
                    'issued_at' => null,
                ]);

            if ($claimed === 0) {
                return (new InvoiceResource($invoice->fresh()))->response();
            }

            $invoice = $invoice->fresh();
            $statusCode = 200;
        } else {
            if ($invoiceDraft->status !== 'review_required' || ! $invoiceDraft->items()->exists()) {
                return response()->json(['message' => 'El borrador aún no está listo para emitir.'], 409);
            }

            $documentType = (string) ($invoiceDraft->document_type ?: '01');
            $series = $documentType === '03'
                ? ($company->default_boleta_series ?: 'B001')
                : $company->default_series;
            $invoice = Invoice::create([
                'company_id' => $company->id,
                'sunat_environment' => $company->sunat_environment,
                'document_type' => $documentType,
                'invoice_draft_id' => $invoiceDraft->id,
                'series' => $series,
                'correlative' => $sequences->next($company, $series, $company->sunat_environment),
                'status' => 'processing',
            ]);
        }

        $invoiceDraft->update(['status' => 'issuing']);

        try {
            $gateway = $gateways->for($company);
            $result = $gateway->issue($invoiceDraft, $invoice);
            $invoice->update([
                'status' => $result['status'],
                'sunat_code' => $result['code'],
                'sunat_message' => $result['message'],
                'sunat_notes' => $result['notes'],
                'xml_path' => $result['xml_path'],
                'cdr_path' => $result['cdr_path'],
                'issued_at' => $result['status'] === 'accepted' ? now() : null,
            ]);

            $invoiceDraft->update([
                'status' => match ($result['status']) {
                    'accepted' => 'issued',
                    'rejected' => 'rejected',
                    default => 'issue_failed',
                },
            ]);
        } catch (Throwable $exception) {
            report($exception);
            $invoice->update([
                'status' => 'error',
                'sunat_code' => 'APPLICATION_ERROR',
                'sunat_message' => $exception->getMessage(),
            ]);
            $invoiceDraft->update(['status' => 'issue_failed']);
        }

        return (new InvoiceResource($invoice->fresh()))
            ->response()
            ->setStatusCode($statusCode);
    }

    public function file(
        Invoice $invoice,
        string $type,
        CompanyContext $context,
        InvoicePdfService $pdfs,
    ): Response|StreamedResponse|JsonResponse
    {
        abort_unless($context->owns($invoice->company_id), 404);

        if ($type === 'pdf') {
            abort_unless($invoice->status === 'accepted', 404);

            return response($pdfs->render($invoice), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$invoice->number.'.pdf"',
            ]);
        }

        $path = match ($type) {
            'xml' => $invoice->xml_path,
            'cdr' => $invoice->cdr_path,
            default => null,
        };

        if (! $path || ! Storage::disk('local')->exists($path)) {
            return response()->json(['message' => 'El archivo solicitado no está disponible.'], 404);
        }

        return Storage::disk('local')->download($path);
    }
}
