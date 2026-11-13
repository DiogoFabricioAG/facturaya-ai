<?php

namespace App\Http\Controllers;

use App\Services\InvoicePdfService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * PDF of a Sales Cat quote with the documents' single layout (ADR-0013). Stateless: the
 * bridge sends the quote, gets the PDF back and keeps it; FacturaYa stores nothing.
 * Same internal bearer as the taxpayer lookup.
 */
final class InternalQuotePdfController extends Controller
{
    public function __invoke(Request $request, InvoicePdfService $pdfs): Response
    {
        $quote = $request->validate([
            'company' => ['required', 'array'],
            'company.name' => ['required', 'string', 'max:160'],
            'company.legal_name' => ['nullable', 'string', 'max:255'],
            'company.ruc' => ['nullable', 'string', 'regex:/^\d{11}$/'],
            'company.address' => ['nullable', 'string', 'max:500'],
            'number' => ['required', 'integer', 'min:1', 'max:99999999'],
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'valid_until' => ['required', 'date_format:Y-m-d'],
            'customer' => ['required', 'array'],
            'customer.name' => ['required', 'string', 'max:255'],
            'customer.document_type' => ['nullable', 'string', 'in:0,1,4,6,7'],
            'customer.document_number' => ['nullable', 'string', 'max:20'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'lines.*.unit_price_cents' => ['required', 'integer', 'min:0', 'max:99999999999'],
            'lines.*.total_cents' => ['required', 'integer', 'min:0', 'max:99999999999'],
            'subtotal_cents' => ['required', 'integer', 'min:0', 'max:99999999999'],
            'discount_cents' => ['required', 'integer', 'min:0', 'max:99999999999'],
            'total_cents' => ['required', 'integer', 'min:0', 'max:99999999999'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return response($pdfs->renderQuote($quote), 200, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
