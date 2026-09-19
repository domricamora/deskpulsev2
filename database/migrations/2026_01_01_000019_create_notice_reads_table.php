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
        Schema::create('notice_reads', function (Blueprint $table) {
            $table->integer('id', true, false);
            $table->integer('notice_id');
            $table->integer('user_id');
            $table->dateTime('dismissed_at')->useCurrent();

            $table->unique(['notice_id', 'user_id'], 'uniq_notice_user');
            $table->index(['user_id'], 'user_id');

            $table->foreign(['notice_id'], 'notice_reads_ibfk_1')->references('id')->on('notices')->onDelete('cascade');
            $table->foreign(['user_id'], 'notice_reads_ibfk_2')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notice_reads');
    }
};
