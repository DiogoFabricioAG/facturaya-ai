<?php

use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanyTokenController;
use App\Http\Controllers\CreditNoteController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceDraftController;
use App\Http\Resources\CompanyResource;
use App\Services\CompanyContext;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'ai_driver' => config('facturaya.ai.driver'),
    'tenancy' => 'company_token',
]));

Route::prefix('admin')->middleware('platform.admin')->group(function (): void {
    Route::apiResource('companies', CompanyController::class)->except('destroy');
    Route::post('/companies/{company}/tokens', [CompanyTokenController::class, 'store']);
    Route::delete('/companies/{company}/tokens/{companyApiToken}', [CompanyTokenController::class, 'destroy']);
});

Route::middleware('company.auth')->group(function (): void {
    Route::get('/company', fn (CompanyContext $context) => new CompanyResource($context->company()));
    Route::get('/customers', [CustomerController::class, 'index']);
    Route::post('/customers', [CustomerController::class, 'store']);
    Route::get('/invoice-drafts', [InvoiceDraftController::class, 'index']);
    Route::post('/invoice-drafts/import', [InvoiceDraftController::class, 'store']);
    Route::get('/invoice-drafts/{invoiceDraft}', [InvoiceDraftController::class, 'show']);
    Route::put('/invoice-drafts/{invoiceDraft}', [InvoiceDraftController::class, 'update']);
    Route::post('/invoice-drafts/{invoiceDraft}/issue', [InvoiceController::class, 'store']);
    Route::get('/invoices/{invoice}/files/{type}', [InvoiceController::class, 'file'])
        ->whereIn('type', ['pdf', 'xml', 'cdr'])
        ->name('api.invoices.file');
    Route::get('/invoices/{invoice}/credit-notes', [CreditNoteController::class, 'index']);
    Route::post('/invoices/{invoice}/credit-notes', [CreditNoteController::class, 'store']);
    Route::get('/credit-notes/{creditNote}/files/{type}', [CreditNoteController::class, 'file'])
        ->whereIn('type', ['xml', 'cdr'])
        ->name('api.credit-notes.file');
});
