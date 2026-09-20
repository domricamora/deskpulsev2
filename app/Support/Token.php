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
}
