<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            // How the platform is paid by this store (see App\Enums\PlatformFeeModel).
            // Existing stores keep their current percentage (platform_fee_bps).
            $table->string('platform_fee_model')->default('percentage')->after('platform_fee_bps');
            $table->unsignedInteger('platform_fee_flat_cents')->default(0)->after('platform_fee_model');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropColumn(['platform_fee_model', 'platform_fee_flat_cents']);
        });
    }
};
