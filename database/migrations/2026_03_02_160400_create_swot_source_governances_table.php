<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swot_source_governances', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('analysis_id')->nullable()->constrained('swot_analyses')->nullOnDelete();
            $table->string('customer_uuid', 64)->index();
            $table->string('analysis_run_id', 128)->nullable()->index();
            $table->string('source_name', 255);
            $table->string('source_key', 255);
            $table->string('source_origin', 64)->default('internal');
            $table->string('source_url', 5000)->nullable();
            $table->string('source_category', 128)->nullable();
            $table->string('status', 32)->default('pending')->index();
            $table->boolean('is_priority')->default(false)->index();
            $table->jsonb('extra_metadata')->nullable();
            $table->timestampTz('last_seen_at')->nullable()->index();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['customer_uuid', 'source_origin', 'source_key'], 'swot_source_unique_customer_origin_key');
            $table->index(['customer_uuid', 'analysis_run_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swot_source_governances');
    }
};
