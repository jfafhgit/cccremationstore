<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The "Soon" choice was stored as pre_need, but it means death is imminent;
 * pre_need now belongs to orders from pre-need stores. Every store before
 * this was at-need, so all existing pre_need orders are really imminent.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('orders')->where('timing', 'pre_need')->update(['timing' => 'imminent']);

        Schema::table('products', function (Blueprint $table): void {
            $table->renameColumn('available_for_pre_need', 'available_for_imminent');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->renameColumn('available_for_imminent', 'available_for_pre_need');
        });

        DB::table('orders')->where('timing', 'imminent')->update(['timing' => 'pre_need']);
    }
};
