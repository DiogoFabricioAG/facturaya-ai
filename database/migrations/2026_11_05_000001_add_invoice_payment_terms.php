<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_drafts', function (Blueprint $table): void {
            // Existing documents retain their original contado semantics. Never rewrite issued XML.
            $table->string('payment_method', 16)->default('contado');
            $table->decimal('outstanding_amount', 14, 2)->nullable();
            $table->json('installments')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_drafts', fn (Blueprint $table) => $table->dropColumn(['payment_method', 'outstanding_amount', 'installments']));
    }
};
