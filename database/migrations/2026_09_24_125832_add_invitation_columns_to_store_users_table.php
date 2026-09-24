<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff logins are now invited by email and choose their own password,
     * instead of an admin handing them a temporary one. Only a hash of the
     * invitation token is stored; sending a new invitation replaces it, so
     * links sent before it stop working.
     */
    public function up(): void
    {
        Schema::table('store_users', function (Blueprint $table) {
            $table->string('invitation_token', 64)->nullable()->after('role');
            $table->timestamp('invited_at')->nullable()->after('invitation_token');
            $table->timestamp('invitation_accepted_at')->nullable()->after('invited_at');
        });

        // Existing staff already sign in with a password they were given.
        DB::table('store_users')->update(['invitation_accepted_at' => DB::raw('created_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('store_users', function (Blueprint $table) {
            $table->dropColumn(['invitation_token', 'invited_at', 'invitation_accepted_at']);
        });
    }
};
