<?php

namespace App\Support;

/**
 * Flash messages, in the legacy shape.
 *
 * The legacy app pushes `['msg' => …, 'type' => …]` onto `$_SESSION['flash']`
 * and the layout drains the whole queue with take_flashes(). Several handlers
 * queue more than one message before redirecting, so a single-value bag would
 * lose messages — hence a list rather than session()->flash('status', …).
 *
 * `take()` clears as it reads, which is what makes a message shown on the page
 * that queued it (the SSO-enforced sign-in refusal, for example) not reappear on
 * the next request.
 */
class Flash
{
    public const KEY = 'dp_flash';

    /** @param  'info'|'error'|'success'  $type */
    public static function add(string $message, string $type = 'info'): void
    {
        $queue = session()->get(self::KEY, []);
        $queue[] = ['msg' => $message, 'type' => $type];

        // flash(), not put(): it must survive exactly one redirect and no more.
        session()->flash(self::KEY, $queue);
    }

    public static function error(string $message): void
    {
        self::add($message, 'error');
    }

    public static function success(string $message): void
    {
        self::add($message, 'success');
    }

    /**
     * Drain the queue. Ports take_flashes().
     *
     * @return list<array{msg: string, type: string}>
     */
    public static function take(): array
    {
        $queue = session()->get(self::KEY, []);
        session()->forget(self::KEY);

        return $queue;
    }
}
