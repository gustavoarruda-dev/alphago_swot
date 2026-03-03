<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swot_card_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('card_id')->constrained('swot_cards')->cascadeOnDelete();
            $table->string('item_key', 128)->nullable();
            $table->text('title');
            $table->text('description')->nullable();
            $table->string('tag', 128)->nullable();
            $table->string('priority', 64)->nullable();
            $table->string('period', 64)->nullable();
            $table->string('kpi', 255)->nullable();
            $table->string('owner', 128)->nullable();
            $table->string('swot_link', 255)->nullable();
            $table->string('impact', 64)->nullable();
            $table->string('dimension', 128)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['card_id', 'sort_order']);
            $table->index(['card_id', 'item_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swot_card_items');
    }
};
