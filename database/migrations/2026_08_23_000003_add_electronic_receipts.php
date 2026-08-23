<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('default_boleta_series', 8)->default('B001')->after('default_series');
            $table->string('default_boleta_credit_note_series', 8)->default('BC01')->after('default_credit_note_series');
        });

        Schema::table('invoice_drafts', function (Blueprint $table): void {
            $table->string('document_type', 2)->default('01')->after('company_id');
            $table->string('customer_document_type', 2)->default('6')->after('customer_ruc');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('document_type', 2)->default('01')->after('sunat_environment');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->string('document_type', 2)->default('6')->after('company_id');
            $table->unique(['company_id', 'document_type', 'ruc'], 'customers_company_document_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropUnique('customers_company_document_number_unique');
            $table->dropColumn('document_type');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn('document_type');
        });

        Schema::table('invoice_drafts', function (Blueprint $table): void {
            $table->dropColumn(['document_type', 'customer_document_type']);
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn(['default_boleta_series', 'default_boleta_credit_note_series']);
        });
    }
};
