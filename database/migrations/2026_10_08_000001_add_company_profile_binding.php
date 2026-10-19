<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->unsignedInteger('profile_version')->default(1)->after('sunat_environment');
            $table->unsignedInteger('fiscal_revision')->default(0)->after('profile_version');
            $table->timestamp('profile_updated_at')->nullable()->after('fiscal_revision');
        });

        Schema::create('company_profile_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('profile_version');
            $table->string('fiscal_digest', 64);
            $table->string('certificate_sha256', 64);
            $table->string('environment', 16);
            $table->string('ruc', 11);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['company_id', 'profile_version']);
        });

        Schema::create('company_profile_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->uuid('tenant_id');
            $table->string('environment', 16);
            $table->unsignedInteger('config_version');
            $table->unsignedInteger('bound_profile_version');
            $table->string('bound_digest', 64);
            $table->timestamp('bound_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'environment']);
        });

        Schema::create('company_profile_sync_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 128);
            $table->string('request_digest', 64);
            $table->string('fiscal_digest', 64);
            $table->unsignedInteger('profile_version');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['company_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_profile_sync_receipts');
        Schema::dropIfExists('company_profile_bindings');
        Schema::dropIfExists('company_profile_versions');

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn(['profile_version', 'fiscal_revision', 'profile_updated_at']);
        });
    }
};
