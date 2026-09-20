<?php

namespace App\Services\Oidc;

use App\Models\Organization;
use App\Support\LegacyCipher;

/**
 * Which federated sign-in methods exist right now.
 *
 * Two kinds:
 *
 *   platform providers  google / microsoft, configured once for the whole
 *                       install, offered to everyone
 *   enterprise SSO      the `sso` pseudo-provider, configured per organization
 *                       on its own row, reached with ?org=<id>
 *
 * A platform provider is only offered when BOTH its client id and secret are
 * set. With nothing configured the buttons do not render at all — a fresh
 * install must not show a dead "Continue with Google".
 *
 * @see docs/migration/authentication.md §6
 */
class ProviderRegistry
{
    /** @var array<string, array<string, string>>|null */
    private ?array $providers = null;

    /**
     * Configured platform providers, in the order they are shown on the form.
     *
     * @return array<string, array<string, string>>
     */
    public function providers(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        $out = [];

        foreach ((array) config('deskpulse.oauth.providers', []) as $key => $definition) {
            $id = trim((string) config("services.{$key}.client_id", ''));
            $secret = trim((string) config("services.{$key}.client_secret", ''));

            if ($id !== '' && $secret !== '') {
                $out[$key] = $definition + ['client_id' => $id, 'client_secret' => $secret];
            }
        }

        return $this->providers = $out;
    }

    public function enabled(): bool
    {
        return $this->providers() !== [];
    }

    /**
     * Resolve one provider: a platform provider by key, or an organization's
     * own SSO when the key is `sso`.
     *
     * The stored client secret is `v1:` ciphertext — see LegacyCipher.
     *
     * @return array<string, string>|null
     */
    public function config(string $provider, ?int $organizationId = null): ?array
    {
        if ($provider !== 'sso') {
            return $this->providers()[$provider] ?? null;
        }

        if (! $organizationId) {
            return null;
        }

        $org = Organization::query()
            ->where('id', $organizationId)
            ->first(['sso_enabled', 'sso_issuer', 'sso_client_id', 'sso_client_secret']);

        if (! $org || ! $org->sso_enabled || empty($org->sso_issuer) || empty($org->sso_client_id)) {
            return null;
        }

        return [
            'label'         => 'your organization',
            'issuer'        => (string) $org->sso_issuer,
            'scope'         => 'openid email profile',
            'client_id'     => (string) $org->sso_client_id,
            'client_secret' => LegacyCipher::decrypt((string) ($org->sso_client_secret ?? '')),
        ];
    }

    /** The redirect URI registered with the provider. Must match byte for byte. */
    public function redirectUri(string $provider): string
    {
        return url('/auth/' . $provider . '/callback');
    }

    /**
     * The organization whose SSO claims this email address's domain.
     *
     * Lets someone typing a work address be routed to their employer's identity
     * provider — including after a failed password attempt, which is usually why
     * the password was wrong.
     *
     * Domains are stored as a comma-separated list on the organization row.
     */
    public function ssoOrganizationForEmail(string $email): ?Organization
    {
        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));

        if ($domain === '') {
            return null;
        }

        $candidates = Organization::query()
            ->where('sso_enabled', 1)
            ->whereNotNull('sso_domains')
            ->get(['id', 'name', 'sso_domains', 'sso_enforce']);

        foreach ($candidates as $org) {
            if (in_array($domain, self::domains($org->sso_domains), true)) {
                return $org;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function domains(?string $list): array
    {
        return array_values(array_filter(array_map(
            'trim',
            explode(',', strtolower((string) $list))
        )));
    }
}
