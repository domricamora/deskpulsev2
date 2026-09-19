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
        Schema::create('remote_sessions', function (Blueprint $table) {
            $table->integer('id', true, false);
            $table->integer('device_id');
            $table->integer('user_id');
            $table->integer('admin_user_id');
            $table->integer('org_id');
            $table->string('token', 48);
            $table->string('status', 16)->default('pending');
            $table->string('end_reason', 32)->nullable();
            $table->integer('screen_w')->nullable();
            $table->integer('screen_h')->nullable();
            $table->integer('frame_seq')->default(0);
            $table->dateTime('last_frame_at')->nullable();
            $table->dateTime('last_input_at')->nullable();
            $table->dateTime('started_at')->useCurrent();
            $table->dateTime('activated_at')->nullable();
            $table->dateTime('ended_at')->nullable();

            $table->index(['device_id'], 'device_id');
            $table->index(['org_id'], 'org_id');
            $table->index(['status'], 'status');

            $table->foreign(['device_id'], 'remote_sessions_ibfk_1')->references('id')->on('devices')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remote_sessions');
    }
};
