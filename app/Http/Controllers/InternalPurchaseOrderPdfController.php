<?php

namespace App\Http\Controllers;

use App\Services\InvoicePdfService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * PDF of a Reorder Cat purchase order with the documents' single layout (ADR-0016 de
 * MeowLab). Stateless like the quote: the bridge sends the order and gets the PDF back.
 * Same internal bearer as the taxpayer lookup.
 */
final class InternalPurchaseOrderPdfController extends Controller
{
    public function __invoke(Request $request, InvoicePdfService $pdfs): Response
    {
        $order = $request->validate([
            'company' => ['required', 'array'],
            'company.name' => ['required', 'string', 'max:160'],
            'company.legal_name' => ['nullable', 'string', 'max:255'],
            'company.ruc' => ['nullable', 'string', 'regex:/^\d{11}$/'],
            'company.address' => ['nullable', 'string', 'max:500'],
            'number' => ['required', 'integer', 'min:1', 'max:99999999'],
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'deliver_by' => ['nullable', 'date_format:Y-m-d'],
            'supplier' => ['required', 'array'],
            'supplier.name' => ['required', 'string', 'max:255'],
            'supplier.ruc' => ['nullable', 'string', 'regex:/^\d{11}$/'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'lines.*.unit_price_cents' => ['nullable', 'integer', 'min:0', 'max:99999999999'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return response($pdfs->renderPurchaseOrder($order), 200, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
