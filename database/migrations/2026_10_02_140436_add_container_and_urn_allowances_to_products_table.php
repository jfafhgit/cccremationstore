<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            // A package's credit toward the customer's chosen container / urn.
            $table->unsignedInteger('container_allowance_cents')->nullable()->after('taxable_amount_cents');
            $table->unsignedInteger('urn_allowance_cents')->nullable()->after('container_allowance_cents');
            // Hide containers / urns priced below the package's allowance for them.
            $table->boolean('hide_options_below_allowance')->default(false)->after('urn_allowance_cents');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['container_allowance_cents', 'urn_allowance_cents', 'hide_options_below_allowance']);
        });
    }
};
