<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            // Dollar portion of the price that is taxable (packages bundle
            // taxable goods with non-taxable services). Null means the
            // is_taxable flag applies to the whole price, as before.
            $table->unsignedInteger('taxable_amount_cents')->nullable()->after('is_taxable');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            // Per-unit taxable cents at order time, snapshotted like
            // is_taxable_snapshot. Null on orders placed before this existed.
            $table->unsignedInteger('taxable_unit_cents_snapshot')->nullable()->after('is_taxable_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('taxable_amount_cents');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('taxable_unit_cents_snapshot');
        });
    }
};
