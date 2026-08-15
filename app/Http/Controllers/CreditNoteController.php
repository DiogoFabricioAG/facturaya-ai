<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCreditNoteRequest;
use App\Http\Resources\CreditNoteResource;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Services\CompanyContext;
use App\Services\CreditNoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CreditNoteController extends Controller
{
    public function index(Invoice $invoice, CompanyContext $context): AnonymousResourceCollection
    {
        abort_unless($context->owns($invoice->company_id), 404);

        return CreditNoteResource::collection(
            $invoice->creditNotes()->with(['invoice', 'items'])->latest()->get(),
        );
    }

    public function store(
        StoreCreditNoteRequest $request,
        Invoice $invoice,
        CreditNoteService $service,
        CompanyContext $context,
    ): JsonResponse {
        abort_unless($context->owns($invoice->company_id), 404);

        $creditNote = $service->issue($invoice, $request->validated());

        return (new CreditNoteResource($creditNote))
            ->response()
            ->setStatusCode(201);
    }

    public function file(CreditNote $creditNote, string $type, CompanyContext $context): StreamedResponse|JsonResponse
    {
        abort_unless($context->owns($creditNote->company_id), 404);

        $path = match ($type) {
            'xml' => $creditNote->xml_path,
            'cdr' => $creditNote->cdr_path,
            default => null,
        };

        if (! $path || ! Storage::disk('local')->exists($path)) {
            return response()->json(['message' => 'El archivo solicitado no está disponible.'], 404);
        }

        return Storage::disk('local')->download($path);
    }
}
