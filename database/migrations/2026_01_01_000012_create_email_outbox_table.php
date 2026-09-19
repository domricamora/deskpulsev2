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
        Schema::create('email_outbox', function (Blueprint $table) {
            $table->integer('id', true, false);
            $table->integer('org_id')->nullable();
            $table->integer('user_id')->nullable();
            $table->string('to_email', 190);
            $table->string('to_name', 160)->nullable();
            $table->string('subject', 255);
            $table->mediumText('body_html')->nullable();
            $table->mediumText('body_text')->nullable();
            $table->text('attachments')->nullable();
            $table->string('kind', 24)->default('notice');
            $table->string('status', 12)->default('queued');
            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->integer('created_by_id')->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->index(['kind'], 'kind');
            $table->index(['org_id'], 'org_id');
            $table->index(['scheduled_at'], 'scheduled_at');
            $table->index(['status'], 'status');

            $table->foreign(['org_id'], 'email_outbox_ibfk_1')->references('id')->on('organizations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_outbox');
    }
};
