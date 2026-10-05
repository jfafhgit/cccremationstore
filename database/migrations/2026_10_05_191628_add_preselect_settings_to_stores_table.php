<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether the storefront pre-selects the first container / urn / urn
     * vault (in the store's sort order) for the family.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->boolean('preselect_container')->default(false)->after('requires_urn_vault');
            $table->boolean('preselect_urn')->default(false)->after('preselect_container');
            $table->boolean('preselect_urn_vault')->default(false)->after('preselect_urn');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropColumn(['preselect_container', 'preselect_urn', 'preselect_urn_vault']);
        });
    }
};
