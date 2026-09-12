<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_drafts', function (Blueprint $table): void {
            $table->string('customer_ruc', 11)->nullable()->change();
            $table->string('customer_name')->nullable()->change();
            $table->string('customer_document_type', 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_drafts', function (Blueprint $table): void {
            $table->string('customer_ruc', 11)->nullable(false)->change();
            $table->string('customer_name')->nullable(false)->change();
            $table->string('customer_document_type', 2)->nullable(false)->change();
        });
    }
};
