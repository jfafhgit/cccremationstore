<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Admin access is no longer granted to everyone who can sign in through
     * WorkOS. A user must be approved (or invited) by a super admin first.
     * Invited users have no WorkOS id until their first sign-in.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('workos_id')->nullable()->change();
            $table->timestamp('approved_at')->nullable()->after('workos_id');
            $table->boolean('is_super_admin')->default(false)->after('approved_at');
        });

        if ($superAdminEmail = config('services.platform.super_admin_email')) {
            DB::table('users')
                ->whereRaw('lower(email) = ?', [Str::lower($superAdminEmail)])
                ->update(['approved_at' => now(), 'is_super_admin' => true]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['approved_at', 'is_super_admin']);
            $table->string('workos_id')->nullable(false)->change();
        });
    }
};
