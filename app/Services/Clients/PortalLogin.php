<?php

namespace App\Services\Clients;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\User;
use App\Support\Token;
use Illuminate\Support\Facades\Hash;

/**
 * Giving a client a read-only login to their own engagement.
 *
 * Ports create_client_login(). The account is a `client_viewer` in the
 * provider's organization — not a tenant of its own — and `clients.user_id`
 * links the two. That single column is what
 * {@see \App\Support\Visibility::clientFor()} resolves on every request, so a
 * portal login with no client sees nothing at all.
 *
 * The temporary password is returned to the admin to hand over, not emailed:
 * the legacy flow shows it on screen once. `must_change_password` is set, so
 * gate 2 stops them at the change-password form on first sign-in.
 */
class PortalLogin
{
    /**
     * @return array{0: bool, 1: string} [created, message for the admin]
     */
    public function create(int $organizationId, Client $client, string $name, ?string $email): array
    {
        $email = strtolower(trim((string) $email));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [false, 'No login account was created — add a valid contact email first.'];
        }

        // Email is the login, so it is unique across every organization.
        if (User::query()->where('email', $email)->exists()) {
            return [false, 'No login account was created — that email is already registered.'];
        }

        $temporary = Token::random(9);

        $user = User::create([
            'org_id'               => $organizationId,
            'name'                 => substr($name !== '' ? $name : 'Client', 0, 120),
            'email'                => $email,
            'password_hash'        => Hash::make($temporary),
            'role'                 => UserRole::ClientViewer,
            'currency'             => 'USD',
            'must_change_password' => 1,
        ]);

        $client->forceFill(['user_id' => $user->id])->save();

        return [true, "Login created for {$email} — temporary password: {$temporary} "
            . "(they'll be asked to change it on first login)."];
    }

    /**
     * Issue a fresh temporary password for an existing portal login.
     *
     * Scoped to `client_viewer` in this organization: the same form must never
     * be able to reset an employee's password.
     */
    public function resetPassword(int $organizationId, Client $client): ?string
    {
        if (! $client->user_id) {
            return null;
        }

        $temporary = Token::random(9);

        $updated = User::query()
            ->whereKey($client->user_id)
            ->where('org_id', $organizationId)
            ->where('role', UserRole::ClientViewer->value)
            ->update([
                'password_hash'        => Hash::make($temporary),
                'must_change_password' => 1,
            ]);

        return $updated ? $temporary : null;
    }
}
