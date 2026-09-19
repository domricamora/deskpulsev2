<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generated from the live DeskPulse schema, which is the source of truth —
 * server/schema.sql covers only 30 of the 37 tables.
 *
 * Column types are reproduced as they exist today, including `double` money
 * columns (decision D1: migrate as-is so report parity can be proven, convert
 * later under test).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->integer('id', true, false);
            $table->integer('org_id');
            $table->string('name', 120);
            $table->string('email', 190);
            $table->string('password_hash', 255);
            $table->string('role', 16)->default('member');
            $table->string('pay_type', 16)->default('hourly');
            $table->double('pay_rate')->default(0);
            $table->string('bill_type', 16)->default('hourly');
            $table->double('bill_rate')->default(0);
            $table->string('currency', 8)->default('USD');
            $table->dateTime('created_at')->useCurrent();
            $table->string('phone', 40)->nullable();
            $table->string('job_title', 120)->nullable();
            $table->time('work_start')->nullable();
            $table->time('work_end')->nullable();
            $table->string('work_days', 20)->default('1,2,3,4,5');
            $table->boolean('must_change_password')->default(0);
            $table->dateTime('welcomed_at')->nullable();
            $table->string('external_ref', 40)->nullable();
            $table->string('wise_id', 64)->nullable();
            $table->string('wise_name', 160)->nullable();
            $table->string('employment_type', 16)->default('full_time');
            $table->decimal('daily_hours_cap', 5, 2)->default(0.00);
            $table->decimal('weekly_hours_cap', 6, 2)->default(0.00);
            $table->decimal('period_hours_cap', 7, 2)->default(0.00);
            $table->boolean('email_opt_out')->default(0);
            $table->date('hired_on')->nullable();

            $table->unique(['email'], 'email');
            $table->index(['org_id', 'external_ref'], 'idx_users_external_ref');
            $table->index(['org_id'], 'org_id');

            $table->foreign(['org_id'], 'users_ibfk_1')->references('id')->on('organizations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
