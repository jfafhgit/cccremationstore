<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            // Whether the storefront must collect a container/urn selection
            // before checkout can proceed. Independent flags because a store
            // may sell containers without requiring one (e.g. direct
            // cremation) while still requiring an urn, or vice versa.
            $table->boolean('requires_container')->default(false)->after('checkout_path');
            $table->boolean('requires_urn')->default(false)->after('requires_container');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropColumn(['requires_container', 'requires_urn']);
        });
    }
};
