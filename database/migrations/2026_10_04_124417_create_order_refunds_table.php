<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_refund_id')->unique();
            $table->unsignedInteger('amount_cents');
            // The platform's application fee returned to the store alongside this refund.
            $table->unsignedInteger('application_fee_refunded_cents')->default(0);
            $table->string('status');
            $table->text('reason')->nullable();
            // Null when the refund was issued from the store's own Stripe dashboard.
            $table->foreignId('refunded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_refunds');
    }
};
