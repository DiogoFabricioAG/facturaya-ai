<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sunat_taxpayers', function (Blueprint $table): void {
            $table->string('ruc', 11)->primary();
            $table->string('legal_name');
            $table->string('status', 60)->nullable();
            $table->string('condition', 60)->nullable();
            $table->string('ubigeo', 6)->nullable();
            $table->text('fiscal_address')->nullable();
            $table->string('provider', 40)->nullable();
            $table->date('as_of')->nullable();
            $table->timestampTz('synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sunat_taxpayers');
    }
};
