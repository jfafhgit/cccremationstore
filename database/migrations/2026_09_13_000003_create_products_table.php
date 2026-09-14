<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('category');
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->unsignedInteger('price_cents')->default(0);
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('allow_multiple_quantity')->default(false);
            $table->boolean('requires_engraving')->default(false);
            $table->boolean('available_for_immediate')->default(true);
            $table->boolean('available_for_pre_need')->default(true);
            $table->timestamps();

            $table->unique(['store_id', 'slug']);
            $table->index(['store_id', 'category', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
