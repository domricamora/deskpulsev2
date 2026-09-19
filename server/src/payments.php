<?php
/**
 * Payment infrastructure — platform subscriptions.
 *
 * DeskPulse charges each organization its flat monthly plan fee. Money is collected
 * by US bank deposit (ACH/wire, USD) to a Wise Business USD account and reconciled
 * from the Wise API / balances#credit webhook. This file provides the subscription
 * state + trial/paywall helpers; the Wise API calls, invoice issuance, reconciliation
 * and promo codes land in later phases (see the plan). Reuses the existing plan model
 * (organizations.plan_type/discount_pct + effective_monthly_fee(), platform_org_id()).
 */

/** Nested lookup into config('payments'); dot path, e.g. pay_cfg('wise.api_token'). */
function pay_cfg(string $path, $default = null)
{
    $node = config('payments');
    if (!is_array($node)) {
        return $default;
    }
    foreach (explode('.', $path) as $k) {
        if (!is_array($node) || !array_key_exists($k, $node)) {
            return $default;
        }
        $node = $node[$k];
    }
    return $node;
}

/** Master switch — when false the trial/paywall is inert and the app behaves as before. */
function payments_enabled(): bool
{
    // The super admin can flip this from the Accounting page; config.php is the fallback.
    return (bool) wise_settings()['enabled'];
}

/** Trial length in days: the value on the platform org row, else the config default. */
function platform_trial_days(): int
{
    $row  = db_one('SELECT trial_days FROM organizations WHERE id = ?', [platform_org_id()]);
    $days = (int) ($row['trial_days'] ?? 0);
    return $days > 0 ? $days : max(0, (int) pay_cfg('trial_days', 14));
}

/** The org's flat monthly fee in integer cents (USD), after any discount. */
function plan_amount_cents(array $org): int
{
    return (int) round(((float) effective_monthly_fee($org)) * 100);
}

/**
 * Does the org currently have access? True when: a super-admin manual comp
 * (billing_status='active'), an active trial (trial_ends_at in the future), or an
 * active paid period (current_period_end in the future / not yet recorded).
 */
function sub_is_current(array $org): bool
{
    if (($org['billing_status'] ?? '') === 'active') {
        return true;   // manual comp / override by super admin
    }
    $status = $org['subscription_status'] ?? 'none';
    $now = time();
    if ($status === 'trialing') {
        return !empty($org['trial_ends_at']) && strtotime($org['trial_ends_at'] . ' UTC') > $now;
    }
    if ($status === 'active') {
        return empty($org['current_period_end'])
            || strtotime($org['current_period_end'] . ' UTC') > $now;
    }
    return false;   // none | past_due | canceled
}

/** Whole days left in the trial (0 when not trialing or already expired). */
function sub_days_left(array $org): int
{
    if (($org['subscription_status'] ?? '') !== 'trialing' || empty($org['trial_ends_at'])) {
        return 0;
    }
    $secs = strtotime($org['trial_ends_at'] . ' UTC') - time();
    return $secs > 0 ? (int) ceil($secs / 86400) : 0;
}

/** A short human label for the org's subscription state. */
function sub_state_label(array $org): string
{
    if (($org['billing_status'] ?? '') === 'active') {
        return 'Active (complimentary)';
    }
    switch ($org['subscription_status'] ?? 'none') {
        case 'trialing':
            $d = sub_days_left($org);
            return $d > 0 ? "Free trial — {$d} day" . ($d === 1 ? '' : 's') . ' left' : 'Trial ended';
        case 'active':
            return 'Active';
        case 'past_due':
            return 'Payment due';
        case 'canceled':
            return 'Canceled';
        default:
            return 'No subscription';
    }
}

/** Assign a stable deposit reference to the org once (DP-<id>-<5hex>). Returns it. */
function ensure_pay_reference(array $org): string
{
    if (!empty($org['pay_reference'])) {
        return $org['pay_reference'];
    }
    $ref = 'DP-' . (int) $org['id'] . '-' . strtoupper(bin2hex(random_bytes(3)));
    db_exec('UPDATE organizations SET pay_reference = ? WHERE id = ?', [$ref, (int) $org['id']]);
    return $ref;
}

/** The USD bank-deposit details shown to the payer (from config; the Wise API can
 *  fill/refresh this in a later phase). Returns [] when not configured. */
function wise_usd_deposit_details(): array
{
    return wise_settings()['usd_details'];
}

// ─────────────────────────── Invoices & reconciliation (Phase 2) ───────────────────────────

/** The platform billing period: how long one payment grants access. Set by the super
 *  admin (days or months) on the platform org row; defaults to 1 month. */
function billing_period(): array
{
    $r = db_one('SELECT billing_period_unit, billing_period_count FROM organizations WHERE id = ?', [platform_org_id()]);
    $unit  = ($r['billing_period_unit'] ?? 'month') === 'day' ? 'day' : 'month';
    $count = max(1, (int) ($r['billing_period_count'] ?? 1));
    return ['unit' => $unit, 'count' => $count];
}

/** Human label for the billing period, e.g. "per month", "every 30 days". */
function plan_period_label(): string
{
    $bp = billing_period();
    if ($bp['count'] === 1) {
        return 'per ' . $bp['unit'];
    }
    return "every {$bp['count']} {$bp['unit']}s";
}

/** Extend the org's paid period by N billing periods (from the later of now / current
 *  end) and mark the subscription active. The period length (days or months) is the
 *  super-admin-configured billing_period(). */
function activate_period(int $orgId, int $periods = 1): void
{
    $bp   = billing_period();
    $step = max(1, $bp['count'] * max(1, $periods)) . ' ' . $bp['unit'];
    $org  = db_one('SELECT current_period_end FROM organizations WHERE id = ?', [$orgId]);
    $base = (!empty($org['current_period_end']) && strtotime($org['current_period_end'] . ' UTC') > time())
        ? strtotime($org['current_period_end'] . ' UTC') : time();
    $end = gmdate('Y-m-d H:i:s', strtotime("+{$step}", $base));
    db_exec("UPDATE organizations SET subscription_status = 'active', current_period_end = ? WHERE id = ?",
        [$end, $orgId]);
}

/** The org's current-cycle OPEN invoice, creating one if none is open. */
function issue_invoice(array $org): array
{
    $open = db_one("SELECT * FROM invoices WHERE org_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1",
        [(int) $org['id']]);
    if ($open) {
        return $open;
    }
    $ref = ensure_pay_reference($org);
    $id  = db_exec(
        "INSERT INTO invoices (org_id, reference, amount_cents, currency, period_start, period_end, status)
         VALUES (?, ?, ?, ?, ?, ?, 'open')",
        [(int) $org['id'], $ref, plan_amount_cents($org), $org['billing_currency'] ?: 'USD',
         gmdate('Y-m-d'), gmdate('Y-m-d', strtotime('+1 month'))]
    );
    return db_one('SELECT * FROM invoices WHERE id = ?', [$id]);
}

/** Mark an org's current open invoice paid, link an optional payment row, and extend
 *  the paid period by one month. Returns the settled invoice id (or null). */
function settle_org_payment(int $orgId, ?int $paymentId = null): ?int
{
    $org = db_one('SELECT * FROM organizations WHERE id = ?', [$orgId]);
    if (!$org) {
        return null;
    }
    $inv = issue_invoice($org);
    db_exec("UPDATE invoices SET status = 'paid', paid_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'open'",
        [(int) $inv['id']]);
    if ($paymentId) {
        db_exec('UPDATE payments SET invoice_id = ?, org_id = ?, matched = 1 WHERE id = ?',
            [(int) $inv['id'], $orgId, $paymentId]);
    }
    activate_period($orgId, 1);

    // Every payment route (Wise reconciliation, manual entry, manual assignment) funnels
    // through here, so this is the one place a subscription alert has to be raised.
    $pay = $paymentId ? db_one('SELECT * FROM payments WHERE id = ?', [$paymentId]) : null;
    $fresh = db_one('SELECT * FROM organizations WHERE id = ?', [$orgId]);
    notify_sales('subscription.payment', 'Payment received: ' . ($org['name'] ?? ('org #' . $orgId)), [
        'Organization'  => $org['name'] ?? ('#' . $orgId),
        'Amount'        => $pay
            ? number_format(((int) $pay['amount_cents']) / 100, 2) . ' ' . ($pay['currency'] ?: 'USD')
            : number_format(((int) $inv['amount_cents']) / 100, 2) . ' ' . ($inv['currency'] ?: 'USD'),
        'Via'           => $pay['provider'] ?? 'n/a',
        'Invoice'       => $inv['reference'] ?? ('#' . $inv['id']),
        'Plan'          => $org['plan_type'] ?? '',
        'Paid until'    => $fresh['current_period_end'] ?? '',
        'Subscription'  => $fresh['subscription_status'] ?? '',
    ], $orgId);
    return (int) $inv['id'];
}

/** Record an incoming Wise credit and try to auto-reconcile it to an org by payment
 *  reference. $tx keys: tx_id, amount_cents, currency, reference, occurred_at. Dedupes
 *  by wise_tx_id. Returns [matched(bool), message]. Unmatched credits are stored for
 *  manual assignment by a super admin. */
function reconcile_credit(array $tx): array
{
    $txId = trim((string) ($tx['tx_id'] ?? ''));
    if ($txId !== '' && db_one('SELECT id FROM payments WHERE wise_tx_id = ?', [$txId])) {
        return [false, 'duplicate (already processed)'];
    }
    $amount   = (int) ($tx['amount_cents'] ?? 0);
    $currency = $tx['currency'] ?? 'USD';
    $ref      = trim((string) ($tx['reference'] ?? ''));
    $occurred = !empty($tx['occurred_at']) ? $tx['occurred_at'] : gmdate('Y-m-d H:i:s');

    $org = null;
    $matchedCode = null;
    if ($ref !== '') {
        if (preg_match('/DP-\d+-[A-Z0-9]+/i', $ref, $m)) {
            $org = db_one('SELECT id FROM organizations WHERE UPPER(pay_reference) = ?', [strtoupper($m[0])]);
            if ($org) {
                $matchedCode = strtoupper($m[0]);
            }
        }
        if (!$org) {   // fuzzy: the reference appears somewhere in the transfer note
            $org = db_one("SELECT id FROM organizations WHERE pay_reference IS NOT NULL
                           AND ? LIKE CONCAT('%', pay_reference, '%')", [$ref]);
            if ($org) {
                $matchedCode = db_one('SELECT pay_reference FROM organizations WHERE id = ?',
                    [(int) $org['id']])['pay_reference'] ?? null;
            }
        }
    }
    $pid = db_exec(
        "INSERT INTO payments (org_id, provider, wise_tx_id, amount_cents, currency, reference_raw,
                occurred_at, matched, wise_reference_code, raw)
         VALUES (?, 'wise', ?, ?, ?, ?, ?, ?, ?, ?)",
        [$org['id'] ?? null, $txId ?: null, $amount, $currency, substr($ref, 0, 255), $occurred,
         $org ? 1 : 0, $matchedCode,
         isset($tx['raw']) ? substr(json_encode($tx['raw']), 0, 65000) : null]
    );
    if ($org) {
        settle_org_payment((int) $org['id'], $pid);
        return [true, 'matched to org #' . $org['id']];
    }
    // An unmatched credit needs a human — sales/ops should hear about it immediately,
    // because the money has arrived but nobody's subscription has been extended.
    notify_sales('subscription.payment_unmatched', 'Unmatched payment received — needs assignment', [
        'Amount'    => number_format($amount / 100, 2) . ' ' . $currency,
        'Reference' => $ref !== '' ? $ref : '(none supplied)',
        'Wise tx'   => $txId ?: 'n/a',
        'Received'  => $occurred,
        'Action'    => 'Assign it to an organization on the Platform billing page.',
    ]);
    return [false, 'no matching reference — held for manual assignment'];
}

// ─────────────────────────── Customer payment notifications ───────────────────────────

/** Human labels for how a customer says they sent the money. */
function claim_methods(): array
{
    return [
        'bank_transfer' => 'Bank transfer',
        'wire'          => 'International wire (SWIFT)',
        'ach'           => 'ACH (US domestic)',
        'other'         => 'Other',
    ];
}

/**
 * Store an uploaded remittance receipt. Kept in PRIVATE storage, never under the
 * document root: public/.htaccess serves any real file it finds, and a receipt carries
 * bank details. Returns [relativePath, error].
 */
function store_payment_receipt(array $file, int $orgId): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null];                       // optional — no file is fine
    }
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
        return [null, 'The receipt failed to upload.'];
    }
    if (($file['size'] ?? 0) > 8 * 1024 * 1024) {
        return [null, 'The receipt is larger than 8 MB.'];
    }
    // Trust the sniffed type, not the filename.
    $mime = function_exists('finfo_open')
        ? (finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file['tmp_name']) ?: '') : '';
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    if (!isset($allowed[$mime])) {
        return [null, 'Upload a PDF or an image (JPG, PNG or WebP).'];
    }
    $rel = sprintf('receipts/%d/%s.%s', $orgId, bin2hex(random_bytes(10)), $allowed[$mime]);
    $abs = private_path($rel);
    @mkdir(dirname($abs), 0770, true);
    if (!move_uploaded_file($file['tmp_name'], $abs)) {
        return [null, 'Could not save the receipt.'];
    }
    return [$rel, null];
}

/**
 * Confirm a customer's payment notification: record the money, settle the open invoice
 * and extend the paid period. Returns [ok, error].
 */
function confirm_payment_claim(int $claimId, array $admin, ?int $overrideCents = null, string $note = ''): array
{
    $claim = db_one('SELECT * FROM payment_claims WHERE id = ?', [$claimId]);
    if (!$claim) {
        return [false, 'That payment notification no longer exists.'];
    }
    if ($claim['status'] !== 'pending') {
        return [false, 'That notification has already been ' . $claim['status'] . '.'];
    }
    $cents = $overrideCents !== null && $overrideCents > 0 ? $overrideCents : (int) $claim['amount_cents'];
    if ($cents <= 0) {
        return [false, 'The confirmed amount must be greater than zero.'];
    }
    $orgId = (int) $claim['org_id'];
    record_manual_payment($orgId, $cents,
        trim('Confirmed customer payment notification #' . $claimId . ' ' . $note));
    $pay = db_one('SELECT id FROM payments WHERE org_id = ? ORDER BY id DESC LIMIT 1', [$orgId]);
    db_exec("UPDATE payment_claims SET status = 'confirmed', payment_id = ?, reviewed_by_id = ?,
                    reviewed_at = ?, review_note = ? WHERE id = ?",
        [$pay['id'] ?? null, $admin['id'], gmdate('Y-m-d H:i:s'), substr($note, 0, 2000) ?: null, $claimId]);
    return [true, null];
}

/** Super-admin manual: record an off-platform payment for an org (marks the current
 *  invoice paid and extends the period). */
function record_manual_payment(int $orgId, int $amountCents, string $note = ''): void
{
    $pid = db_exec(
        "INSERT INTO payments (org_id, provider, amount_cents, currency, reference_raw, occurred_at, matched, note)
         VALUES (?, 'manual', ?, 'USD', NULL, UTC_TIMESTAMP(), 1, ?)",
        [$orgId, $amountCents, substr($note, 0, 255)]
    );
    settle_org_payment($orgId, $pid);
}

/** Assign an existing unmatched payment to an org (manual reconciliation). */
function assign_payment(int $paymentId, int $orgId): void
{
    settle_org_payment($orgId, $paymentId);
}

/** Start an org on a free trial: plan, trialing status, trial end + deposit reference,
 *  and the effective monthly fee. Called at registration. No-op if payments disabled. */
function start_trial(int $orgId, string $planType): void
{
    $plan = in_array($planType, ['solo', 'individual', 'per_seat', 'organization'], true) ? $planType : 'organization';
    $org  = db_one('SELECT * FROM organizations WHERE id = ?', [$orgId]);
    if (!$org) {
        return;
    }
    $fee = effective_monthly_fee(array_merge($org, ['plan_type' => $plan]));
    if (payments_enabled()) {
        $days = platform_trial_days();
        db_exec(
            "UPDATE organizations SET plan_type = ?, monthly_fee = ?, subscription_status = 'trialing',
             trial_ends_at = ? WHERE id = ?",
            [$plan, $fee, gmdate('Y-m-d H:i:s', time() + $days * 86400), $orgId]
        );
    } else {
        db_exec('UPDATE organizations SET plan_type = ?, monthly_fee = ? WHERE id = ?', [$plan, $fee, $orgId]);
    }
    ensure_pay_reference(['id' => $orgId, 'pay_reference' => $org['pay_reference'] ?? null]);
}
