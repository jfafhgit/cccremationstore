<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stores with paid orders are archived (soft deleted) rather than
     * removed, so their order and payment records are kept.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
