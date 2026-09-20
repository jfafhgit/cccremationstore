<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            // "packages" = customer picks a package first; "a_la_carte" = a
            // single base package is applied automatically and everything
            // else is chosen à la carte.
            $table->string('checkout_path')->default('packages')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropColumn('checkout_path');
        });
    }
};
