<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add-ons set up with options become "choose-one" items, which now have
     * their own category.
     */
    public function up(): void
    {
        DB::table('products')
            ->where('category', 'addon')
            ->whereIn('id', DB::table('product_variants')->select('product_id'))
            ->update(['category' => 'choice']);
    }

    public function down(): void
    {
        DB::table('products')->where('category', 'choice')->update(['category' => 'addon']);
    }
};
