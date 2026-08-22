<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('sunat_environment', 16)->nullable()->after('company_id');
        });

        Schema::table('credit_notes', function (Blueprint $table): void {
            $table->string('sunat_environment', 16)->nullable()->after('company_id');
        });

        Schema::table('company_invoice_sequences', function (Blueprint $table): void {
            $table->string('sunat_environment', 16)->nullable()->after('company_id');
        });

        DB::table('companies')->select(['id', 'sunat_environment'])->orderBy('id')->each(function ($company): void {
            DB::table('invoices')
                ->where('company_id', $company->id)
                ->update(['sunat_environment' => $company->sunat_environment]);
            DB::table('credit_notes')
                ->where('company_id', $company->id)
                ->update(['sunat_environment' => $company->sunat_environment]);
            DB::table('company_invoice_sequences')
                ->where('company_id', $company->id)
                ->update(['sunat_environment' => $company->sunat_environment]);
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'series', 'correlative']);
            $table->unique(
                ['company_id', 'sunat_environment', 'series', 'correlative'],
                'invoices_company_environment_series_correlative_unique',
            );
        });

        Schema::table('credit_notes', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'series', 'correlative']);
            $table->unique(
                ['company_id', 'sunat_environment', 'series', 'correlative'],
                'credit_notes_company_environment_series_correlative_unique',
            );
        });

        Schema::table('company_invoice_sequences', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'series']);
            $table->unique(
                ['company_id', 'sunat_environment', 'series'],
                'company_invoice_sequences_environment_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('company_invoice_sequences', function (Blueprint $table): void {
            $table->dropUnique('company_invoice_sequences_environment_unique');
            $table->dropColumn('sunat_environment');
            $table->unique(['company_id', 'series']);
        });

        Schema::table('credit_notes', function (Blueprint $table): void {
            $table->dropUnique('credit_notes_company_environment_series_correlative_unique');
            $table->dropColumn('sunat_environment');
            $table->unique(['company_id', 'series', 'correlative']);
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropUnique('invoices_company_environment_series_correlative_unique');
            $table->dropColumn('sunat_environment');
            $table->unique(['company_id', 'series', 'correlative']);
        });
    }
};
