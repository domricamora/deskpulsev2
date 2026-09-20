<?php

namespace App\Services\Mail;

use App\Mail\OutboxMessage;
use App\Models\EmailOutbox;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The email outbox: queue a message, and optionally send that specific row now.
 *
 * Every outbound message is written to `email_outbox` first. The row is the
 * durable record, the retry state and the admin-visible email log, so a message
 * that fails to send is still auditable. Ports mail_queue() and mail_send_row().
 *
 * Phase 5 needs only the password-reset path. The cron drain, notices,
 * reminders and attachments arrive with the rest of messaging in Phase 14.
 *
 * @see docs/migration/messaging.md
 */
class Outbox
{
    /** After this many failures a row stops being retried. */
    public const MAX_ATTEMPTS = 5;

    /**
     * Write a queued message. Returns null when the address is unusable, which
     * the legacy mail_queue() signals by returning 0.
     *
     * @param  array<string, mixed>  $options
     */
    public function queue(array $options): ?EmailOutbox
    {
        $to = (string) ($options['to_email'] ?? '');

        if (! self::addressOk($to)) {
            return null;
        }

        $html = (string) ($options['html'] ?? '');
        $text = (string) ($options['text'] ?? '');

        if ($text === '' && $html !== '') {
            $text = self::htmlToText($html);
        }

        return EmailOutbox::create([
            'org_id'        => $options['org_id'] ?? null,
            'user_id'       => $options['user_id'] ?? null,
            'to_email'      => $to,
            'to_name'       => isset($options['to_name'])
                ? mb_substr(self::headerClean((string) $options['to_name']), 0, 160)
                : null,
            'subject'       => mb_substr(self::headerClean((string) ($options['subject'] ?? '(no subject)')), 0, 255),
            'body_html'     => $html,
            'body_text'     => $text,
            'attachments'   => ! empty($options['attachments']) ? json_encode($options['attachments']) : null,
            'kind'          => mb_substr((string) ($options['kind'] ?? 'notice'), 0, 24),
            'status'        => 'queued',
            'scheduled_at'  => $options['scheduled_at'] ?? null,
            'created_by_id' => $options['created_by_id'] ?? null,
        ]);
    }

    /**
     * Send one specific row immediately and record the outcome.
     *
     * Deliberately this row and not "drain the queue": draining sends the oldest
     * queued messages, which need not include this one if there is any backlog —
     * and the caller is sending now precisely because this message cannot wait.
     *
     * Never throws. A password reset that could not be delivered must not turn
     * into a 500 on the form; the row stays queued with the error recorded.
     */
    public function sendNow(EmailOutbox $row): bool
    {
        try {
            Mail::to($row->to_email, $row->to_name ?: null)->send(new OutboxMessage($row));
        } catch (Throwable $e) {
            $attempts = (int) $row->attempts + 1;

            $row->forceFill([
                'status'     => $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'queued',
                'attempts'   => $attempts,
                'last_error' => mb_substr($e->getMessage(), 0, 2000),
            ])->save();

            return false;
        }

        $row->forceFill([
            'status'     => 'sent',
            'attempts'   => (int) $row->attempts + 1,
            'sent_at'    => now(),
            'last_error' => null,
        ])->save();

        return true;
    }

    /* ── Sanitising ──────────────────────────────────────────────────────── */

    public static function addressOk(string $email): bool
    {
        return $email !== ''
            && mb_strlen($email) <= 190
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** Strip anything that could inject a second header. */
    public static function headerClean(string $value): string
    {
        return trim(str_replace(["\r", "\n", "\0"], ' ', $value));
    }

    /** The plain-text alternative, when a caller supplied only HTML. */
    public static function htmlToText(string $html): string
    {
        $withBreaks = preg_replace('#<br\s*/?>|</p>#i', "\n", $html);

        return trim(html_entity_decode(strip_tags((string) $withBreaks), ENT_QUOTES, 'UTF-8'));
    }
}
