<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether the "family provided" urn carries a charge (for the care,
     * processing, and placement of the cremains into it), and how much.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->boolean('charges_for_family_provided_urn')->default(false)->after('offers_family_provided_urn');
            $table->unsignedInteger('family_provided_urn_price_cents')->default(0)->after('charges_for_family_provided_urn');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropColumn(['charges_for_family_provided_urn', 'family_provided_urn_price_cents']);
        });
    }
};
