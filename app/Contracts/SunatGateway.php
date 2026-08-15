<?php

namespace App\Contracts;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoiceDraft;

interface SunatGateway
{
    /**
     * @return array{
     *   status: string,
     *   code: string,
     *   message: string,
     *   notes: array<int, string>,
     *   xml_path: string|null,
     *   cdr_path: string|null
     * }
     */
    public function issue(InvoiceDraft $draft, Invoice $invoice): array;

    /**
     * @return array{
     *   status: string,
     *   code: string,
     *   message: string,
     *   notes: array<int, string>,
     *   xml_path: string|null,
     *   cdr_path: string|null
     * }
     */
    public function issueCreditNote(CreditNote $creditNote): array;
}
