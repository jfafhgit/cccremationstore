<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a staff login is emailed about this location's orders (new
     * purchases, Vital Statistics, refunds). Off still leaves them able to
     * see every order in the portal.
     */
    public function up(): void
    {
        Schema::table('store_memberships', function (Blueprint $table): void {
            $table->boolean('receives_order_emails')->default(true)->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('store_memberships', function (Blueprint $table): void {
            $table->dropColumn('receives_order_emails');
        });
    }
};
