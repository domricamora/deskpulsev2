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
        Schema::create('notices', function (Blueprint $table) {
            $table->integer('id', true, false);
            $table->integer('org_id');
            $table->string('title', 160);
            $table->text('body');
            $table->string('level', 12)->default('info');
            $table->string('audience', 16)->default('all');
            $table->string('audience_ref', 255)->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('emailed')->default(0);
            $table->integer('created_by_id')->nullable();
            $table->dateTime('created_at')->useCurrent();

            $table->index(['ends_at'], 'ends_at');
            $table->index(['org_id'], 'org_id');

            $table->foreign(['org_id'], 'notices_ibfk_1')->references('id')->on('organizations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notices');
    }
};
