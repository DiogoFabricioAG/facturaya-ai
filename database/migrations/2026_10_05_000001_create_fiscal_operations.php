<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_operations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('operation_id', 80);
            $table->string('idempotency_key', 120);
            $table->string('document_type', 2);
            $table->string('sunat_environment', 16);
            $table->foreignUlid('preview_id')->nullable()->constrained('fiscal_previews')->nullOnDelete();
            $table->string('preview_digest', 80);
            $table->string('request_hash', 64);
            $table->string('status', 32); // processing, accepted, rejected, error, not_issued
            $table->foreignUlid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignUlid('credit_note_id')->nullable()->constrained('credit_notes')->nullOnDelete();
            $table->string('series', 8)->nullable();
            $table->unsignedBigInteger('correlative')->nullable();
            $table->date('issue_date')->nullable();
            $table->string('sunat_code', 32)->nullable();
            $table->text('sunat_message')->nullable();
            $table->json('sunat_notes')->nullable();
            $table->string('xml_path')->nullable();
            $table->string('cdr_path')->nullable();
            $table->string('pdf_path')->nullable();
            $table->string('origin_number', 32)->nullable();
            $table->json('totals_json')->nullable();
            $table->json('payload_json');
            $table->json('confirmation_json');
            $table->json('reconciliation_evidence')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'operation_id']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_operations');
    }
};
