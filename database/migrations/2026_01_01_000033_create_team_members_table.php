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
        Schema::create('team_members', function (Blueprint $table) {
            $table->integer('id', true, false);
            $table->integer('team_id');
            $table->integer('user_id');

            $table->unique(['team_id', 'user_id'], 'uniq_team_user');
            $table->index(['user_id'], 'user_id');

            $table->foreign(['team_id'], 'team_members_ibfk_1')->references('id')->on('teams')->onDelete('cascade');
            $table->foreign(['user_id'], 'team_members_ibfk_2')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_members');
    }
};
