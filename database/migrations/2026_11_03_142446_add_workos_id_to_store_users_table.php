<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff can sign in through WorkOS as well as with a password. The WorkOS
     * user id is stored the first time they do, so later sign-ins match on it
     * rather than on email. It is the same id across every Treasured Memories
     * site that shares the WorkOS environment.
     */
    public function up(): void
    {
        Schema::table('store_users', function (Blueprint $table) {
            $table->string('workos_id')->nullable()->unique()->after('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('store_users', function (Blueprint $table) {
            $table->dropUnique(['workos_id']);
            $table->dropColumn('workos_id');
        });
    }
};
