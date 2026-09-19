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
        Schema::create('share_links', function (Blueprint $table) {
            $table->integer('id', true, false);
            $table->integer('org_id');
            $table->string('scope', 16)->default('user');
            $table->integer('target_id')->nullable();
            $table->string('token', 48);
            $table->string('label', 160)->default('');
            $table->string('period_default', 16)->default('week');
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('expires_at')->nullable();
            $table->boolean('revoked')->default(0);

            $table->index(['org_id'], 'org_id');
            $table->unique(['token'], 'token');

            $table->foreign(['org_id'], 'share_links_ibfk_1')->references('id')->on('organizations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_links');
    }
};
