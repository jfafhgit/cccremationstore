<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turns the follow-up details form into the Vital Statistics form: the
 * information a funeral home needs for the death certificate. The Social
 * Security number is stored encrypted (see OrderDetail's casts), so its
 * column is text rather than a short string.
 */
return new class extends Migration
{
    /**
     * The old free-text marital statuses and the MaritalStatus values they become.
     *
     * @var array<string, string>
     */
    private const MARITAL_STATUSES = [
        'Single' => 'never_married',
        'Married' => 'married',
        'Separated' => 'married_but_separated',
        'Widowed' => 'widowed',
        'Divorced' => 'divorced',
    ];

    public function up(): void
    {
        Schema::table('order_details', function (Blueprint $table): void {
            $table->string('sex')->nullable()->after('order_id');
            $table->string('maiden_name')->nullable()->after('sex');
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('address_city')->nullable();
            $table->string('address_state', 2)->nullable();
            $table->string('address_zip', 10)->nullable();
            $table->string('phone', 30)->nullable();
            $table->boolean('born_outside_us')->default(false);
            $table->string('birth_city')->nullable();
            $table->string('birth_state', 2)->nullable();
            $table->string('birth_place_outside_us')->nullable();
            $table->string('citizenship')->nullable();
            $table->text('ssn')->nullable();
            $table->string('race')->nullable();
            $table->boolean('hispanic_origin')->nullable();
            $table->unsignedTinyInteger('education_years')->nullable();
            $table->string('highest_degree')->nullable();
            $table->string('occupation')->nullable();
            $table->string('industry')->nullable();
            $table->boolean('has_pacemaker')->nullable();
            $table->string('spouse_first_name')->nullable();
            $table->string('spouse_middle_name')->nullable();
            $table->string('spouse_last_name')->nullable();
            $table->string('spouse_maiden_name')->nullable();
            $table->string('mother_first_name')->nullable();
            $table->string('mother_maiden_name')->nullable();
            $table->boolean('mother_living')->nullable();
            $table->string('father_first_name')->nullable();
            $table->string('father_last_name')->nullable();
            $table->boolean('father_living')->nullable();
            $table->string('veteran_branch')->nullable();
            $table->string('next_of_kin_name')->nullable();
            $table->string('next_of_kin_relationship')->nullable();
            $table->string('next_of_kin_phone', 30)->nullable();
            $table->string('next_of_kin_email')->nullable();
        });

        foreach (self::MARITAL_STATUSES as $old => $new) {
            DB::table('order_details')->where('marital_status', $old)->update(['marital_status' => $new]);
        }
    }

    public function down(): void
    {
        foreach (self::MARITAL_STATUSES as $old => $new) {
            DB::table('order_details')->where('marital_status', $new)->update(['marital_status' => $old]);
        }

        Schema::table('order_details', function (Blueprint $table): void {
            $table->dropColumn([
                'sex', 'maiden_name', 'address_line1', 'address_line2', 'address_city', 'address_state', 'address_zip',
                'phone', 'born_outside_us', 'birth_city', 'birth_state', 'birth_place_outside_us', 'citizenship', 'ssn',
                'race', 'hispanic_origin', 'education_years', 'highest_degree', 'occupation', 'industry', 'has_pacemaker',
                'spouse_first_name', 'spouse_middle_name', 'spouse_last_name', 'spouse_maiden_name',
                'mother_first_name', 'mother_maiden_name', 'mother_living', 'father_first_name', 'father_last_name',
                'father_living', 'veteran_branch', 'next_of_kin_name', 'next_of_kin_relationship', 'next_of_kin_phone',
                'next_of_kin_email',
            ]);
        });
    }
};
