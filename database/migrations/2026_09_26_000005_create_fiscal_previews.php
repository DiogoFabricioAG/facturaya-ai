<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_previews', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('operation_id', 80);
            $table->string('idempotency_key', 120);
            $table->unsignedInteger('draft_revision');
            $table->string('document_type', 2);
            $table->string('sunat_environment', 16);
            $table->unsignedInteger('calculation_version');
            $table->string('request_hash', 64);
            $table->string('preview_digest', 80);
            $table->date('issue_date');
            $table->char('currency', 3);
            $table->string('tax_mode', 16);
            $table->json('payload_json');
            $table->json('lines_json');
            $table->json('totals_json');
            $table->timestamp('valid_until');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'operation_id', 'draft_revision']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'operation_id', 'superseded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_previews');
    }
};
