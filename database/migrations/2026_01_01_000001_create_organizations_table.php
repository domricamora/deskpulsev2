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
        Schema::create('organizations', function (Blueprint $table) {
            $table->integer('id', true, false);
            $table->string('name', 160);
            $table->dateTime('created_at')->useCurrent();
            $table->integer('screenshot_interval_min')->default(10);
            $table->boolean('screenshot_blur')->default(0);
            $table->integer('idle_threshold_min')->default(15);
            $table->boolean('track_screenshots')->default(1);
            $table->boolean('track_windows')->default(1);
            $table->boolean('track_processes')->default(1);
            $table->string('status', 16)->default('approved');
            $table->string('billing_status', 16)->default('none');
            $table->double('monthly_fee')->default(0);
            $table->string('billing_currency', 8)->default('USD');
            $table->dateTime('billing_started_at')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->integer('sync_interval_s')->default(60);
            $table->double('seat_rate')->default(0);
            $table->integer('screenshot_retention_days')->default(30);
            $table->dateTime('onboarded_at')->nullable();
            $table->string('logo_path', 400)->nullable();
            $table->string('plan_type', 16)->default('organization');
            $table->decimal('discount_pct', 5, 2)->default(0.00);
            $table->decimal('price_individual', 10, 2)->default(9.00);
            $table->decimal('price_organization', 10, 2)->default(49.00);
            $table->integer('seats_min')->default(2);
            $table->integer('seats_max')->default(50);
            $table->string('subscription_status', 16)->default('none');
            $table->dateTime('trial_ends_at')->nullable();
            $table->dateTime('current_period_end')->nullable();
            $table->string('pay_reference', 24)->nullable();
            $table->string('promo_code', 40)->nullable();
            $table->integer('trial_days')->default(14);
            $table->double('price_per_seat')->default(0);
            $table->string('billing_period_unit', 8)->default('month');
            $table->integer('billing_period_count')->default(1);
            $table->string('report_tz', 64)->default('UTC');
            $table->tinyInteger('week_start')->default(1);
            $table->string('pay_cycle', 16)->default('semimonthly');
            $table->date('pay_cycle_anchor')->nullable();
            $table->string('pay_currency', 8)->default('USD');
            $table->string('mail_from', 190)->nullable();
            $table->string('mail_from_name', 120)->nullable();
            $table->string('mail_reply_to', 190)->nullable();
            $table->string('mail_notify', 190)->nullable();
            $table->string('mail_transport', 8)->nullable();
            $table->boolean('mail_enabled')->nullable();
            $table->boolean('pay_enabled')->nullable();
            $table->string('wise_env', 12)->nullable();
            $table->text('wise_api_token_enc')->nullable();
            $table->string('wise_profile_id', 40)->nullable();
            $table->string('wise_balance_id', 40)->nullable();
            $table->text('wise_webhook_key')->nullable();
            $table->string('wise_webhook_id', 64)->nullable();
            $table->dateTime('wise_last_sync_at')->nullable();
            $table->string('wise_last_error', 400)->nullable();
            $table->string('wise_usd_bank', 160)->nullable();
            $table->string('wise_usd_routing', 40)->nullable();
            $table->string('wise_usd_account', 60)->nullable();
            $table->string('wise_usd_type', 24)->nullable();
            $table->string('wise_usd_address', 255)->nullable();
            $table->text('wise_sca_public')->nullable();
            $table->text('wise_sca_private_enc')->nullable();
            $table->dateTime('wise_sca_created_at')->nullable();
            $table->string('pay_method', 16)->default('bank');
            $table->string('wise_usd_holder', 160)->nullable();
            $table->string('wise_usd_swift', 24)->nullable();
            $table->double('price_solo')->default(0);
            $table->double('price_seat_cap')->default(0);
            $table->integer('seats_bill_min')->default(0);
            $table->integer('seats_cap_covers')->default(0);
            $table->double('custom_fee')->nullable();
            $table->string('ga4_measurement_id', 24)->nullable();
            $table->string('clarity_project_id', 24)->nullable();
            $table->string('utm_source', 80)->nullable();
            $table->string('utm_medium', 80)->nullable();
            $table->string('utm_campaign', 80)->nullable();
            $table->string('utm_content', 80)->nullable();
            $table->string('utm_landing', 190)->nullable();
            $table->boolean('sso_enabled')->default(0);
            $table->string('sso_issuer', 255)->nullable();
            $table->string('sso_client_id', 255)->nullable();
            $table->text('sso_client_secret')->nullable();
            $table->string('sso_domains', 255)->nullable();
            $table->boolean('sso_enforce')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
