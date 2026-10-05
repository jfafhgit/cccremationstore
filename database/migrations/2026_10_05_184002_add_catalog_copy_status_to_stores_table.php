<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A duplicated store's products and images are copied in the background;
     * this tracks that copy (null once it's done, or for stores never copied).
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->string('catalog_copy_status')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropColumn('catalog_copy_status');
        });
    }
};
