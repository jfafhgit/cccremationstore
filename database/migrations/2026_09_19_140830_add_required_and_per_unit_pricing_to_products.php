<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            // Pre-selected in every cart and cannot be removed by the customer.
            $table->boolean('is_required')->default(false)->after('is_taxable');
            // When set, price_cents is a one-time base fee and this is the
            // additional price per unit (e.g. $250 service + $15 per copy).
            $table->unsignedInteger('per_unit_price_cents')->nullable()->after('taxable_amount_cents');
            $table->string('per_unit_label')->nullable()->after('per_unit_price_cents');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            // One-time base fee for per-unit items; total = base + unit price x quantity.
            $table->unsignedInteger('base_price_cents_snapshot')->default(0)->after('unit_price_cents');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['is_required', 'per_unit_price_cents', 'per_unit_label']);
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('base_price_cents_snapshot');
        });
    }
};
