<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_drafts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('customer_ruc', 11);
            $table->string('customer_name');
            $table->date('issue_date');
            $table->string('tax_mode', 16);
            $table->string('currency', 3)->default('PEN');
            $table->string('status', 32)->default('analyzing');
            $table->string('source_path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->string('ai_driver', 32);
            $table->json('raw_extraction')->nullable();
            $table->json('warnings')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('igv', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_draft_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('invoice_draft_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('description', 500);
            $table->decimal('quantity', 12, 3);
            $table->decimal('entered_unit_price', 14, 2);
            $table->decimal('unit_value', 14, 6);
            $table->decimal('unit_price_with_igv', 14, 6);
            $table->decimal('line_base', 14, 2);
            $table->decimal('igv', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->decimal('confidence', 5, 4)->nullable();
            $table->unsignedInteger('source_page')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('invoice_draft_id')->unique()->constrained()->restrictOnDelete();
            $table->string('series', 8);
            $table->unsignedBigInteger('correlative');
            $table->string('status', 32)->default('processing');
            $table->string('sunat_code', 20)->nullable();
            $table->text('sunat_message')->nullable();
            $table->json('sunat_notes')->nullable();
            $table->string('xml_path')->nullable();
            $table->string('cdr_path')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            $table->unique(['series', 'correlative']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_draft_items');
        Schema::dropIfExists('invoice_drafts');
    }
};
