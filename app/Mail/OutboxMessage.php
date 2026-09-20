<?php

namespace App\Mail;

use App\Models\EmailOutbox;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sends an `email_outbox` row.
 *
 * The row is the message: its HTML and plain-text bodies were rendered when it
 * was queued, not when it is sent. That is deliberate and matters — a notice
 * drained three days later must say what it said when it was written, with the
 * organization's branding as it was then.
 *
 * @see docs/migration/messaging.md
 */
class OutboxMessage extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly EmailOutbox $row) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) $this->row->subject);
    }

    public function content(): Content
    {
        return new Content(
            htmlString: (string) $this->row->body_html,
            text: 'email.raw-text',
            with: ['text' => (string) $this->row->body_text],
        );
    }
}
