<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The store's public email: the reply-to on family emails and where new
     * order alerts go. Starts as the contact email, which used to do both,
     * so existing stores keep getting their alerts.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->string('general_email')->nullable()->after('contact_phone');
        });

        DB::table('stores')->whereNotNull('contact_email')->update(['general_email' => DB::raw('contact_email')]);
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropColumn('general_email');
        });
    }
};
