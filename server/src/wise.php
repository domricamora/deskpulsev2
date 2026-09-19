<?php
/**
 * Wise connection: credentials, API client, statement sync and the inbound webhook.
 *
 * Money for DeskPulse subscriptions arrives as USD bank deposits into a Wise Business
 * balance. Two things bring it into the app:
 *   1. The webhook (POST /webhooks/wise) — Wise notifies us the moment a balance is
 *      credited. That event carries the amount and time but NOT the payer's reference,
 *      so it records the credit and immediately triggers (2).
 *   2. The statement sync — reads the balance statement over the API, which does carry
 *      the payment reference, and reconciles each credit to an organization by its
 *      DP-<id>-XXXX code. Also runnable on demand from the Accounting page, so the
 *      system still works if a webhook is ever missed.
 *
 * Credentials live on the platform org row (like the mail and plan settings); the API
 * token is encrypted at rest. config('payments.wise.*') remains the fallback.
 */

// ─────────────────────────── Credentials ───────────────────────────

/** Wise settings from the platform org row, falling back to config.php. Cached per request. */
function wise_settings(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $row = [];
    try {
        $row = db_one('SELECT pay_enabled, wise_env, wise_api_token_enc, wise_profile_id, wise_balance_id,
                              wise_webhook_key, wise_webhook_id, wise_last_sync_at, wise_last_error,
                              wise_usd_bank, wise_usd_routing, wise_usd_account, wise_usd_type, wise_usd_address,
                              wise_usd_holder, wise_usd_swift, pay_method
                       FROM organizations WHERE id = ?', [platform_org_id()]) ?: [];
    } catch (\Throwable $e) {
        $row = [];      // pre-migration database
    }
    // config.example ships the numeric ids as 0, so a plain string cast would yield
    // "0" — a value that reads as "configured" and would build API calls against
    // profile 0. Treat 0/"0"/""/null uniformly as "not set".
    $blank = fn ($v) => $v === null || $v === '' || $v === 0 || $v === '0';
    $pick = function (string $col, string $cfg, $default = '') use ($row, $blank) {
        if (isset($row[$col]) && !$blank($row[$col])) {
            return (string) $row[$col];
        }
        $v = pay_cfg($cfg, $default);
        return $blank($v) ? '' : (string) $v;
    };

    $cache = [
        'enabled'      => $row['pay_enabled'] !== null && $row['pay_enabled'] !== ''
                            ? (bool) (int) $row['pay_enabled'] : (bool) pay_cfg('enabled', false),
        'env'          => in_array($pick('wise_env', 'wise.env', 'sandbox'), ['sandbox', 'live'], true)
                            ? $pick('wise_env', 'wise.env', 'sandbox') : 'sandbox',
        'api_token'    => !empty($row['wise_api_token_enc'])
                            ? dp_decrypt($row['wise_api_token_enc']) : (string) pay_cfg('wise.api_token', ''),
        'profile_id'   => (string) $pick('wise_profile_id', 'wise.profile_id', ''),
        'balance_id'   => (string) $pick('wise_balance_id', 'wise.balance_id', ''),
        'webhook_key'  => (string) $pick('wise_webhook_key', 'wise.webhook_public_key', ''),
        'webhook_id'   => (string) ($row['wise_webhook_id'] ?? ''),
        'last_sync_at' => $row['wise_last_sync_at'] ?? null,
        'last_error'   => $row['wise_last_error'] ?? null,
        'sca_public'   => (string) ($row['wise_sca_public'] ?? ''),
        'sca_private'  => !empty($row['wise_sca_private_enc']) ? dp_decrypt($row['wise_sca_private_enc']) : '',
        'sca_created'  => $row['wise_sca_created_at'] ?? null,
        'pay_method'   => (string) ($row['pay_method'] ?? 'bank'),
        'usd_details'  => array_filter([
            'holder'         => $pick('wise_usd_holder', 'wise.usd_details.holder', ''),
            'bank'           => $pick('wise_usd_bank', 'wise.usd_details.bank', ''),
            'swift'          => $pick('wise_usd_swift', 'wise.usd_details.swift', ''),
            'routing'        => $pick('wise_usd_routing', 'wise.usd_details.routing', ''),
            'account_number' => $pick('wise_usd_account', 'wise.usd_details.account_number', ''),
            'account_type'   => $pick('wise_usd_type', 'wise.usd_details.account_type', ''),
            'address'        => $pick('wise_usd_address', 'wise.usd_details.address', ''),
        ], fn ($v) => $v !== '' && $v !== null),
    ];
    return $cache;
}

/** Drop the cached settings after a save. */
function wise_settings_flush(): void
{
    // The cache is a per-request static; every save redirects, so nothing else needed.
    // Declared explicitly so call sites read clearly.
}

function wise_configured(): bool
{
    $s = wise_settings();
    return $s['api_token'] !== '' && $s['profile_id'] !== '';
}

/**
 * Validate a webhook public key.
 *
 * This key belongs to WISE, not to us: Wise signs each delivery with their private
 * key and publishes the matching public key per environment. There is nothing for an
 * operator to "generate" here — pasting a self-made key would reject every genuine
 * delivery, because Wise never sees the private half.
 *
 * Returns [ok, error, ['type'=>'RSA','bits'=>2048,'fingerprint'=>'AB:CD:…']].
 */
function wise_parse_public_key(string $pem): array
{
    $pem = trim($pem);
    if ($pem === '') {
        return [false, 'No key provided.', []];
    }
    if (!str_contains($pem, 'BEGIN PUBLIC KEY') && !str_contains($pem, 'BEGIN RSA PUBLIC KEY')
        && !str_contains($pem, 'BEGIN CERTIFICATE')) {
        return [false, 'That does not look like a PEM public key — it must start with '
                     . '"-----BEGIN PUBLIC KEY-----". A private key or an API token will not work here.', []];
    }
    if (str_contains($pem, 'PRIVATE KEY')) {
        return [false, 'That is a PRIVATE key. Paste the PUBLIC key Wise publishes — never a private key.', []];
    }
    $key = @openssl_pkey_get_public($pem);
    if (!$key) {
        return [false, 'OpenSSL could not read that key: ' . (openssl_error_string() ?: 'unrecognised format') . '.', []];
    }
    $d = @openssl_pkey_get_details($key) ?: [];
    $type = ($d['type'] ?? null) === OPENSSL_KEYTYPE_RSA ? 'RSA'
          : ((($d['type'] ?? null) === OPENSSL_KEYTYPE_EC) ? 'EC' : 'other');
    $fp = strtoupper(substr(hash('sha256', (string) ($d['key'] ?? $pem)), 0, 32));
    $fp = trim(chunk_split($fp, 4, ':'), ':');
    if ($type !== 'RSA') {
        return [false, 'Wise signs with RSA; this key is ' . $type . '.', []];
    }
    if ((int) ($d['bits'] ?? 0) < 2048) {
        return [false, 'That key is only ' . (int) ($d['bits'] ?? 0) . ' bits — Wise uses 2048 or more.', []];
    }
    return [true, null, ['type' => $type, 'bits' => (int) $d['bits'], 'fingerprint' => $fp]];
}

/** Status of the stored webhook key, for display. */
function wise_key_status(): array
{
    $pem = wise_settings()['webhook_key'];
    if (trim($pem) === '') {
        return ['state' => 'missing', 'label' => 'not set', 'detail' => 'deliveries are accepted but unverified'];
    }
    [$ok, $err, $info] = wise_parse_public_key($pem);
    return $ok
        ? ['state' => 'ok', 'label' => $info['type'] . ' ' . $info['bits'] . '-bit',
           'detail' => 'fingerprint ' . $info['fingerprint']]
        : ['state' => 'bad', 'label' => 'invalid', 'detail' => (string) $err];
}

/** The public URL Wise should POST events to. Shown on the Accounting page. */
function wise_webhook_url(): string
{
    return rtrim((string) public_base_url(), '/') . url('/webhooks/wise');
}

// ─────────────────────────── API client ───────────────────────────

function wise_api_base(): string
{
    return wise_settings()['env'] === 'live'
        ? 'https://api.transferwise.com'
        : 'https://api.sandbox.transferwise.tech';
}

/**
 * Locate a usable `openssl` binary. PATH alone is unreliable — PHP's exec() inherits
 * the web server's (or cmd.exe's) environment, which frequently lacks it even when an
 * interactive shell has it. Returns a command string, or null.
 */
function wise_openssl_binary(): ?string
{
    static $found = false;
    if ($found !== false) {
        return $found;
    }
    $candidates = ['openssl'];
    if (DIRECTORY_SEPARATOR === '\\') {
        $candidates[] = 'C:\\Program Files\\Git\\usr\\bin\\openssl.exe';
        $candidates[] = 'C:\\Program Files\\Git\\mingw64\\bin\\openssl.exe';
        foreach (glob('C:\\wamp64\\bin\\apache\\*\\bin\\openssl.exe') ?: [] as $g) {
            $candidates[] = $g;
        }
        foreach (glob('C:\\xampp\\apache\\bin\\openssl.exe') ?: [] as $g) {
            $candidates[] = $g;
        }
    } else {
        $candidates[] = '/usr/bin/openssl';
        $candidates[] = '/usr/local/bin/openssl';
        $candidates[] = '/opt/homebrew/bin/openssl';
    }
    foreach ($candidates as $c) {
        $out = [];
        $rc = -1;
        @exec(escapeshellarg($c) . ' version 2>&1', $out, $rc);
        if ($rc === 0 && !empty($out[0]) && stripos($out[0], 'openssl') !== false) {
            return $found = $c;
        }
    }
    return $found = null;
}

/**
 * Strong Customer Authentication keypair.
 *
 * Distinct from the webhook key: here WE own the private half and upload the PUBLIC
 * half to Wise (Settings → API tokens → Manage public keys). Wise protects sensitive
 * endpoints — balance statements among them — by answering 403 with an
 * `x-2fa-approval` token, which we sign with the private key and replay.
 *
 * Returns [ok, error, publicPem]. Regenerating invalidates the key already uploaded
 * to Wise, so the caller must warn before overwriting one.
 */
function wise_generate_sca_keypair(): array
{
    $priv = null;
    $pub  = null;

    // Preferred: do it in-process so the private key never touches disk.
    $res = @openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if ($res) {
        openssl_pkey_export($res, $priv);
        $pub = openssl_pkey_get_details($res)['key'] ?? null;
    }

    // Fallback: PHP's openssl_pkey_new() needs an openssl.cnf that many Windows CLI
    // and some minimal hosting builds lack. Shell out, then delete the temp files.
    $why = $res ? 'openssl_pkey_new() gave no usable key' : 'openssl_pkey_new() failed (likely a missing openssl.cnf)';
    if (!$priv || !$pub) {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dp-sca-' . bin2hex(random_bytes(6));
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return [false, $why . '; and the temp directory ' . $dir . ' could not be created.', ''];
        }
        $pk = $dir . DIRECTORY_SEPARATOR . 'k.pem';
        $pu = $dir . DIRECTORY_SEPARATOR . 'k.pub';
        $bin = wise_openssl_binary();
        if ($bin === null) {
            @rmdir($dir);
            return [false, $why . '; and no usable `openssl` binary was found on PATH or in the '
                . 'usual locations. Generate a keypair manually with the command below and store '
                . 'it, or point PHP at a valid openssl.cnf.', ''];
        }
        $ssl = escapeshellarg($bin);
        $o1 = $o2 = [];
        $rc1 = $rc2 = -1;
        @exec($ssl . ' genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out ' . escapeshellarg($pk) . ' 2>&1', $o1, $rc1);
        // A child process wrote the file, so PHP's stat cache must be dropped before
        // is_file()/file_get_contents() — otherwise a freshly created key reads as absent.
        clearstatcache(true, $pk);
        if ($rc1 === 0 && is_file($pk)) {
            @exec($ssl . ' rsa -in ' . escapeshellarg($pk) . ' -pubout -out ' . escapeshellarg($pu) . ' 2>&1', $o2, $rc2);
            clearstatcache(true, $pu);
            if ($rc2 === 0 && is_file($pu)) {
                $priv = (string) file_get_contents($pk);
                $pub  = (string) file_get_contents($pu);
            }
        }
        foreach ([$pk, $pu] as $f) {
            if (is_file($f)) {
                @file_put_contents($f, str_repeat("\0", 64));   // overwrite before unlinking
                @unlink($f);
            }
        }
        @rmdir($dir);
        if (!$priv || !$pub) {
            $why .= '; the openssl command also failed (genpkey exit ' . $rc1 . ', rsa exit ' . $rc2 . ')';
            $tail = trim(implode(' ', array_slice(array_merge($o1, $o2), -2)));
            if ($tail !== '') {
                $why .= ': ' . substr($tail, 0, 200);
            }
        }
    }

    if (!$priv || !$pub) {
        return [false, 'Could not generate a keypair — ' . $why . '. Generate one manually with: '
            . 'openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out wise.key '
            . '&& openssl rsa -in wise.key -pubout -out wise.pub', ''];
    }
    db_exec('UPDATE organizations SET wise_sca_public = ?, wise_sca_private_enc = ?, wise_sca_created_at = ? WHERE id = ?',
        [trim($pub), dp_encrypt($priv), gmdate('Y-m-d H:i:s'), platform_org_id()]);
    return [true, null, trim($pub)];
}

/** Sign a Wise SCA challenge token with our private key (RSA-SHA256, base64). */
function wise_sign_sca(string $token): ?string
{
    $priv = wise_settings()['sca_private'];
    if ($priv === '') {
        return null;
    }
    $key = @openssl_pkey_get_private($priv);
    if (!$key) {
        return null;
    }
    $sig = '';
    return openssl_sign($token, $sig, $key, OPENSSL_ALGO_SHA256) ? base64_encode($sig) : null;
}

/**
 * Call the Wise API. Returns [ok, decodedBody|errorString, httpStatus].
 * Never throws — a payment provider being unreachable must not break a page.
 *
 * Transparently answers Wise's SCA challenge: a protected endpoint replies 403 with
 * an `x-2fa-approval` token; we sign it and replay the identical request with the
 * token plus `X-Signature`. Retried once only, so a persistent rejection surfaces as
 * a real error instead of looping.
 */
function wise_api(string $method, string $path, ?array $body = null, int $timeout = 20, bool $isRetry = false): array
{
    $s = wise_settings();
    if ($s['api_token'] === '') {
        return [false, 'No Wise API token configured.', 0];
    }
    static $scaToken = null;      // carried from the challenge into the retry
    $url = wise_api_base() . $path;

    $headers = ['Authorization: Bearer ' . $s['api_token'], 'Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($isRetry && $scaToken !== null) {
        $sig = wise_sign_sca($scaToken);
        if ($sig === null) {
            return [false, 'Wise asked for Strong Customer Authentication, but no SCA private key is '
                . 'stored (or it could not be read). Generate a keypair on the Accounting page and '
                . 'upload its public key to Wise.', 403];
        }
        $headers[] = 'x-2fa-approval: ' . $scaToken;
        $headers[] = 'X-Signature: ' . $sig;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HEADER         => true,      // needed to read x-2fa-approval
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw       = curl_exec($ch);
    $status    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerLen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err       = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return [false, 'Could not reach Wise: ' . $err, 0];
    }
    $rawHeaders = substr((string) $raw, 0, $headerLen);
    $rawBody    = substr((string) $raw, $headerLen);

    // SCA challenge — sign the one-time token and replay the same request once.
    if ($status === 403 && !$isRetry
        && preg_match('/^x-2fa-approval:\s*(.+)$/mi', $rawHeaders, $m)) {
        $scaToken = trim($m[1]);
        return wise_api($method, $path, $body, $timeout, true);
    }

    $decoded = json_decode($rawBody, true);
    if ($status >= 200 && $status < 300) {
        return [true, $decoded ?? [], $status];
    }
    $msg = is_array($decoded)
        ? ($decoded['error_description'] ?? $decoded['message'] ?? ($decoded['errors'][0]['message'] ?? substr($rawBody, 0, 300)))
        : substr($rawBody, 0, 300);
    if ($status === 403 && $isRetry) {
        $msg .= ' — the SCA signature was rejected. Confirm the public key on the Accounting page '
              . 'is the one uploaded to Wise for this environment.';
    }
    return [false, 'Wise API ' . $status . ': ' . $msg, $status];
}

/** Record the outcome of the last Wise interaction, for the Accounting page. */
function wise_note_result(?string $error): void
{
    db_exec('UPDATE organizations SET wise_last_sync_at = ?, wise_last_error = ? WHERE id = ?',
        [gmdate('Y-m-d H:i:s'), $error !== null ? substr($error, 0, 400) : null, platform_org_id()]);
}

/** Verify the credentials and list the profiles the token can see. */
function wise_test_connection(): array
{
    [$ok, $data] = wise_api('GET', '/v2/profiles');
    if (!$ok) {
        // v2 is not available on every account; fall back to v1.
        [$ok, $data] = wise_api('GET', '/v1/profiles');
    }
    if (!$ok) {
        wise_note_result(is_string($data) ? $data : 'Unknown error');
        return [false, is_string($data) ? $data : 'Unknown error', []];
    }
    wise_note_result(null);
    $profiles = [];
    foreach ((array) $data as $p) {
        $profiles[] = [
            'id'   => (string) ($p['id'] ?? ''),
            'type' => (string) ($p['type'] ?? ''),
            'name' => (string) ($p['fullName'] ?? $p['name']
                        ?? trim(($p['details']['firstName'] ?? '') . ' ' . ($p['details']['lastName'] ?? ''))
                        ?: ($p['details']['name'] ?? '')),
        ];
    }
    return [true, null, $profiles];
}

/** Balances on the configured profile (so the admin can pick the USD one). */
function wise_list_balances(): array
{
    $s = wise_settings();
    if ($s['profile_id'] === '') {
        return [false, 'Set the Wise profile ID first.', []];
    }
    [$ok, $data] = wise_api('GET', '/v4/profiles/' . rawurlencode($s['profile_id']) . '/balances?types=STANDARD');
    if (!$ok) {
        wise_note_result(is_string($data) ? $data : 'Unknown error');
        return [false, is_string($data) ? $data : 'Unknown error', []];
    }
    wise_note_result(null);
    $out = [];
    foreach ((array) $data as $b) {
        $out[] = [
            'id'       => (string) ($b['id'] ?? ''),
            'currency' => (string) ($b['currency'] ?? ''),
            'amount'   => (float) ($b['amount']['value'] ?? 0),
        ];
    }
    return [true, null, $out];
}

// ─────────────────────────── Statement sync ───────────────────────────

/**
 * Pull the balance statement for the last $days and reconcile every credit.
 * This is the authoritative path: the statement carries the payer's reference, which
 * the webhook event does not. Safe to run repeatedly — reconcile_credit() dedupes on
 * the Wise transaction id.
 *
 * Returns [ok, error, ['seen'=>n,'matched'=>n,'unmatched'=>n,'skipped'=>n]].
 */
function wise_sync_statement(int $days = 30): array
{
    $s = wise_settings();
    $stats = ['seen' => 0, 'matched' => 0, 'unmatched' => 0, 'skipped' => 0];
    if ($s['profile_id'] === '' || $s['balance_id'] === '') {
        return [false, 'Set the Wise profile and balance IDs first.', $stats];
    }
    $end   = gmdate('Y-m-d\TH:i:s.000\Z');
    $start = gmdate('Y-m-d\TH:i:s.000\Z', time() - max(1, $days) * 86400);
    $path  = sprintf('/v1/profiles/%s/balance-statements/%s/statement.json?currency=USD&intervalStart=%s&intervalEnd=%s&type=COMPACT',
        rawurlencode($s['profile_id']), rawurlencode($s['balance_id']), rawurlencode($start), rawurlencode($end));

    [$ok, $data] = wise_api('GET', $path, null, 40);
    if (!$ok) {
        wise_note_result(is_string($data) ? $data : 'Unknown error');
        return [false, is_string($data) ? $data : 'Unknown error', $stats];
    }

    foreach ((array) ($data['transactions'] ?? []) as $t) {
        if (strtoupper((string) ($t['type'] ?? '')) !== 'CREDIT') {
            continue;
        }
        $stats['seen']++;
        $amount = (float) ($t['amount']['value'] ?? 0);
        if ($amount <= 0) {
            $stats['skipped']++;
            continue;
        }
        // Wise puts the payer's message in a few different places depending on the rail.
        $d = $t['details'] ?? [];
        $reference = trim((string) ($d['paymentReference'] ?? $d['reference'] ?? $d['description'] ?? ($t['referenceNumber'] ?? '')));
        if ($reference === '' && !empty($d['senderName'])) {
            $reference = (string) $d['senderName'];
        }
        [$matched] = reconcile_credit([
            'tx_id'        => (string) ($t['referenceNumber'] ?? ($d['id'] ?? '')),
            'amount_cents' => (int) round($amount * 100),
            'currency'     => (string) ($t['amount']['currency'] ?? 'USD'),
            'reference'    => $reference,
            'occurred_at'  => isset($t['date']) ? gmdate('Y-m-d H:i:s', strtotime((string) $t['date'])) : gmdate('Y-m-d H:i:s'),
            'raw'          => $t,
        ]);
        $matched ? $stats['matched']++ : $stats['unmatched']++;
    }
    wise_note_result(null);
    return [true, null, $stats];
}

// ─────────────────────────── Webhook subscription ───────────────────────────

/** Ask Wise to POST balance-credit events to this server. Returns [ok, error, id]. */
function wise_register_webhook(): array
{
    $s = wise_settings();
    if ($s['profile_id'] === '') {
        return [false, 'Set the Wise profile ID first.', null];
    }
    $url = wise_webhook_url();
    if (!preg_match('#^https://#i', $url)) {
        return [false, 'Wise only delivers to HTTPS URLs. This server reports ' . $url, null];
    }
    [$ok, $data] = wise_api('POST', '/v3/profiles/' . rawurlencode($s['profile_id']) . '/subscriptions', [
        'name'       => 'DeskPulse balance credits',
        'trigger_on' => 'balances#credit',
        'delivery'   => ['version' => '2.0.0', 'url' => $url],
    ]);
    if (!$ok) {
        wise_note_result(is_string($data) ? $data : 'Unknown error');
        return [false, is_string($data) ? $data : 'Unknown error', null];
    }
    $id = (string) ($data['id'] ?? '');
    db_exec('UPDATE organizations SET wise_webhook_id = ? WHERE id = ?', [$id ?: null, platform_org_id()]);
    wise_note_result(null);
    return [true, null, $id];
}

// ─────────────────────────── Inbound webhook ───────────────────────────

/**
 * Wise signs each delivery with RSA-SHA256 over the raw body; the signature is in
 * X-Signature-SHA256 (v2 deliveries) or X-Signature (v1), base64 encoded. The public
 * key is published by Wise per environment and pasted into the Accounting page.
 *
 * If no key is configured we accept the delivery but mark it unverified — better to
 * record the money and flag it than to silently drop a real payment. The page shows
 * the unverified state so it can't go unnoticed.
 */
function wise_verify_signature(string $rawBody): array
{
    $s = wise_settings();
    $sig = $_SERVER['HTTP_X_SIGNATURE_SHA256'] ?? $_SERVER['HTTP_X_SIGNATURE'] ?? '';
    if ($s['webhook_key'] === '') {
        return [true, 'unverified (no public key configured)'];
    }
    if ($sig === '') {
        return [false, 'missing signature header'];
    }
    $decoded = base64_decode($sig, true);
    if ($decoded === false) {
        return [false, 'signature is not valid base64'];
    }
    $key = openssl_pkey_get_public($s['webhook_key']);
    if (!$key) {
        return [false, 'stored public key could not be parsed'];
    }
    $res = openssl_verify($rawBody, $decoded, $key, OPENSSL_ALGO_SHA256);
    return $res === 1 ? [true, 'verified'] : [false, 'signature did not match'];
}

/** Is the automated Wise API/webhook path currently in use? */
function wise_api_archived(): bool
{
    return wise_settings()['pay_method'] !== 'wise_api';
}

/** POST /webhooks/wise — Wise event receiver. */
function wh_wise(): void
{
    // Archived: the platform collects by direct bank transfer and records payments by
    // hand. Refuse rather than quietly accept, so a stale Wise subscription can't file
    // phantom credits into the ledger. Re-enable by switching the payment method back.
    if (wise_api_archived()) {
        abort(410, 'The Wise API integration is archived. Payments are collected by direct bank transfer.');
    }
    $raw = raw_body();
    $payload = json_decode($raw, true) ?: [];
    $eventType = (string) ($payload['event_type'] ?? $payload['eventType'] ?? '');

    // Wise probes the endpoint when a subscription is created.
    if ($eventType === '' || str_contains($eventType, '#test')) {
        json_out(['ok' => true, 'note' => 'test event acknowledged']);
    }

    [$sigOk, $sigNote] = wise_verify_signature($raw);
    if (!$sigOk) {
        // Record the rejection so a misconfigured key is visible rather than silent.
        wise_note_result('Rejected a webhook delivery: ' . $sigNote);
        abort(401, 'Invalid signature');
    }

    // Dedupe: Wise retries deliveries, and a repeat must not extend a subscription twice.
    $eventId = (string) ($_SERVER['HTTP_X_DELIVERY_ID'] ?? $payload['data']['id'] ?? '');
    if ($eventId === '') {
        $eventId = substr(hash('sha256', $raw), 0, 60);
    }
    try {
        db_exec('INSERT INTO webhook_events (provider, event_id, type) VALUES (?, ?, ?)',
            ['wise', $eventId, substr($eventType, 0, 64)]);
    } catch (\PDOException $e) {
        json_out(['ok' => true, 'note' => 'duplicate delivery ignored']);
    }

    if (!str_contains($eventType, 'balances#credit')) {
        json_out(['ok' => true, 'note' => 'event ignored: ' . $eventType]);
    }

    // The credit event carries the amount but not the payer's reference, so record it
    // and then read the statement, which does, to reconcile it to an organization.
    $d = $payload['data'] ?? [];
    $amount = (float) ($d['amount'] ?? 0);
    $currency = (string) ($d['currency'] ?? 'USD');
    $occurred = !empty($d['occurred_at'])
        ? gmdate('Y-m-d H:i:s', strtotime((string) $d['occurred_at'])) : gmdate('Y-m-d H:i:s');

    $result = ['reconciled' => false, 'note' => $sigNote];
    if ($amount > 0) {
        [$ok, , $stats] = wise_sync_statement(7);
        $result['statement_sync'] = $ok ? $stats : 'failed';
        // If the statement didn't cover it (timing), still record the raw credit so the
        // money is visible on the Accounting page for manual assignment.
        $seen = db_one('SELECT id FROM payments WHERE provider = ? AND amount_cents = ? AND occurred_at >= ?',
            ['wise', (int) round($amount * 100), gmdate('Y-m-d H:i:s', strtotime($occurred) - 3600)]);
        if (!$seen) {
            db_exec("INSERT INTO payments (org_id, provider, amount_cents, currency, reference_raw, occurred_at, matched, raw)
                     VALUES (NULL, 'wise', ?, ?, ?, ?, 0, ?)",
                [(int) round($amount * 100), $currency, 'balances#credit (no reference in event)',
                 $occurred, json_encode($payload)]);
            notify_sales('subscription.payment_unmatched', 'Wise credit received — needs assignment', [
                'Amount'   => number_format($amount, 2) . ' ' . $currency,
                'Received' => $occurred,
                'Action'   => 'Assign it on the Accounting page.',
            ]);
        }
    }
    json_out(['ok' => true] + $result);
}
