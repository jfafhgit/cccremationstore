<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            // Sales tax rate applied to taxable line items, in basis points
            // (725 = 7.25%) — matches the platform_fee_bps convention.
            $table->unsignedInteger('tax_rate_bps')->default(0)->after('platform_fee_bps');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropColumn('tax_rate_bps');
        });
    }
};
