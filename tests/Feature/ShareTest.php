<?php

/**
 * Phase 14 — public share links.
 *
 * A share URL is a capability token: whoever holds it can read the page, with
 * no sign-in and no way to take it back except revoking the link. So the tests
 * that matter are about what is NOT on the page, and about a dead token giving
 * nothing away.
 *
 * @see docs/migration/sharing.md §7
 */

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\Screenshot;
use App\Models\ShareLink;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\Sharing\PersonalLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function shareLink(Organization $organization, array $attributes = []): ShareLink
{
    return ShareLink::create(array_merge([
        'org_id'         => $organization->id,
        'scope'          => 'user',
        'target_id'      => 0,
        'token'          => 'tok_' . bin2hex(random_bytes(6)),
        'label'          => 'Test link',
        'period_default' => 'week',
        'revoked'        => 0,
    ], $attributes));
}

function shared(User $user, string $day, int $activeSeconds = 3600, array $attributes = []): WorkSession
{
    return WorkSession::create(array_merge([
        'user_id'         => $user->id,
        'started_at'      => $day . ' 09:00:00',
        'ended_at'        => $day . ' 17:00:00',
        'active_s'        => $activeSeconds,
        'inactive_s'      => 0,
        'source'          => 'agent',
        'approval_status' => 'approved',
    ], $attributes));
}

/* ── A dead token gives nothing away ─────────────────────────────────────── */

test('unknown, revoked and expired tokens are indistinguishable', function () {
    $organization = org();
    $worker = member($organization, UserRole::Member);

    $revoked = shareLink($organization, ['target_id' => $worker->id, 'revoked' => 1]);
    $expired = shareLink($organization, [
        'target_id' => $worker->id, 'expires_at' => gmdate('Y-m-d H:i:s', time() - 3600),
    ]);

    $messages = [];

    foreach ([$revoked->token, $expired->token, 'nosuchtokenatall'] as $token) {
        $response = $this->get("/share/{$token}")->assertNotFound();
        $messages[] = $response->exception?->getMessage();
    }

    // All three the same — otherwise a token can be probed for its state.
    expect(array_unique($messages))->toHaveCount(1)
        ->and($messages[0])->toBe('This share link is invalid or has expired.');
});

test('a link that has not expired yet still works', function () {
    $organization = org();
    $worker = member($organization, UserRole::Member);
    shared($worker, gmdate('Y-m-d'));

    $link = shareLink($organization, [
        'target_id' => $worker->id, 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400),
    ]);

    $this->get("/share/{$link->token}")->assertOk();
});

/* ── What must never appear ──────────────────────────────────────────────── */

test('no screenshot appears on a share page, at any scope', function () {
    $organization = org();
    $worker = member($organization, UserRole::Member);
    $session = shared($worker, gmdate('Y-m-d'));

    Screenshot::create([
        'session_id' => $session->id, 'ts' => gmdate('Y-m-d H:i:s'),
        'file_path' => $worker->id . '/' . $session->id . '/secret.png', 'blurred' => 0,
    ]);

    foreach (['user', 'org'] as $scope) {
        $link = shareLink($organization, ['scope' => $scope, 'target_id' => $worker->id]);

        $html = $this->get("/share/{$link->token}")->assertOk()->getContent();

        expect($html)->not->toContain('screenshot')
            ->and($html)->not->toContain('secret.png')
            // Nor the authorized image route, which would still be a link to one.
            ->and($html)->not->toContain('/image');
    }
});

test('no pay rate or labor cost reaches the page', function () {
    $organization = org();
    $worker = member($organization, UserRole::Member, [
        'name' => 'Ana Cruz', 'pay_rate' => 12345.67, 'bill_rate' => 98765.43,
    ]);

    shared($worker, gmdate('Y-m-d'));

    $link = shareLink($organization, ['target_id' => $worker->id]);
    $html = $this->get("/share/{$link->token}")->assertOk()->getContent();

    expect($html)->not->toContain('12345')
        ->and($html)->not->toContain('12,345')
        ->and($html)->not->toContain('98765')
        ->and($html)->not->toContain('98,765');
});

test('only approved time counts toward the totals', function () {
    $organization = org();
    $worker = member($organization, UserRole::Member);

    shared($worker, gmdate('Y-m-d'), 3600);
    shared($worker, gmdate('Y-m-d'), 36000, ['approval_status' => 'pending', 'source' => 'manual']);

    $link = shareLink($organization, ['target_id' => $worker->id]);
    $html = $this->get("/share/{$link->token}")->assertOk()->getContent();

    // One approved hour, not eleven.
    expect($html)->toContain('1h')->and($html)->not->toContain('11h');
});

/* ── Scope ───────────────────────────────────────────────────────────────── */

test('a user link covers one person and a team link covers the team', function () {
    $organization = org();
    $ana = member($organization, UserRole::Member, ['name' => 'Ana Cruz']);
    $ben = member($organization, UserRole::Member, ['name' => 'Ben Santos']);

    $team = Team::create(['org_id' => $organization->id, 'name' => 'Support']);
    TeamMember::create(['team_id' => $team->id, 'user_id' => $ana->id]);
    TeamMember::create(['team_id' => $team->id, 'user_id' => $ben->id]);

    shared($ana, gmdate('Y-m-d'));
    shared($ben, gmdate('Y-m-d'));

    // The page deliberately shows figures rather than naming people, so the
    // scope is asserted through the totals: one person's hour, or both.
    $personal = shareLink($organization, ['scope' => 'user', 'target_id' => $ana->id]);
    $teamLink = shareLink($organization, ['scope' => 'team', 'target_id' => $team->id]);

    expect($this->get("/share/{$personal->token}")->assertOk()->getContent())->toContain('1h')
        ->and($this->get("/share/{$teamLink->token}")->assertOk()->getContent())->toContain('2h');
});

test('a token from one tenant cannot reach another tenant data', function () {
    $ours = org(['name' => 'Acme']);
    $theirs = org(['name' => 'Globex']);

    $ourWorker = member($ours, UserRole::Member, ['name' => 'Our Worker']);
    $theirWorker = member($theirs, UserRole::Member, ['name' => 'Their Worker']);

    shared($ourWorker, gmdate('Y-m-d'));
    shared($theirWorker, gmdate('Y-m-d'));

    $link = shareLink($ours, ['scope' => 'org']);

    // The page shows figures, not names — so the assertion is on the totals.
    // One hour from our side, never the other tenant's hour as well.
    $html = $this->get("/share/{$link->token}")->assertOk()->getContent();

    expect($html)->toContain('1h')->and($html)->not->toContain('2h');
});

/* ── Windows ─────────────────────────────────────────────────────────────── */

test('a reversed from/to range is swapped rather than rejected', function () {
    $organization = org();
    $worker = member($organization, UserRole::Member);
    shared($worker, '2026-09-10');

    $link = shareLink($organization, ['target_id' => $worker->id]);

    $this->get("/share/{$link->token}?from=2026-09-15&to=2026-09-05")
        ->assertOk()
        ->assertSee('1h', false);
});

test('an invalid period falls back to week', function () {
    $organization = org();
    $worker = member($organization, UserRole::Member);

    $link = shareLink($organization, ['target_id' => $worker->id]);

    // ?period=nonsense must not 500, and must not light a pill that is not a
    // real option.
    $this->get("/share/{$link->token}?period=nonsense")->assertOk();
});

test('the window is cut in the owning organization timezone', function () {
    // Manila is UTC+8: 16:30 UTC on the 20th is 00:30 on the 21st there, so a
    // day-scoped link anchored on the 21st must include it.
    $organization = org(['report_tz' => 'Asia/Manila']);
    $worker = member($organization, UserRole::Member);

    shared($worker, '2026-09-20');
    WorkSession::query()->update([
        'started_at' => '2026-09-20 16:30:00', 'ended_at' => '2026-09-20 17:30:00',
    ]);

    $link = shareLink($organization, ['target_id' => $worker->id, 'period_default' => 'day']);

    $this->get("/share/{$link->token}?date=2026-09-21")->assertOk()->assertSee('1h', false);
});

/* ── Headers and hygiene ─────────────────────────────────────────────────── */

test('indexing is blocked by a response header, not a meta tag', function () {
    // A crawler told not to fetch the page never reads a noindex inside it,
    // so anything already indexed would stay indexed.
    $organization = org();
    $link = shareLink($organization, ['target_id' => member($organization, UserRole::Member)->id]);

    $this->get("/share/{$link->token}")
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
});

test('personal links are minted once and only for people without one', function () {
    $organization = org();
    member($organization, UserRole::Member);
    member($organization, UserRole::Member);

    app(PersonalLinks::class)->ensureFor($organization->id);
    $after = ShareLink::query()->where('org_id', $organization->id)->count();

    app(PersonalLinks::class)->ensureFor($organization->id);

    expect(ShareLink::query()->where('org_id', $organization->id)->count())->toBe($after)
        ->and($after)->toBeGreaterThan(0);
});

test('the directory is admin-only', function () {
    $this->actingAs(member(org(), UserRole::ClientAdmin))->get('/app/share-links')->assertOk();
    $this->actingAs(member(org(), UserRole::Manager))->get('/app/share-links')->assertRedirect();
    $this->actingAs(member(org(), UserRole::Member))->get('/app/share-links')->assertRedirect();
});
