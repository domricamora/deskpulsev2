<?php
/**
 * Outbound email: a dependency-free SMTP client plus a durable outbox.
 *
 * Nothing sends synchronously from a page handler. Everything is written to
 * email_outbox first and drained afterwards, so a slow or broken SMTP host can
 * never hang a request, every send is auditable, and failures can be retried.
 *
 * Draining happens three ways, in order of preference:
 *   1. `php server/tools/mail_worker.php` from cron — the reliable option.
 *   2. Opportunistically, a couple of messages per dashboard request, when
 *      config 'mail_autoflush' is on (for shared hosting with no cron).
 *   3. The "Send queued now" button on the Messages page.
 */

const MAIL_MAX_ATTEMPTS = 4;

/**
 * Platform-wide mail settings a super admin can edit at /app/platform/settings.
 * They live on the platform org row (the same place the plan prices and screenshot
 * retention live). A NULL/empty column means "not overridden" and defers to
 * config.php, so a deployment behaves exactly as its config file says until
 * somebody changes it in the UI.
 *
 * Cached for the request; mail_settings_flush() is called after a save.
 */
function platform_mail_settings(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    try {
        $row = db_one("SELECT o.mail_from, o.mail_from_name, o.mail_reply_to, o.mail_notify,
                              o.mail_transport, o.mail_enabled
                       FROM organizations o
                       JOIN users u ON u.org_id = o.id AND u.role = 'super_admin'
                       ORDER BY u.id LIMIT 1");
        foreach ((array) $row as $k => $v) {
            if ($v !== null && $v !== '') {
                $cache[$k] = $v;
            }
        }
    } catch (\Throwable $e) {
        // Pre-migration database, or no super admin yet — fall back to config.php.
        $cache = [];
    }
    return $cache;
}

function mail_settings_flush(): void
{
    // Cheap and rare: the static cache is per-request, so a save just needs the
    // redirect that follows it. Kept explicit so the intent is obvious at call sites.
}

/** Keys a super admin may override from the platform console. */
function mail_overridable_keys(): array
{
    return ['mail_from', 'mail_from_name', 'mail_reply_to', 'mail_notify', 'mail_transport', 'mail_enabled'];
}

function mail_cfg(string $key, $default = null)
{
    if (in_array($key, mail_overridable_keys(), true)) {
        $over = platform_mail_settings();
        if (array_key_exists($key, $over)) {
            return $key === 'mail_enabled' ? (bool) (int) $over[$key] : $over[$key];
        }
    }
    return config($key, $default);
}

/**
 * Which transport delivers the queue.
 *   'mail' — PHP's built-in mail() (the host's sendmail / MTA). No credentials to
 *            manage; delivery and SPF/DKIM alignment are the host's responsibility.
 *   'smtp' — the built-in SMTP client below (authenticated, works from anywhere).
 */
function mail_transport(): string
{
    $t = strtolower((string) mail_cfg('mail_transport', 'mail'));
    return in_array($t, ['mail', 'smtp'], true) ? $t : 'mail';
}

/** Is a usable transport configured? */
function mail_enabled(): bool
{
    // Defaults are the product's own settings, so a deployment whose config.php
    // predates these keys still sends (and can be switched off in the UI).
    if (!mail_cfg('mail_enabled', true)) {
        return false;
    }
    return mail_transport() === 'mail'
        ? function_exists('mail')
        : (bool) mail_cfg('smtp_host');
}

/** Human-readable description of the current transport, for the Messages page. */
function mail_transport_label(): string
{
    if (mail_transport() === 'mail') {
        return 'PHP mail() — the server\'s own MTA';
    }
    $h = (string) mail_cfg('smtp_host', '');
    return $h ? 'SMTP — ' . $h . ':' . (int) mail_cfg('smtp_port', 587) : 'SMTP (not configured)';
}

/**
 * Addresses that must never receive mail: the synthetic ones the payroll import
 * mints for employees who only exist in a spreadsheet.
 */
function mail_address_ok(?string $email): bool
{
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    return !is_synthetic_email($email);
}

/** Strip CR/LF from anything that goes into a header — basic injection defence. */
function mail_header_clean(string $s): string
{
    return trim(preg_replace('/[\r\n]+/', ' ', $s));
}

/** RFC 2047 encode a header value when it isn't plain ASCII. */
function mail_encode_header(string $s): string
{
    $s = mail_header_clean($s);
    if (preg_match('/^[\x20-\x7E]*$/', $s)) {
        return $s;
    }
    return '=?UTF-8?B?' . base64_encode($s) . '?=';
}

function mail_format_address(string $email, ?string $name = null): string
{
    $email = mail_header_clean($email);
    if (!$name) {
        return $email;
    }
    return '"' . str_replace('"', '', mail_encode_header($name)) . '" <' . $email . '>';
}

/**
 * Queue a message. $opts: to_email, to_name, subject, html, text, attachments
 * (list of ['path'=>abs,'name'=>…,'mime'=>…]), kind, org_id, user_id,
 * created_by_id, scheduled_at.
 * Returns the outbox id, or 0 when the address is unusable.
 */
function mail_queue(array $opts): int
{
    $to = (string) ($opts['to_email'] ?? '');
    if (!mail_address_ok($to)) {
        return 0;
    }
    $html = (string) ($opts['html'] ?? '');
    $text = (string) ($opts['text'] ?? '');
    if ($text === '' && $html !== '') {
        $text = trim(html_entity_decode(strip_tags(preg_replace('#<br\s*/?>|</p>#i', "\n", $html)), ENT_QUOTES, 'UTF-8'));
    }
    return (int) db_exec(
        'INSERT INTO email_outbox (org_id, user_id, to_email, to_name, subject, body_html, body_text,
                attachments, kind, status, scheduled_at, created_by_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$opts['org_id'] ?? null, $opts['user_id'] ?? null, $to,
         isset($opts['to_name']) ? substr(mail_header_clean((string) $opts['to_name']), 0, 160) : null,
         substr(mail_header_clean((string) ($opts['subject'] ?? '(no subject)')), 0, 255),
         $html, $text,
         !empty($opts['attachments']) ? json_encode($opts['attachments']) : null,
         substr((string) ($opts['kind'] ?? 'notice'), 0, 24), 'queued',
         $opts['scheduled_at'] ?? null, $opts['created_by_id'] ?? null]
    );
}

/** Render an email body inside the shared HTML shell. */
function mail_render_html(array $vars): string
{
    return render('email/layout', $vars);
}

/**
 * Internal alert to the sales/ops address (config 'mail_notify') — new signups and
 * subscription activity. Fire-and-forget: it queues like any other message, and a
 * failure here must never break the customer-facing action that triggered it.
 *
 * $facts is an ordered [label => value] map rendered as a simple table.
 */
function notify_sales(string $event, string $subject, array $facts, ?int $orgId = null): int
{
    $to = trim((string) mail_cfg('mail_notify', 'sales@deskpulse.click'));
    if ($to === '' || !mail_address_ok($to)) {
        return 0;
    }
    try {
        $facts = array_merge($facts, [
            'Event'  => $event,
            'When'   => gmdate('D, d M Y H:i') . ' UTC',
            'Server' => (string) public_base_url(),
        ]);
        $rows = '';
        $text = '';
        foreach ($facts as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $rows .= '<tr><td style="padding:4px 14px 4px 0;color:#64748b;white-space:nowrap">'
                   . e((string) $k) . '</td><td style="padding:4px 0"><b>' . e((string) $v) . '</b></td></tr>';
            $text .= $k . ': ' . $v . "\n";
        }
        return mail_queue([
            'org_id'   => $orgId,
            'to_email' => $to,
            'to_name'  => 'DeskPulse sales',
            'subject'  => $subject,
            'text'     => $text,
            'html'     => mail_render_html([
                'org'       => ['name' => 'DeskPulse'],
                'heading'   => $subject,
                'body_html' => '<table style="border-collapse:collapse;font-size:14px">' . $rows . '</table>',
                'cta_url'   => rtrim((string) public_base_url(), '/') . url('/app/platform'),
                'cta_label' => 'Open the platform console',
            ]),
            'kind'     => 'system',
        ]);
    } catch (\Throwable $e) {
        return 0;   // never let an internal alert break a signup or a payment
    }
}

/**
 * Send one queued row. Returns [ok, error]. Marks the row sent/failed and bumps
 * attempts; a row that has burned through MAIL_MAX_ATTEMPTS stays 'failed'.
 */
function mail_send_row(array $row): array
{
    if (!mail_enabled()) {
        $why = mail_transport() === 'mail'
            ? 'Mail is disabled (set mail_enabled = true in config.php), or PHP has no mail() function.'
            : 'No SMTP transport configured (set smtp_host and mail_enabled in config.php).';
        db_exec("UPDATE email_outbox SET status = 'failed', attempts = attempts + 1,
                        last_error = ? WHERE id = ?", [$why, $row['id']]);
        return [false, $why];
    }
    $attachments = [];
    foreach (json_decode((string) $row['attachments'], true) ?: [] as $a) {
        $path = $a['path'] ?? '';
        if ($path && is_file($path)) {
            $attachments[] = ['name' => $a['name'] ?? basename($path),
                              'mime' => $a['mime'] ?? 'application/octet-stream',
                              'data' => (string) file_get_contents($path)];
        }
    }
    $send = mail_transport() === 'mail' ? 'php_mail_send' : 'smtp_send';
    [$ok, $err] = $send(
        (string) $row['to_email'], (string) ($row['to_name'] ?? ''),
        (string) $row['subject'], (string) $row['body_html'], (string) $row['body_text'], $attachments
    );
    if ($ok) {
        db_exec("UPDATE email_outbox SET status = 'sent', attempts = attempts + 1,
                        sent_at = ?, last_error = NULL WHERE id = ?",
            [gmdate('Y-m-d H:i:s'), $row['id']]);
        return [true, null];
    }
    $attempts = (int) $row['attempts'] + 1;
    db_exec('UPDATE email_outbox SET status = ?, attempts = ?, last_error = ? WHERE id = ?',
        [$attempts >= MAIL_MAX_ATTEMPTS ? 'failed' : 'queued', $attempts, substr((string) $err, 0, 2000), $row['id']]);
    return [false, $err];
}

/** Drain up to $limit due messages. Returns [sent, failed]. */
function mail_flush(int $limit = 10): array
{
    if (!mail_enabled()) {
        return [0, 0];
    }
    $rows = db_all(
        "SELECT * FROM email_outbox
         WHERE status = 'queued' AND attempts < ?
           AND (scheduled_at IS NULL OR scheduled_at <= ?)
         ORDER BY id ASC LIMIT " . max(1, min(200, $limit)),
        [MAIL_MAX_ATTEMPTS, gmdate('Y-m-d H:i:s')]
    );
    $sent = $failed = 0;
    foreach ($rows as $r) {
        [$ok] = mail_send_row($r);
        $ok ? $sent++ : $failed++;
    }
    return [$sent, $failed];
}

/**
 * Opportunistic drain from a normal page load, for hosts without cron. Kept to a
 * couple of messages so it never becomes a visible page delay, and skipped
 * entirely unless the admin opted in.
 */
function mail_maybe_flush(): void
{
    static $done = false;
    if ($done || !mail_cfg('mail_autoflush', false) || !mail_enabled()) {
        return;
    }
    $done = true;
    try {
        mail_flush(2);
    } catch (\Throwable $e) {
        // Never let mail trouble break a page.
    }
}

// ─────────────────────────── MIME ───────────────────────────

/**
 * Build the message as separate header lines + body, so both transports can use it.
 *
 * $includeToSubject is false for PHP's mail(), which takes the recipient and the
 * subject as its own arguments — repeating them in the header block would produce
 * a duplicated To:/Subject: in the delivered mail.
 */
function mail_build_parts(string $to, string $toName, string $subject,
                          string $html, string $text, array $attachments,
                          bool $includeToSubject = true): array
{
    $fromEmail = (string) mail_cfg('mail_from', 'support@deskpulse.click');
    $fromName  = (string) mail_cfg('mail_from_name', 'DeskPulse');
    $replyTo   = (string) mail_cfg('mail_reply_to', 'support@deskpulse.click');

    $altBoundary = 'dp-alt-' . bin2hex(random_bytes(8));
    $mixBoundary = 'dp-mix-' . bin2hex(random_bytes(8));

    $headers = [
        'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (parse_url((string) public_base_url(), PHP_URL_HOST) ?: 'deskpulse') . '>',
        'From: ' . mail_format_address($fromEmail, $fromName),
    ];
    if ($includeToSubject) {
        $headers[] = 'To: ' . mail_format_address($to, $toName ?: null);
        $headers[] = 'Subject: ' . mail_encode_header($subject);
    }
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'X-Mailer: DeskPulse';
    if ($replyTo) {
        $headers[] = 'Reply-To: ' . mail_header_clean($replyTo);
    }

    $alt = "--$altBoundary\r\n"
         . "Content-Type: text/plain; charset=UTF-8\r\n"
         . "Content-Transfer-Encoding: base64\r\n\r\n"
         . chunk_split(base64_encode($text !== '' ? $text : strip_tags($html))) . "\r\n";
    if ($html !== '') {
        $alt .= "--$altBoundary\r\n"
              . "Content-Type: text/html; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($html)) . "\r\n";
    }
    $alt .= "--$altBoundary--\r\n";

    if (!$attachments) {
        $headers[] = "Content-Type: multipart/alternative; boundary=\"$altBoundary\"";
        return ['headers' => $headers, 'body' => $alt];
    }

    $headers[] = "Content-Type: multipart/mixed; boundary=\"$mixBoundary\"";
    $body = "--$mixBoundary\r\n"
          . "Content-Type: multipart/alternative; boundary=\"$altBoundary\"\r\n\r\n"
          . $alt;
    foreach ($attachments as $a) {
        $name = mail_header_clean((string) ($a['name'] ?? 'attachment'));
        $body .= "--$mixBoundary\r\n"
               . 'Content-Type: ' . mail_header_clean((string) ($a['mime'] ?? 'application/octet-stream'))
               . "; name=\"$name\"\r\n"
               . "Content-Transfer-Encoding: base64\r\n"
               . "Content-Disposition: attachment; filename=\"$name\"\r\n\r\n"
               . chunk_split(base64_encode((string) $a['data'])) . "\r\n";
    }
    $body .= "--$mixBoundary--\r\n";
    return ['headers' => $headers, 'body' => $body];
}

/** Full RFC 5322 message (headers + body) — used by the SMTP transport. */
function mail_build_message(string $to, string $toName, string $subject,
                            string $html, string $text, array $attachments): string
{
    $p = mail_build_parts($to, $toName, $subject, $html, $text, $attachments, true);
    return implode("\r\n", $p['headers']) . "\r\n\r\n" . $p['body'];
}

// ─────────────────────────── Transport: PHP mail() ───────────────────────────

/**
 * Deliver via PHP's built-in mail(). Returns [ok, error].
 *
 * The envelope sender is set with the "-f" additional parameter so bounces come
 * back to mail_from and the envelope aligns with SPF — without it many hosts send
 * as the web-server user (www-data@host) and the mail is treated as spoofed.
 * Hosts running in safe mode, or with a restricted sendmail_path, reject the
 * parameter, so the call is retried once without it rather than failing outright.
 */
function php_mail_send(string $to, string $toName, string $subject,
                       string $html, string $text, array $attachments = []): array
{
    if (!function_exists('mail')) {
        return [false, 'PHP mail() is not available on this server.'];
    }
    if (!mail_address_ok($to)) {
        return [false, 'Refusing to send to an unusable address: ' . $to];
    }

    $from = (string) mail_cfg('mail_from', 'support@deskpulse.click');
    $parts = mail_build_parts($to, $toName, $subject, $html, $text, $attachments, false);
    $headerBlock = implode("\r\n", $parts['headers']);
    $encSubject  = mail_encode_header($subject);

    // Windows builds hand the message straight to an SMTP server and need CRLF;
    // sendmail on Unix accepts CRLF too, so one form works everywhere.
    $ok = @mail($to, $encSubject, $parts['body'], $headerBlock, '-f' . $from);
    if (!$ok) {
        $ok = @mail($to, $encSubject, $parts['body'], $headerBlock);
    }
    if ($ok) {
        return [true, null];
    }
    $last = error_get_last();
    $detail = $last && !empty($last['message']) ? ' — ' . $last['message'] : '';
    return [false, 'PHP mail() returned false (the local MTA rejected or could not queue the message)' . $detail];
}

// ─────────────────────────── SMTP ───────────────────────────

/** Read one SMTP reply, following multi-line continuations ("250-" vs "250 "). */
function smtp_read($fp): string
{
    $out = '';
    while (($line = fgets($fp, 1024)) !== false) {
        $out .= $line;
        // A final line has a space in the 4th position; continuations have '-'.
        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }
    return $out;
}

function smtp_cmd($fp, string $cmd, string $expect): array
{
    if ($cmd !== '') {
        fwrite($fp, $cmd . "\r\n");
    }
    $reply = smtp_read($fp);
    $code = substr(trim($reply), 0, 3);
    if (strpos($expect, $code) === false) {
        $shown = $cmd !== '' && stripos($cmd, 'AUTH') === false && !preg_match('/^[A-Za-z0-9+\/=]+$/', $cmd)
            ? explode(' ', $cmd)[0] : 'AUTH';
        return [false, "SMTP $shown failed: " . trim($reply)];
    }
    return [true, $reply];
}

/**
 * Deliver one message over SMTP. Returns [ok, error].
 * Supports implicit TLS (smtps, usually port 465) and STARTTLS (usually 587).
 */
function smtp_send(string $to, string $toName, string $subject,
                   string $html, string $text, array $attachments = []): array
{
    $host    = (string) mail_cfg('smtp_host', '');
    $port    = (int) mail_cfg('smtp_port', 587);
    $secure  = strtolower((string) mail_cfg('smtp_secure', 'tls'));   // none | tls (STARTTLS) | ssl
    $user    = (string) mail_cfg('smtp_user', '');
    $pass    = (string) mail_cfg('smtp_pass', '');
    $timeout = (int) mail_cfg('smtp_timeout', 20);
    $from    = (string) mail_cfg('mail_from', 'no-reply@deskpulse.click');

    if (!$host) {
        return [false, 'smtp_host is not configured.'];
    }
    if (!mail_address_ok($to)) {
        return [false, 'Refusing to send to an unusable address: ' . $to];
    }

    $dsn = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $ctx = stream_context_create(['ssl' => ['SNI_enabled' => true]]);
    $fp = @stream_socket_client($dsn, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        return [false, "Could not connect to $dsn: $errstr ($errno)"];
    }
    stream_set_timeout($fp, $timeout);

    try {
        $ehlo = 'EHLO ' . (parse_url((string) public_base_url(), PHP_URL_HOST) ?: 'deskpulse.local');
        [$ok, $err] = smtp_cmd($fp, '', '220');            // greeting
        if (!$ok) { return [false, $err]; }
        [$ok, $err] = smtp_cmd($fp, $ehlo, '250');
        if (!$ok) { return [false, $err]; }

        if ($secure === 'tls') {
            [$ok, $err] = smtp_cmd($fp, 'STARTTLS', '220');
            if (!$ok) { return [false, $err]; }
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            if (!@stream_socket_enable_crypto($fp, true, $crypto)) {
                return [false, 'STARTTLS negotiation failed.'];
            }
            [$ok, $err] = smtp_cmd($fp, $ehlo, '250');     // must re-EHLO after TLS
            if (!$ok) { return [false, $err]; }
        }

        if ($user !== '') {
            [$ok, $err] = smtp_cmd($fp, 'AUTH LOGIN', '334');
            if (!$ok) { return [false, $err]; }
            [$ok, $err] = smtp_cmd($fp, base64_encode($user), '334');
            if (!$ok) { return [false, 'SMTP username rejected.']; }
            [$ok, $err] = smtp_cmd($fp, base64_encode($pass), '235');
            if (!$ok) { return [false, 'SMTP authentication failed.']; }
        }

        [$ok, $err] = smtp_cmd($fp, 'MAIL FROM:<' . mail_header_clean($from) . '>', '250');
        if (!$ok) { return [false, $err]; }
        [$ok, $err] = smtp_cmd($fp, 'RCPT TO:<' . mail_header_clean($to) . '>', '250 251');
        if (!$ok) { return [false, $err]; }
        [$ok, $err] = smtp_cmd($fp, 'DATA', '354');
        if (!$ok) { return [false, $err]; }

        $message = mail_build_message($to, $toName, $subject, $html, $text, $attachments);
        // Dot-stuffing: a line that is just "." would end the DATA block early.
        $message = preg_replace('/^\./m', '..', str_replace("\n", "\r\n", str_replace("\r\n", "\n", $message)));
        fwrite($fp, $message . "\r\n.\r\n");
        [$ok, $err] = smtp_cmd($fp, '', '250');
        if (!$ok) { return [false, $err]; }

        @smtp_cmd($fp, 'QUIT', '221');
        return [true, null];
    } catch (\Throwable $e) {
        return [false, 'SMTP error: ' . $e->getMessage()];
    } finally {
        @fclose($fp);
    }
}
