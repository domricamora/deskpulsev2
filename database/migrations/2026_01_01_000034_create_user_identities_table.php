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
        Schema::create('user_identities', function (Blueprint $table) {
            $table->integer('id', true, false);
            $table->integer('user_id');
            $table->string('provider', 32);
            $table->string('subject', 190);
            $table->string('email', 190)->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('last_login_at')->nullable();

            $table->unique(['provider', 'subject'], 'uq_provider_subject');
            $table->index(['user_id'], 'user_id');

            $table->foreign(['user_id'], 'user_identities_ibfk_1')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_identities');
    }
};
