<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A store is entirely pre-need or at-need, and the at-need timing answer
     * is only information for staff, so every active product is offered
     * whatever the family answers.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['available_for_immediate', 'available_for_imminent']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('available_for_immediate')->default(true);
            $table->boolean('available_for_imminent')->default(true);
        });
    }
};
