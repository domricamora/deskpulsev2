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
        Schema::create('password_resets', function (Blueprint $table) {
            $table->integer('id', true, false);
            $table->integer('user_id');
            $table->char('token_hash', 64);
            $table->string('requested_ip', 45)->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();

            $table->index(['expires_at'], 'expires_at');
            $table->unique(['token_hash'], 'uq_token');
            $table->index(['user_id'], 'user_id');

            $table->foreign(['user_id'], 'password_resets_ibfk_1')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_resets');
    }
};
