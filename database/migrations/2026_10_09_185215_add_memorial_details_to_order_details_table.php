<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The family's answers to the Memorial Story questions (see
     * OrderDetail::MEMORIAL_QUESTIONS), used to write the memorial story.
     */
    public function up(): void
    {
        Schema::table('order_details', function (Blueprint $table): void {
            $table->json('memorial_details')->nullable()->after('obituary_text');
        });
    }

    public function down(): void
    {
        Schema::table('order_details', function (Blueprint $table): void {
            $table->dropColumn('memorial_details');
        });
    }
};
