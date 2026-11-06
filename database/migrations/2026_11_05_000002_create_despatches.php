<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->text('gre_client_id')->nullable();
            $table->text('gre_client_secret')->nullable();
            $table->boolean('gre_enabled')->default(false);
            $table->unsignedInteger('gre_revision')->default(0);
        });
        Schema::create('despatches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained();
            $table->foreignUlid('invoice_id')->constrained();
            $table->string('environment', 16);
            $table->string('driver', 16);
            $table->unsignedInteger('fiscal_revision');
            $table->unsignedInteger('gre_revision');
            $table->string('idempotency_key', 120);
            $table->string('confirmation_digest', 64);
            $table->json('payload');
            $table->string('status', 24)->default('draft');
            $table->string('series', 4)->default('T001');
            $table->unsignedBigInteger('correlative')->nullable();
            $table->string('ticket')->nullable();
            $table->string('xml_path')->nullable();
            $table->string('cdr_path')->nullable();
            $table->string('response_code', 20)->nullable();
            $table->string('message', 500)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'idempotency_key']);
            $table->unique(['company_id', 'environment', 'series', 'correlative']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('despatches');
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['gre_client_id', 'gre_client_secret', 'gre_enabled', 'gre_revision']));
    }
};
