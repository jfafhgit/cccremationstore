<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            // The platform's own Stripe Billing relationship with the funeral
            // home (on the platform account — unrelated to stripe_account_id,
            // which is the store's connected account for customer payments).
            $table->unsignedInteger('subscription_monthly_cents')->default(0)->after('platform_fee_flat_cents');
            $table->string('stripe_customer_id')->nullable()->after('subscription_monthly_cents');
            $table->string('stripe_subscription_id')->nullable()->index()->after('stripe_customer_id');
            // Stripe's subscription status as last synced: active, past_due, canceled, …
            $table->string('subscription_status')->nullable()->after('stripe_subscription_id');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropColumn(['subscription_monthly_cents', 'stripe_customer_id', 'stripe_subscription_id', 'subscription_status']);
        });
    }
};
