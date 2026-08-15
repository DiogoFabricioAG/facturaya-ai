<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('default_credit_note_series', 4)->default('FC01')->after('default_series');
        });

        Schema::create('credit_notes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('series', 4);
            $table->unsignedBigInteger('correlative');
            $table->date('issue_date');
            $table->char('reason_code', 2);
            $table->string('reason_description', 250);
            $table->char('currency', 3)->default('PEN');
            $table->decimal('subtotal', 14, 2);
            $table->decimal('igv', 14, 2);
            $table->decimal('total', 14, 2);
            $table->string('status', 30)->default('processing');
            $table->string('sunat_code', 50)->nullable();
            $table->text('sunat_message')->nullable();
            $table->json('sunat_notes')->nullable();
            $table->string('xml_path')->nullable();
            $table->string('cdr_path')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'series', 'correlative']);
            $table->index(['invoice_id', 'status']);
        });

        Schema::create('credit_note_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('credit_note_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_draft_item_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('description', 500);
            $table->decimal('quantity', 14, 3);
            $table->decimal('entered_unit_price', 14, 2);
            $table->decimal('unit_value', 14, 6);
            $table->decimal('unit_price_with_igv', 14, 6);
            $table->decimal('line_base', 14, 2);
            $table->decimal('igv', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->timestamps();

            $table->unique(['credit_note_id', 'invoice_draft_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_items');
        Schema::dropIfExists('credit_notes');

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('default_credit_note_series');
        });
    }
};
