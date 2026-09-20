<?php

namespace App\Services\Mail;

use Throwable;

/**
 * Internal alerts to sales — a new signup, a payment claim, a cancellation.
 *
 * Queued, and never fatal: a failure to tell ourselves about a signup must not
 * fail the signup.
 *
 * @see docs/migration/messaging.md
 */
class SalesNotifier
{
    public function __construct(private readonly Outbox $outbox) {}

    /** @param array<string, mixed> $facts */
    public function notify(string $event, string $subject, array $facts, ?int $organizationId = null): void
    {
        $to = trim((string) config('deskpulse.mail.notify', ''));

        if (! Outbox::addressOk($to)) {
            return;
        }

        try {
            $facts = array_merge($facts, [
                'Event'  => $event,
                'When'   => gmdate('D, d M Y H:i') . ' UTC',
                'Server' => url('/'),
            ]);

            $rows = '';
            $text = '';

            foreach ($facts as $label => $value) {
                if ($value === null || $value === '') {
                    continue;
                }

                $rows .= '<tr><td style="padding:4px 14px 4px 0;color:#64748b;white-space:nowrap">'
                    . e((string) $label) . '</td><td style="padding:4px 0"><b>'
                    . e((string) $value) . '</b></td></tr>';
                $text .= $label . ': ' . $value . "\n";
            }

            $this->outbox->queue([
                'org_id'   => $organizationId,
                'to_email' => $to,
                'to_name'  => 'DeskPulse sales',
                'subject'  => $subject,
                'text'     => $text,
                'html'     => view('email.layout', [
                    'org'      => ['name' => 'DeskPulse'],
                    'heading'  => $subject,
                    'bodyHtml' => '<table style="border-collapse:collapse;font-size:14px">' . $rows . '</table>',
                    'ctaUrl'   => url('/app/platform'),
                    'ctaLabel' => 'Open the platform console',
                ])->render(),
            ]);
        } catch (Throwable) {
            // Never break the request that triggered it.
        }
    }
}
