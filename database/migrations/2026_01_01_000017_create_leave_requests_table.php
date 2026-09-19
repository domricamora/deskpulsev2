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
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->integer('id', true, false);
            $table->integer('org_id');
            $table->integer('user_id');
            $table->integer('leave_type_id');
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('hours_per_day', 5, 2)->default(8.00);
            $table->decimal('total_days', 6, 2)->default(0.00);
            $table->decimal('total_hours', 7, 2)->default(0.00);
            $table->boolean('half_day')->default(0);
            $table->text('reason')->nullable();
            $table->string('status', 16)->default('pending');
            $table->integer('reviewed_by_id')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->index(['leave_type_id'], 'leave_type_id');
            $table->index(['org_id'], 'org_id');
            $table->index(['start_date'], 'start_date');
            $table->index(['status'], 'status');
            $table->index(['user_id'], 'user_id');

            $table->foreign(['org_id'], 'leave_requests_ibfk_1')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign(['user_id'], 'leave_requests_ibfk_2')->references('id')->on('users')->onDelete('cascade');
            $table->foreign(['leave_type_id'], 'leave_requests_ibfk_3')->references('id')->on('leave_types')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
