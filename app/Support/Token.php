<?php

namespace App\Support;

/**
 * URL-safe random tokens in the legacy format. Ports random_token().
 *
 * base64url of N random bytes, unpadded — the shape every share link, OAuth
 * state and nonce already in the database has. Kept byte-compatible so a token
 * minted before the migration and one minted after are indistinguishable.
 */
class Token
{
    public static function random(int $bytes = 18): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    /**
     * Lowercase hex, for the desktop agent's device secret.
     *
     * Deliberately NOT the base64url above: the agent signs with
     * `hmac(..).hexdigest()` against the secret exactly as stored, and 32 bytes
     * is the 64-character string every registered device already holds. Ports
     * `bin2hex(random_bytes(32))`.
     */
    public static function hex(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }
}
