<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            // Units covered by the package (not charged), and the package's
            // container/urn allowance applied to this line.
            $table->unsignedInteger('included_quantity')->default(0)->after('quantity');
            $table->unsignedInteger('allowance_cents')->default(0)->after('included_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn(['included_quantity', 'allowance_cents']);
        });
    }
};
