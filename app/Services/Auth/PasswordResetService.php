<?php

namespace App\Services\Auth;

use App\Models\PasswordReset;
use App\Models\User;
use App\Services\Mail\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Password reset, on the legacy `password_resets` table.
 *
 * ── Why this is more than twenty lines ───────────────────────────────────────
 *   • The stored value is a SHA-256 of the token, never the token. A reset token
 *     is a bearer credential: a leaked database or SQL export must not yield
 *     working links.
 *   • The response never reveals whether an address exists. Enumerating which
 *     emails have accounts is the classic leak in this flow, so the limiter
 *     counts attempts for unknown addresses too — otherwise the limiter itself
 *     becomes the oracle.
 *   • Tokens are single-use and short-lived, and every other outstanding token
 *     for the account is burned on use: a second request sitting in the inbox
 *     must not stay usable after a successful reset.
 *
 * Laravel's own password broker is deliberately not used. It has its own table,
 * its own token format and one row per email; swapping to it would break every
 * reset link already in someone's inbox at cutover, and would drop
 * `requested_ip`, which the reset email shows to the recipient.
 *
 * @see docs/migration/authentication.md §5
 */
class PasswordResetService
{
    public function __construct(private readonly Outbox $outbox) {}

    /** Constant-time-comparable lookup key for a token. */
    public static function tokenHash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function ttlMinutes(): int
    {
        return (int) config('deskpulse.password_reset.ttl_minutes', 60);
    }

    /**
     * Handle a reset request.
     *
     * Returns nothing on purpose: every branch — registered address, unknown
     * address, throttled — must be indistinguishable to the caller, so there is
     * nothing for it to report.
     */
    public function request(string $email, string $ip): void
    {
        $perHour = (int) config('deskpulse.password_reset.max_per_hour', 5);
        $ipLimit = $perHour * (int) config('deskpulse.password_reset.ip_multiplier', 3);

        // Rate limit before doing anything else.
        $fromThisIp = PasswordReset::query()
            ->where('requested_ip', $ip)
            ->where('created_at', '>', now()->subHour())
            ->count();

        if ($fromThisIp >= $ipLimit) {
            return;
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            return;
        }

        $forThisUser = PasswordReset::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>', now()->subHour())
            ->count();

        if ($forThisUser >= $perHour) {
            return;
        }

        $this->send($user, $ip);
    }

    /** Mint a token, store its hash, and send the email straight away. */
    public function send(User $user, string $ip): void
    {
        $token = Str::random(64);
        $ttl = $this->ttlMinutes();

        PasswordReset::create([
            'user_id'      => $user->id,
            'token_hash'   => self::tokenHash($token),
            'requested_ip' => $ip,
            'expires_at'   => now()->addMinutes($ttl),
        ]);

        $link = url('/reset-password?token=' . urlencode($token));
        $name = trim((string) $user->name) ?: 'there';

        $row = $this->outbox->queue([
            'org_id'   => $user->org_id,
            'user_id'  => $user->id,
            'to_email' => $user->email,
            'to_name'  => $user->name,
            'kind'     => 'password_reset',
            'subject'  => 'Reset your DeskPulse password',
            'html'     => view('email.password_reset', [
                'name' => $name, 'link' => $link, 'ttl' => $ttl, 'ip' => $ip,
            ])->render(),
        ]);

        // Send THIS message now rather than waiting for the drain: a reset link
        // that arrives ten minutes late is a support ticket, and this deployment
        // has no cron drainer. It is still queued first — the outbox row is the
        // durable record, the retry state and the admin-visible email log.
        if ($row !== null) {
            $this->outbox->sendNow($row);
        }
    }

    /**
     * Look up a token.
     *
     * Returns [match, error]. The error is human-readable so the page can tell
     * expired from already-used from never-valid — the three have completely
     * different remedies, and a single "invalid link" would send someone to
     * support instead of to the "request a new one" button.
     *
     * @return array{0: array{user: User, reset: PasswordReset}|null, 1: string|null}
     */
    public function lookup(string $token): array
    {
        if ($token === '') {
            return [null, 'This reset link is incomplete.'];
        }

        $row = PasswordReset::query()->where('token_hash', self::tokenHash($token))->first();

        if (! $row) {
            return [null, 'This reset link is not valid. It may have been superseded by a newer one.'];
        }

        if ($row->used_at !== null) {
            return [null, 'This reset link has already been used. Request a new one if you still need it.'];
        }

        if ($row->expires_at === null || $row->expires_at->isPast()) {
            return [null, 'This reset link has expired. Reset links are valid for ' . $this->ttlMinutes() . ' minutes.'];
        }

        $user = User::find($row->user_id);

        if (! $user) {
            return [null, 'That account no longer exists.'];
        }

        return [['user' => $user, 'reset' => $row], null];
    }

    /**
     * Set the new password and burn every outstanding token for the account.
     *
     * `must_change_password` is cleared: the person has just chosen their own
     * password, which is exactly what that flag was waiting for.
     */
    public function complete(User $user, PasswordReset $reset, string $password): void
    {
        DB::transaction(function () use ($user, $reset, $password) {
            $user->forceFill([
                'password_hash'        => bcrypt($password),
                'must_change_password' => 0,
            ])->save();

            $reset->forceFill(['used_at' => now()])->save();

            PasswordReset::query()
                ->where('user_id', $user->id)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);
        });
    }
}
