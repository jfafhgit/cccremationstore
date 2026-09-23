<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            // Path of the uploaded General Price List PDF on the public disk.
            $table->string('general_price_list_path')->nullable()->after('brand_logo_path');
            $table->dropColumn('general_price_list_url');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->string('general_price_list_url')->nullable();
            $table->dropColumn('general_price_list_path');
        });
    }
};
