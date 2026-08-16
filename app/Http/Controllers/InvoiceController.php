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
        $existing = $invoiceDraft->invoice;

        if ($existing) {
            return (new InvoiceResource($existing))->response();
        }

        if ($invoiceDraft->status !== 'review_required' || ! $invoiceDraft->items()->exists()) {
            return response()->json(['message' => 'El borrador aún no está listo para emitir.'], 409);
        }

        $series = $company->default_series;
        $invoice = Invoice::create([
            'company_id' => $company->id,
            'invoice_draft_id' => $invoiceDraft->id,
            'series' => $series,
            'correlative' => $sequences->next($company, $series),
            'status' => 'processing',
        ]);

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
            ->setStatusCode(201);
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
