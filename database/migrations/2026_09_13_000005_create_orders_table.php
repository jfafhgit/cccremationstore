<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('order_number')->unique();
            $table->string('status')->default('pending_payment');
            $table->string('source')->default('storefront');
            $table->string('timing')->nullable();

            // Minimal purchaser + deceased details captured before payment.
            $table->string('purchaser_first_name')->nullable();
            $table->string('purchaser_last_name')->nullable();
            $table->string('purchaser_email')->nullable();
            $table->string('purchaser_phone')->nullable();
            $table->string('relationship_to_deceased')->nullable();
            $table->string('deceased_first_name')->nullable();
            $table->string('deceased_middle_name')->nullable();
            $table->string('deceased_last_name')->nullable();
            $table->string('deceased_suffix')->nullable();

            $table->string('currency', 3)->default('usd');
            $table->unsignedInteger('subtotal_cents')->default(0);
            $table->unsignedInteger('platform_fee_cents')->default(0);
            $table->unsignedInteger('total_cents')->default(0);

            $table->string('stripe_payment_intent_id')->nullable()->index();
            $table->string('stripe_account_id')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->text('internal_notes')->nullable();

            $table->timestamps();

            $table->index(['store_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
