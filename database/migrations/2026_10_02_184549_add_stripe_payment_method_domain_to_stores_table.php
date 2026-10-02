<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            // The storefront domain last registered with the store's Stripe
            // account, which Apple Pay and Google Pay require.
            $table->string('stripe_payment_method_domain')->nullable()->after('stripe_payouts_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropColumn('stripe_payment_method_domain');
        });
    }
};
