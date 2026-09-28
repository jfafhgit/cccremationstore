<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff logins used to belong to exactly one store. They now join any number
 * of locations through store_memberships, with a role per location, while
 * keeping a single email and password.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('staff');
            $table->timestamps();

            $table->unique(['store_id', 'store_user_id']);
        });

        DB::table('store_memberships')->insertUsing(
            ['store_id', 'store_user_id', 'role', 'created_at', 'updated_at'],
            DB::table('store_users')->select('store_id', 'id', 'role', 'created_at', 'updated_at'),
        );

        Schema::table('store_users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('store_id');
            $table->dropColumn('role');
        });
    }

    public function down(): void
    {
        Schema::table('store_users', function (Blueprint $table): void {
            $table->foreignId('store_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('staff')->after('password');
        });

        // A login can only keep one location again; its earliest one wins.
        DB::table('store_memberships')->orderBy('id')->get()
            ->unique('store_user_id')
            ->each(fn (object $membership) => DB::table('store_users')
                ->where('id', $membership->store_user_id)
                ->update(['store_id' => $membership->store_id, 'role' => $membership->role]));

        Schema::dropIfExists('store_memberships');
    }
};
