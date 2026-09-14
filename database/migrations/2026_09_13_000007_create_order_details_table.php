<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_details', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('date_of_birth')->nullable();
            $table->date('date_of_death')->nullable();
            $table->string('place_of_death')->nullable();
            $table->boolean('veteran_status')->nullable();
            $table->string('marital_status')->nullable();
            $table->text('obituary_text')->nullable();
            $table->text('service_preferences')->nullable();
            $table->text('additional_notes')->nullable();
            $table->json('answers')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_details');
    }
};
