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
        Schema::create('tasks', function (Blueprint $table) {
            $table->integer('id', true, false);
            $table->integer('org_id');
            $table->integer('user_id');
            $table->integer('project_id')->nullable();
            $table->string('title', 240);
            $table->string('status', 16)->default('open');
            $table->dateTime('created_at')->useCurrent();
            $table->integer('client_id')->nullable();

            $table->index(['org_id'], 'org_id');
            $table->index(['project_id'], 'project_id');
            $table->index(['user_id'], 'user_id');

            $table->foreign(['org_id'], 'tasks_ibfk_1')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign(['user_id'], 'tasks_ibfk_2')->references('id')->on('users')->onDelete('cascade');
            $table->foreign(['project_id'], 'tasks_ibfk_3')->references('id')->on('projects')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
