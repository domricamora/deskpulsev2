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
        Schema::create('sessions', function (Blueprint $table) {
            $table->integer('id', true, false);
            $table->integer('user_id');
            $table->integer('project_id')->nullable();
            $table->integer('task_id')->nullable();
            $table->integer('device_id')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->integer('active_s')->default(0);
            $table->integer('inactive_s')->default(0);
            $table->string('source', 16)->default('agent');
            $table->text('note')->nullable();
            $table->string('approval_status', 16)->default('approved');
            $table->integer('reviewed_by_id')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->integer('client_id')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->integer('overtime_s')->default(0);
            $table->string('overtime_status', 16)->default('none');
            $table->integer('overtime_reviewed_by_id')->nullable();
            $table->dateTime('overtime_reviewed_at')->nullable();
            $table->text('overtime_review_note')->nullable();
            $table->boolean('overtime_computed')->default(0);

            $table->index(['approval_status'], 'approval_status');
            $table->index(['device_id'], 'device_id');
            $table->index(['project_id'], 'project_id');
            $table->index(['started_at'], 'started_at');
            $table->index(['task_id'], 'task_id');
            $table->index(['user_id'], 'user_id');

            $table->foreign(['user_id'], 'sessions_ibfk_1')->references('id')->on('users')->onDelete('cascade');
            $table->foreign(['project_id'], 'sessions_ibfk_2')->references('id')->on('projects')->onDelete('set null');
            $table->foreign(['task_id'], 'sessions_ibfk_3')->references('id')->on('tasks')->onDelete('set null');
            $table->foreign(['device_id'], 'sessions_ibfk_4')->references('id')->on('devices')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
