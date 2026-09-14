<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            // Snapshotted from the product at order time, like the other
            // *_snapshot columns, so a later change to a product's taxable
            // flag never rewrites the tax on an already-placed order.
            $table->boolean('is_taxable_snapshot')->default(true)->after('category_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('is_taxable_snapshot');
        });
    }
};
