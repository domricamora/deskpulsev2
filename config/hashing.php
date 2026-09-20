<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default hash driver
    |--------------------------------------------------------------------------
    |
    | Bcrypt, matching the legacy password_hash($p, PASSWORD_DEFAULT). A hash
    | written here stays verifiable by the legacy app, which a rollback during
    | the migration depends on.
    |
    */

    'driver' => 'bcrypt',

    'bcrypt' => [
        'rounds' => (int) env('BCRYPT_ROUNDS', 12),
        'verify' => true,
        'limit' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rehash on login
    |--------------------------------------------------------------------------
    |
    | OFF, because the legacy handle_login() only calls password_verify() and
    | never rewrites the stored hash.
    |
    | Every existing row was written at PASSWORD_DEFAULT's cost 10 while this
    | application is configured for 12, so leaving this on would rewrite a hash
    | on the first sign-in of every account in the database — a write the app
    | being replaced does not perform, against data the legacy app still shares
    | during the migration.
    |
    | Turning it back on is now safe on its own terms: User::getAuthPasswordName()
    | names the real column, so the write lands in `password_hash`. It is a
    | behaviour change rather than a port, so it is a decision to take
    | deliberately and not a default to inherit.
    |
    */

    'rehash_on_login' => false,

];
