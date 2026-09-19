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
        Schema::create('wise_accounts', function (Blueprint $table) {
            $table->integer('id', true, false);
            $table->integer('org_id');
            $table->integer('user_id');
            $table->string('recipient_id', 64)->nullable();
            $table->string('account_holder', 160);
            $table->string('email', 190)->nullable();
            $table->string('account_summary', 160)->nullable();
            $table->string('source_currency', 8)->default('USD');
            $table->string('target_currency', 8)->default('USD');
            $table->string('recipient_type', 16)->default('PERSON');
            $table->string('source_label', 40)->default('source');
            $table->string('reference', 80)->nullable();
            $table->boolean('active')->default(1);
            $table->text('note')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->nullable();

            $table->index(['org_id'], 'org_id');
            $table->index(['recipient_id'], 'recipient_id');
            $table->unique(['user_id'], 'uniq_wise_user');

            $table->foreign(['org_id'], 'wise_accounts_ibfk_1')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign(['user_id'], 'wise_accounts_ibfk_2')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wise_accounts');
    }
};
