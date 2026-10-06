<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * For a choose-one item a package includes: the option it covers, which
     * the family can upgrade from but never below.
     */
    public function up(): void
    {
        Schema::table('package_included_products', function (Blueprint $table): void {
            $table->foreignId('included_variant_id')->nullable()->after('included_quantity')->constrained('product_variants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('package_included_products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('included_variant_id');
        });
    }
};
