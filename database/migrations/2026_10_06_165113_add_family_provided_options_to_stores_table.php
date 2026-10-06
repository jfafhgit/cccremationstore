<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether the storefront offers a "family provided" cremation container
     * / urn as the last option in its group.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->boolean('offers_family_provided_container')->default(false)->after('preselect_urn_vault');
            $table->boolean('offers_family_provided_urn')->default(false)->after('offers_family_provided_container');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropColumn(['offers_family_provided_container', 'offers_family_provided_urn']);
        });
    }
};
