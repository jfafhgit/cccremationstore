<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Services and add-ons are now one "Add-ons & Services" category.
     */
    public function up(): void
    {
        DB::table('products')->where('category', 'service')->update(['category' => 'addon']);
    }

    /**
     * Not reversible: once merged, former services can't be told apart from add-ons.
     */
    public function down(): void
    {
        //
    }
};
