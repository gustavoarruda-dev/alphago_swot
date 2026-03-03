<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swot_analyses', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('customer_uuid', 64)->index();
            $table->string('trend_analysis_run_id', 128)->nullable()->index();
            $table->string('status', 32)->default('generated')->index();
            $table->string('analysis_title', 255)->default('Análise SWOT');
            $table->text('analysis_summary')->nullable();
            $table->string('brain_conversation_id', 128)->nullable()->index();
            $table->jsonb('filters')->nullable();
            $table->jsonb('raw_ai_payload')->nullable();
            $table->timestampTz('generated_at')->nullable()->index();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['customer_uuid', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swot_analyses');
    }
};
