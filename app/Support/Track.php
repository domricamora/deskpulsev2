<?php

namespace App\Support;

/**
 * Analytics events queued for the NEXT page render.
 *
 * A conversion cannot be fired from a handler that ends in a 302: a redirect is
 * invisible to a tag. The event is queued instead and emitted by the layout on
 * the page the browser actually lands on.
 *
 * @see docs/migration/marketing-content.md
 */
class Track
{
    public const SESSION_KEY = 'dp_track';

    /** @param array<string, mixed> $params */
    public static function queue(string $event, array $params = []): void
    {
        $queue = session()->get(self::SESSION_KEY, []);
        $queue[] = [$event, $params];

        session()->flash(self::SESSION_KEY, $queue);
    }

    /** @return list<array{0: string, 1: array<string, mixed>}> */
    public static function take(): array
    {
        $queue = session()->get(self::SESSION_KEY, []);
        session()->forget(self::SESSION_KEY);

        return $queue;
    }
}
