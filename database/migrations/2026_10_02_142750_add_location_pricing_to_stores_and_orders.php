<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->boolean('location_pricing_enabled')->default(false)->after('requires_urn');
        });

        Schema::table('orders', function (Blueprint $table): void {
            // Snapshot of the city the family chose, for location-priced stores.
            $table->string('service_city')->nullable()->after('timing');
            $table->string('service_state', 2)->nullable()->after('service_city');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropColumn('location_pricing_enabled');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['service_city', 'service_state']);
        });
    }
};
