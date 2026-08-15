<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('ruc', 11)->unique();
            $table->string('legal_name');
            $table->string('trade_name')->nullable();
            $table->string('ubigeo', 6);
            $table->string('department', 100);
            $table->string('province', 100);
            $table->string('district', 100);
            $table->string('address', 500);
            $table->string('sunat_driver', 16)->default('fake');
            $table->string('sunat_environment', 16)->default('beta');
            $table->text('sol_user')->nullable();
            $table->text('sol_password')->nullable();
            $table->string('certificate_path')->nullable();
            $table->string('default_series', 8)->default('F001');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('company_api_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('token_hash', 64)->unique();
            $table->string('token_hint', 16);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('company_invoice_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('series', 8);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();

            $table->unique(['company_id', 'series']);
        });

        Schema::table('invoice_drafts', function (Blueprint $table): void {
            $table->foreignUlid('company_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->index(['company_id', 'created_at']);
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->foreignUlid('company_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->index(['company_id', 'created_at']);
            $table->dropUnique(['series', 'correlative']);
            $table->unique(['company_id', 'series', 'correlative']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'series', 'correlative']);
            $table->unique(['series', 'correlative']);
            $table->dropConstrainedForeignId('company_id');
        });

        Schema::table('invoice_drafts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('company_id');
        });

        Schema::dropIfExists('company_invoice_sequences');
        Schema::dropIfExists('company_api_tokens');
        Schema::dropIfExists('companies');
    }
};
