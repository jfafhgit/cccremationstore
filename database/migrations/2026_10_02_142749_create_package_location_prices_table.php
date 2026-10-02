<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A package's price in each city; no row means it isn't offered there.
        Schema::create('package_location_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_location_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('price_cents');
            $table->timestamps();

            $table->unique(['product_id', 'store_location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_location_prices');
    }
};
