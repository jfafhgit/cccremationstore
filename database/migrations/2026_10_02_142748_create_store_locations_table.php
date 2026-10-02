<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The cities a store serves, for location-based package pricing.
        Schema::create('store_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('state', 2);
            $table->string('city');
            $table->timestamps();

            $table->unique(['store_id', 'state', 'city']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_locations');
    }
};
