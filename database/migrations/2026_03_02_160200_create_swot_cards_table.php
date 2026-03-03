<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swot_cards', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('analysis_id')->constrained('swot_analyses')->cascadeOnDelete();
            $table->string('card_key', 128);
            $table->string('card_group', 64)->index();
            $table->string('title', 255);
            $table->string('subtitle', 255)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_editable')->default(true);
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['analysis_id', 'card_key']);
            $table->index(['analysis_id', 'card_group', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swot_cards');
    }
};
