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
        Schema::create('projects', function (Blueprint $table) {
            $table->integer('id', true, false);
            $table->integer('org_id');
            $table->integer('client_id')->nullable();
            $table->string('name', 160);
            $table->string('client', 160)->default('');
            $table->boolean('billable')->default(1);
            $table->double('hourly_rate')->default(0);
            $table->boolean('archived')->default(0);
            $table->dateTime('created_at')->useCurrent();

            $table->index(['client_id'], 'client_id');
            $table->index(['org_id'], 'org_id');

            $table->foreign(['org_id'], 'projects_ibfk_1')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign(['client_id'], 'projects_ibfk_2')->references('id')->on('clients')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
