<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Keepsake allowances were add-ons with an allowance amount; they now
     * have their own category.
     */
    public function up(): void
    {
        DB::table('products')
            ->where('category', 'addon')
            ->whereNotNull('keepsake_allowance_cents')
            ->update(['category' => 'keepsake_allowance', 'is_required' => false, 'allow_multiple_quantity' => false]);
    }

    public function down(): void
    {
        DB::table('products')->where('category', 'keepsake_allowance')->update(['category' => 'addon']);
    }
};
