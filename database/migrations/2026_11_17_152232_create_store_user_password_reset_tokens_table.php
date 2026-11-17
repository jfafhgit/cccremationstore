<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Password reset tokens for store staff, kept apart from the admin users'
     * table so the same email on both kinds of login can never share a token.
     */
    public function up(): void
    {
        Schema::create('store_user_password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_user_password_reset_tokens');
    }
};
