<?php
/**
 * Stripe Billing — card on file for subscription payments.
 *
 * Runs alongside the Wise bank-transfer path rather than replacing it: cards for
 * self-serve monthly plans, transfers for annual/enterprise invoices where a ~3% card
 * fee is worth avoiding. Both settle into the same payments/invoices ledger, told apart
 * by payments.provider.
 *
 * WHY HOSTED CHECKOUT AND NOT EMBEDDED CARD FIELDS
 * bootstrap.php sends `script-src 'self'` and `form-action 'self'`, so Stripe.js from
 * js.stripe.com would be blocked, and a form posting straight to checkout.stripe.com
 * would be blocked too. We therefore create a Checkout Session server-side and answer
 * with a 302 — a Location redirect is not subject to form-action, so no CSP change is
 * needed. The card never touches this server (PCI SAQ A), and Stripe's Billing Portal
 * hosts the "update my card / cancel" screens we would otherwise have to build.
 * Do not switch to Elements without deliberately widening the CSP.
 */

// ─────────────────────────── Settings ───────────────────────────

/** Stripe settings from the platform org row, falling back to config('payments.stripe.*'). */
function stripe_settings(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $row = [];
    try {
        $row = db_one('SELECT stripe_mode, stripe_secret_enc, stripe_publishable, stripe_webhook_sec_enc,
                              stripe_price_individual, stripe_price_organization, stripe_price_per_seat,
                              stripe_last_error
                       FROM organizations WHERE id = ?', [platform_org_id()]) ?: [];
    } catch (\Throwable $e) {
        $row = [];   // pre-migration database
    }
    $pick = function (string $col, string $cfg) use ($row) {
        $v = $row[$col] ?? null;
        if ($v !== null && $v !== '') {
            return (string) $v;
        }
        $c = pay_cfg($cfg, '');
        return ($c === null || $c === '' || $c === 0) ? '' : (string) $c;
    };
    $mode = $pick('stripe_mode', 'stripe.mode') ?: 'test';
    $cache = [
        'mode'        => in_array($mode, ['test', 'live'], true) ? $mode : 'test',
        'secret'      => !empty($row['stripe_secret_enc'])
                            ? dp_decrypt($row['stripe_secret_enc']) : (string) pay_cfg('stripe.secret_key', ''),
        'publishable' => $pick('stripe_publishable', 'stripe.publishable_key'),
        'webhook_sec' => !empty($row['stripe_webhook_sec_enc'])
                            ? dp_decrypt($row['stripe_webhook_sec_enc']) : (string) pay_cfg('stripe.webhook_secret', ''),
        'prices'      => [
            'individual'   => $pick('stripe_price_individual', 'stripe.price_individual'),
            'organization' => $pick('stripe_price_organization', 'stripe.price_organization'),
            'per_seat'     => $pick('stripe_price_per_seat', 'stripe.price_per_seat'),
        ],
        'last_error'  => $row['stripe_last_error'] ?? null,
    ];
    return $cache;
}

function stripe_configured(): bool
{
    return stripe_settings()['secret'] !== '';
}

/** The Stripe Price id backing a plan, or '' when that plan has no card option yet. */
function stripe_price_for_plan(string $planType): string
{
    $p = stripe_settings()['prices'];
    return (string) ($p[$planType] ?? '');
}

/** The URL Stripe should POST events to. */
function stripe_webhook_url(): string
{
    return rtrim((string) public_base_url(), '/') . url('/webhooks/stripe');
}

function stripe_note_error(?string $err): void
{
    db_exec('UPDATE organizations SET stripe_last_error = ? WHERE id = ?',
        [$err !== null ? substr($err, 0, 400) : null, platform_org_id()]);
}

/** A secret key must match the selected mode, or every call fails confusingly. */
function stripe_key_matches_mode(): bool
{
    $s = stripe_settings();
    if ($s['secret'] === '') {
        return true;
    }
    return $s['mode'] === 'live'
        ? str_starts_with($s['secret'], 'sk_live_') || str_starts_with($s['secret'], 'rk_live_')
        : str_starts_with($s['secret'], 'sk_test_') || str_starts_with($s['secret'], 'rk_test_');
}

// ─────────────────────────── API client ───────────────────────────

/**
 * Call the Stripe REST API. Returns [ok, decoded|errorString, httpStatus].
 * Never throws — the gateway being unreachable must not break a page.
 *
 * Stripe takes form-encoded input (not JSON); http_build_query produces the
 * `line_items[0][price]` bracket syntax Stripe expects. Every POST carries an
 * Idempotency-Key so a retried request can never create a second subscription.
 */
function stripe_api(string $method, string $path, array $params = [], ?string $idempotencyKey = null): array
{
    $s = stripe_settings();
    if ($s['secret'] === '') {
        return [false, 'No Stripe secret key configured.', 0];
    }
    $method = strtoupper($method);
    $url = 'https://api.stripe.com' . $path;
    $headers = [
        'Authorization: Bearer ' . $s['secret'],
        'Accept: application/json',
    ];
    if ($method === 'POST') {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Idempotency-Key: ' . ($idempotencyKey ?: bin2hex(random_bytes(16)));
    }
    if ($method === 'GET' && $params) {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }
    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return [false, 'Could not reach Stripe: ' . $err, 0];
    }
    $data = json_decode((string) $raw, true);
    if ($status >= 200 && $status < 300) {
        return [true, $data ?? [], $status];
    }
    $msg = $data['error']['message'] ?? substr((string) $raw, 0, 300);
    return [false, 'Stripe ' . $status . ': ' . $msg, $status];
}

/** Verify the key works. Returns [ok, error, accountLabel]. */
function stripe_test_connection(): array
{
    if (!stripe_key_matches_mode()) {
        $s = stripe_settings();
        return [false, 'The stored secret key does not match ' . strtoupper($s['mode'])
            . ' mode — a ' . ($s['mode'] === 'live' ? 'sk_live_' : 'sk_test_') . ' key is expected.', ''];
    }
    [$ok, $data] = stripe_api('GET', '/v1/balance');
    if (!$ok) {
        stripe_note_error(is_string($data) ? $data : 'Unknown error');
        return [false, is_string($data) ? $data : 'Unknown error', ''];
    }
    stripe_note_error(null);
    $avail = [];
    foreach ((array) ($data['available'] ?? []) as $b) {
        $avail[] = strtoupper((string) ($b['currency'] ?? '')) . ' ' . number_format(((int) ($b['amount'] ?? 0)) / 100, 2);
    }
    return [true, null, $avail ? implode(', ', $avail) : 'connected'];
}

// ─────────────────────────── Customer + checkout ───────────────────────────

/** Create (or reuse) the Stripe Customer for an org. Returns [customerId, error]. */
function stripe_ensure_customer(array $org, array $actor): array
{
    if (!empty($org['stripe_customer_id'])) {
        return [(string) $org['stripe_customer_id'], null];
    }
    [$ok, $data] = stripe_api('POST', '/v1/customers', [
        'name'  => (string) $org['name'],
        'email' => (string) ($actor['email'] ?? ''),
        'metadata' => ['org_id' => (string) $org['id'], 'pay_reference' => (string) ($org['pay_reference'] ?? '')],
    ], 'cus-org-' . (int) $org['id']);
    if (!$ok) {
        return ['', is_string($data) ? $data : 'Could not create the Stripe customer.'];
    }
    $id = (string) ($data['id'] ?? '');
    db_exec('UPDATE organizations SET stripe_customer_id = ? WHERE id = ?', [$id, (int) $org['id']]);
    return [$id, null];
}

/**
 * Start a Checkout Session for the org's plan. Returns [url, error].
 *
 * The org's discount_pct has no direct Stripe equivalent, so a discounted tenant is
 * refused here rather than being quietly overcharged at list price — billing the wrong
 * amount is the worst failure mode this code can have. Those customers stay on Wise
 * until a Stripe Coupon is wired up.
 */
function stripe_checkout_url(array $org, array $actor): array
{
    $plan  = (string) ($org['plan_type'] ?? 'organization');
    $price = stripe_price_for_plan($plan);
    if ($price === '') {
        return ['', 'No Stripe price is configured for the "' . $plan . '" plan yet.'];
    }
    if ((float) ($org['discount_pct'] ?? 0) > 0) {
        return ['', 'This organization has a ' . rtrim(rtrim(number_format((float) $org['discount_pct'], 2), '0'), '.')
            . '% discount, which is not yet represented in Stripe. Paying by card would charge the full list '
            . 'price, so card checkout is disabled for discounted accounts — keep using bank transfer.'];
    }
    [$customer, $err] = stripe_ensure_customer($org, $actor);
    if ($err !== null) {
        return ['', $err];
    }
    $base = rtrim((string) public_base_url(), '/');
    $params = [
        'mode'     => 'subscription',
        'customer' => $customer,
        'line_items' => [['price' => $price, 'quantity' => 1]],
        'client_reference_id' => (string) $org['id'],
        'success_url' => $base . url('/app/subscription?checkout=success'),
        'cancel_url'  => $base . url('/app/subscription?checkout=cancel'),
        'metadata'    => ['org_id' => (string) $org['id']],
        'subscription_data' => ['metadata' => ['org_id' => (string) $org['id']]],
        'allow_promotion_codes' => 'true',
    ];
    // Per-seat plans bill by quantity; send the live seat count.
    if ($plan === 'per_seat') {
        $params['line_items'][0]['quantity'] = max(1, org_seat_count((int) $org['id']));
    }
    [$ok, $data] = stripe_api('POST', '/v1/checkout/sessions', $params);
    if (!$ok) {
        stripe_note_error(is_string($data) ? $data : 'Unknown error');
        return ['', is_string($data) ? $data : 'Could not start checkout.'];
    }
    stripe_note_error(null);
    return [(string) ($data['url'] ?? ''), null];
}

/** Billing Portal session — where the customer changes card, sees invoices, cancels. */
function stripe_portal_url(array $org): array
{
    if (empty($org['stripe_customer_id'])) {
        return ['', 'This organization has no card on file yet.'];
    }
    [$ok, $data] = stripe_api('POST', '/v1/billing_portal/sessions', [
        'customer'   => (string) $org['stripe_customer_id'],
        'return_url' => rtrim((string) public_base_url(), '/') . url('/app/subscription'),
    ]);
    if (!$ok) {
        stripe_note_error(is_string($data) ? $data : 'Unknown error');
        return ['', is_string($data) ? $data : 'Could not open the billing portal.'];
    }
    stripe_note_error(null);
    return [(string) ($data['url'] ?? ''), null];
}

// ─────────────────────────── State sync ───────────────────────────

/** Stripe subscription status → our subscription_status vocabulary. */
function stripe_map_status(string $s): string
{
    switch ($s) {
        case 'active':             return 'active';
        case 'trialing':           return 'trialing';
        case 'past_due':
        case 'unpaid':             return 'past_due';
        case 'canceled':           return 'canceled';
        default:                   return 'none';   // incomplete, incomplete_expired, paused
    }
}

/**
 * Apply a Stripe subscription object to the org.
 *
 * IMPORTANT: this sets current_period_end from Stripe and must NOT go through
 * activate_period()/settle_org_payment() (payments.php), which extend by *our*
 * billing_period(). Stripe owns the period for a Stripe subscription; running both
 * would make the paid-until date drift away from what the customer is actually billed.
 */
function stripe_apply_subscription(int $orgId, array $sub): void
{
    $end = !empty($sub['current_period_end'])
        ? gmdate('Y-m-d H:i:s', (int) $sub['current_period_end']) : null;
    $price = $sub['items']['data'][0]['price']['id'] ?? null;
    db_exec('UPDATE organizations SET stripe_subscription_id = ?, stripe_price_id = ?,
                    subscription_status = ?, current_period_end = COALESCE(?, current_period_end)
             WHERE id = ?',
        [(string) ($sub['id'] ?? '') ?: null, $price, stripe_map_status((string) ($sub['status'] ?? '')),
         $end, $orgId]);
}

/** Find the org a Stripe object belongs to, by metadata first then customer id. */
function stripe_org_for(array $object): ?array
{
    $orgId = (int) ($object['metadata']['org_id'] ?? 0);
    if ($orgId > 0) {
        $o = db_one('SELECT * FROM organizations WHERE id = ?', [$orgId]);
        if ($o) {
            return $o;
        }
    }
    $cus = (string) ($object['customer'] ?? '');
    if ($cus !== '') {
        return db_one('SELECT * FROM organizations WHERE stripe_customer_id = ?', [$cus]);
    }
    return null;
}

// ─────────────────────────── Webhook ───────────────────────────

/**
 * Verify Stripe's signature header: `t=<unix>,v1=<hex hmac>[,v1=...]`. The signed
 * payload is "{t}.{rawBody}", HMAC-SHA256 with the endpoint's signing secret.
 * Rejects deliveries older than the tolerance so a captured request can't be replayed.
 */
function stripe_verify_signature(string $rawBody, int $tolerance = 300): array
{
    $secret = stripe_settings()['webhook_sec'];
    if ($secret === '') {
        // Unlike the Wise endpoint we fail closed: Stripe retries a failed delivery for
        // days, so nothing is lost by refusing until the signing secret is configured.
        return [false, 'no signing secret configured'];
    }
    $header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    if ($header === '') {
        return [false, 'missing Stripe-Signature header'];
    }
    $ts = null;
    $sigs = [];
    foreach (explode(',', $header) as $part) {
        $kv = explode('=', trim($part), 2);
        if (count($kv) !== 2) {
            continue;
        }
        if ($kv[0] === 't') {
            $ts = (int) $kv[1];
        } elseif ($kv[0] === 'v1') {
            $sigs[] = $kv[1];
        }
    }
    if ($ts === null || !$sigs) {
        return [false, 'malformed Stripe-Signature header'];
    }
    if (abs(time() - $ts) > $tolerance) {
        return [false, 'timestamp outside the tolerance window (replay?)'];
    }
    $expected = hash_hmac('sha256', $ts . '.' . $rawBody, $secret);
    foreach ($sigs as $sig) {
        if (hash_equals($expected, $sig)) {
            return [true, 'verified'];
        }
    }
    return [false, 'signature did not match'];
}

/** POST /webhooks/stripe */
function wh_stripe(): void
{
    $raw = raw_body();

    [$ok, $note] = stripe_verify_signature($raw);
    if (!$ok) {
        stripe_note_error('Rejected a webhook delivery: ' . $note);
        abort(400, 'Invalid signature: ' . $note);
    }

    $event = json_decode($raw, true) ?: [];
    $type  = (string) ($event['type'] ?? '');
    $id    = (string) ($event['id'] ?? '');
    $obj   = $event['data']['object'] ?? [];
    if ($id === '') {
        abort(400, 'Missing event id');
    }

    // Stripe retries; a repeat must not extend a subscription or bank a payment twice.
    try {
        db_exec('INSERT INTO webhook_events (provider, event_id, type) VALUES (?, ?, ?)',
            ['stripe', $id, substr($type, 0, 64)]);
    } catch (\PDOException $e) {
        json_out(['ok' => true, 'note' => 'duplicate delivery ignored']);
    }

    $org = is_array($obj) ? stripe_org_for($obj) : null;

    switch ($type) {
        case 'checkout.session.completed':
            $orgId = (int) ($obj['client_reference_id'] ?? ($obj['metadata']['org_id'] ?? 0));
            if ($orgId > 0) {
                db_exec('UPDATE organizations SET stripe_customer_id = COALESCE(?, stripe_customer_id) WHERE id = ?',
                    [(string) ($obj['customer'] ?? '') ?: null, $orgId]);
                $subId = (string) ($obj['subscription'] ?? '');
                if ($subId !== '') {
                    [$sok, $sub] = stripe_api('GET', '/v1/subscriptions/' . rawurlencode($subId));
                    if ($sok) {
                        stripe_apply_subscription($orgId, (array) $sub);
                    }
                }
                $o = db_one('SELECT name FROM organizations WHERE id = ?', [$orgId]);
                notify_sales('subscription.card_added', 'Card on file added: ' . ($o['name'] ?? ('org #' . $orgId)), [
                    'Organization' => $o['name'] ?? ('#' . $orgId),
                    'Via'          => 'Stripe Checkout',
                ], $orgId);
            }
            break;

        case 'invoice.paid':
            if ($org) {
                stripe_record_invoice_payment($org, (array) $obj);
            }
            break;

        case 'invoice.payment_failed':
            if ($org) {
                db_exec("UPDATE organizations SET subscription_status = 'past_due' WHERE id = ?", [(int) $org['id']]);
                notify_sales('subscription.payment_failed', 'Card payment failed: ' . $org['name'], [
                    'Organization' => $org['name'],
                    'Amount'       => number_format(((int) ($obj['amount_due'] ?? 0)) / 100, 2) . ' '
                                    . strtoupper((string) ($obj['currency'] ?? 'usd')),
                    'Attempt'      => (string) ($obj['attempt_count'] ?? '1'),
                    'Action'       => 'Stripe will retry automatically; the customer can update their card in the billing portal.',
                ], (int) $org['id']);
            }
            break;

        case 'customer.subscription.updated':
        case 'customer.subscription.created':
            if ($org) {
                stripe_apply_subscription((int) $org['id'], (array) $obj);
            }
            break;

        case 'customer.subscription.deleted':
            if ($org) {
                db_exec("UPDATE organizations SET subscription_status = 'canceled', stripe_subscription_id = NULL
                         WHERE id = ?", [(int) $org['id']]);
                notify_sales('subscription.canceled', 'Subscription canceled: ' . $org['name'], [
                    'Organization' => $org['name'],
                    'Paid until'   => (string) ($org['current_period_end'] ?? ''),
                ], (int) $org['id']);
            }
            break;
    }
    stripe_note_error(null);
    json_out(['ok' => true, 'type' => $type, 'note' => $note]);
}

/**
 * Record a paid Stripe invoice: a payments row, a mirrored invoices row, and the
 * period taken from Stripe's own subscription (never from activate_period()).
 */
function stripe_record_invoice_payment(array $org, array $inv): void
{
    $orgId  = (int) $org['id'];
    $cents  = (int) ($inv['amount_paid'] ?? 0);
    $cur    = strtoupper((string) ($inv['currency'] ?? 'usd'));
    $paidAt = !empty($inv['status_transitions']['paid_at'])
        ? gmdate('Y-m-d H:i:s', (int) $inv['status_transitions']['paid_at']) : gmdate('Y-m-d H:i:s');
    $txId   = (string) ($inv['id'] ?? '');

    // Mirror the Stripe invoice into our ledger so the Accounting page shows one history.
    $ref = (string) ($inv['number'] ?? $txId);
    $existing = db_one('SELECT id FROM invoices WHERE org_id = ? AND reference = ?', [$orgId, $ref]);
    if ($existing) {
        $invoiceId = (int) $existing['id'];
        db_exec("UPDATE invoices SET status = 'paid', paid_at = ?, amount_cents = ? WHERE id = ?",
            [$paidAt, $cents, $invoiceId]);
    } else {
        $invoiceId = (int) db_exec(
            "INSERT INTO invoices (org_id, reference, amount_cents, currency, period_start, period_end, status, paid_at)
             VALUES (?, ?, ?, ?, ?, ?, 'paid', ?)",
            [$orgId, $ref, $cents, $cur,
             !empty($inv['period_start']) ? gmdate('Y-m-d', (int) $inv['period_start']) : null,
             !empty($inv['period_end']) ? gmdate('Y-m-d', (int) $inv['period_end']) : null,
             $paidAt]
        );
    }

    // provider_tx_id is uniquely indexed, so a replayed delivery cannot double-bank.
    try {
        db_exec("INSERT INTO payments (org_id, invoice_id, provider, provider_tx_id, amount_cents, currency,
                        reference_raw, occurred_at, matched, note, raw)
                 VALUES (?, ?, 'stripe', ?, ?, ?, ?, ?, 1, ?, ?)",
            [$orgId, $invoiceId, $txId ?: null, $cents, $cur, $ref, $paidAt,
             'Stripe card payment', substr(json_encode($inv), 0, 65000)]);
    } catch (\PDOException $e) {
        return;   // already recorded
    }

    // Period comes from Stripe, not from our billing_period().
    $subId = (string) ($inv['subscription'] ?? ($org['stripe_subscription_id'] ?? ''));
    if ($subId !== '') {
        [$sok, $sub] = stripe_api('GET', '/v1/subscriptions/' . rawurlencode($subId));
        if ($sok) {
            stripe_apply_subscription($orgId, (array) $sub);
        }
    } elseif (!empty($inv['lines']['data'][0]['period']['end'])) {
        db_exec("UPDATE organizations SET subscription_status = 'active', current_period_end = ? WHERE id = ?",
            [gmdate('Y-m-d H:i:s', (int) $inv['lines']['data'][0]['period']['end']), $orgId]);
    }

    notify_sales('subscription.payment', 'Card payment received: ' . $org['name'], [
        'Organization' => $org['name'],
        'Amount'       => number_format($cents / 100, 2) . ' ' . $cur,
        'Via'          => 'Stripe',
        'Invoice'      => $ref,
    ], $orgId);
}
