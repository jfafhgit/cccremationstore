<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            // Optional surcharge to help the store cover card processing
            // costs, added to the checkout total on top of subtotal + tax.
            // 350 = 3.5%, matching the rate stores most commonly use.
            $table->boolean('processing_fee_enabled')->default(false)->after('tax_rate_bps');
            $table->unsignedInteger('processing_fee_bps')->default(350)->after('processing_fee_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropColumn(['processing_fee_enabled', 'processing_fee_bps']);
        });
    }
};
