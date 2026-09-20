<?php

namespace App\Support;

use Illuminate\Http\RedirectResponse;

/**
 * Where an authenticated request is sent when it may not stay where it is.
 *
 * @see docs/migration/authorization.md §4
 */
class Access
{
    /**
     * An authenticated user reached a page their role cannot access.
     *
     * Commonly after switching accounts, or following a stale link, bookmark or
     * back button. Instead of a hard 403, bounce them to their own dashboard
     * (/app routes each role to its home) with a notice. Unauthenticated users
     * are redirected to /login upstream, so this never strands a signed-out
     * visitor. Ports deny_access().
     *
     * This is why the capability middleware returns a redirect and not abort(403)
     * — a 403 here would be a visible behaviour change on every stale link.
     */
    public static function deny(): RedirectResponse
    {
        Flash::error("You don't have access to that page.");

        return redirect('/app');
    }

    /**
     * Sanitise a `?next=` destination into a path on this site.
     *
     * The legacy check is `$next[0] === '/'`, which accepts `//evil.com` — a
     * protocol-relative URL the browser follows off-site. That is an open
     * redirect whenever DeskPulse is installed at a domain root, so this
     * additionally rejects a leading `//` or `/\`. It is a deliberate deviation,
     * recorded in docs/migration/authentication.md §3; no legitimate `next`
     * value is affected.
     */
    public static function internalPath(?string $next, string $fallback = '/app'): string
    {
        $next = (string) $next;

        if ($next === '' || $next[0] !== '/') {
            return $fallback;
        }

        // `//host` and `/\host` are both read as scheme-relative by browsers.
        if (str_starts_with($next, '//') || str_starts_with($next, '/\\')) {
            return $fallback;
        }

        return $next;
    }
}
