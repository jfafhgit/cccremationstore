<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('draft');
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('timezone')->default('America/New_York');
            $table->string('brand_primary_color')->nullable();
            $table->string('brand_logo_path')->nullable();
            $table->string('general_price_list_url')->nullable();

            // Stripe Connect (each funeral home is its own connected account).
            $table->string('stripe_account_id')->nullable()->index();
            $table->boolean('stripe_details_submitted')->default(false);
            $table->boolean('stripe_charges_enabled')->default(false);
            $table->boolean('stripe_payouts_enabled')->default(false);

            // Platform fee taken on each order, in basis points (500 = 5%).
            $table->unsignedInteger('platform_fee_bps')->default(500);

            $table->json('settings')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
