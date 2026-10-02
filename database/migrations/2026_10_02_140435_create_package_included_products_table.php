<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_included_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('package_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            // How many of the product the package covers; the customer pays only for any beyond this.
            $table->unsignedInteger('included_quantity')->default(1);
            $table->timestamps();

            $table->unique(['package_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_included_products');
    }
};
