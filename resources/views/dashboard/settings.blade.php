@extends('layouts.app')

{{--
    Settings. Ported from server/templates/dashboard/settings.php.

    Six panels, four of them behind `org_settings`. The order is the legacy
    order and matters: the agent panel is first because "what do I type into the
    desktop app" is the question this page is opened to answer.

    The inline confirm() calls are data-confirm now (decision D13); the wording
    is unchanged.
--}}

@php
    $weekDays = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
                 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    $payCycles = [
        'semimonthly' => 'Semi-monthly — 1st–15th and 16th–end of month',
        'rolling15'   => 'Every 15 days (fixed blocks from an anchor date)',
        'weekly'      => 'Weekly',
        'biweekly'    => 'Every 14 days (fixed blocks from an anchor date)',
        'monthly'     => 'Monthly',
    ];

    $linkedProviders = $identities->pluck('provider')->all();
@endphp

@section('content')

    <div class="panel">
        <h3>Desktop agent</h3>
        <p class="muted">Install the DeskPulse desktop app and sign in with your DeskPulse
            email &amp; password. It registers a device automatically and starts tracking when
            you press <b>Start</b>.</p>
        <p><b>Server URL to enter in the app:</b><br>
            <code>{{ $publicBase }}</code></p>
        <h4>Registered devices</h4>
        <ul class="plain">
            @forelse ($devices as $device)
                <li class="dev-row">
                    <span>{{ $device->name }} · <small class="muted">last seen
                        @if ($device->last_seen)
                            <x-time :at="$device->getRawOriginal('last_seen')" />
                        @else
                            never
                        @endif
                    </small></span>
                    <form method="post" action="{{ url('/app/settings') }}" style="display:inline"
                        data-confirm="Remove this device? The desktop app on it will have to sign in again.">
                        @csrf
                        <input type="hidden" name="action" value="device_delete">
                        <input type="hidden" name="device_id" value="{{ (int) $device->id }}">
                        <button class="lnk danger" type="submit">remove</button>
                    </form>
                </li>
            @empty
                <li class="muted">No devices yet.</li>
            @endforelse
        </ul>
    </div>

    @if ($isAdmin)
        <div class="panel">
            <h3>Company branding <span class="tag">admin</span></h3>
            <p class="muted">Upload your company logo. It's shown across the dashboard for everyone
                in your organization — admins, managers, agents and client logins — and on the
                desktop agent. Images are converted to WebP and resized automatically (PNG, JPG,
                GIF or WebP; max 8&nbsp;MB).</p>
            @if ($orgLogo)
                <div class="logo-preview"><img src="{{ $orgLogo }}" alt="Current company logo"></div>
            @endif
            <form method="post" action="{{ url('/app/settings') }}" class="row-form" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="action" value="upload_logo">
                <label class="grow">Logo image
                    <input type="file" name="logo" accept="image/png,image/jpeg,image/gif,image/webp" required></label>
                <button class="btn" type="submit">{{ $orgLogo ? 'Replace logo' : 'Upload logo' }}</button>
            </form>
            @if ($orgLogo)
                <form method="post" action="{{ url('/app/settings') }}" style="margin-top:.5rem"
                    data-confirm="Remove the company logo?">
                    @csrf
                    <input type="hidden" name="action" value="remove_logo">
                    <button class="lnk danger" type="submit">remove logo</button>
                </form>
            @endif
        </div>

        <div class="panel">
            <h3>Reporting &amp; payroll period <span class="tag">admin</span></h3>
            <p class="muted">Work is recorded in UTC and shown to each person in their own local time.
                These settings decide the <b>clock every report window is cut in</b> — which sessions
                count as "today", where a week starts, and how your pay periods are chopped up.</p>
            <form method="post" action="{{ url('/app/settings') }}" class="stack">
                @csrf
                <input type="hidden" name="action" value="period_policy">
                <div class="inline">
                    <label>Reporting timezone
                        <select name="report_tz">
                            @foreach (timezone_identifiers_list() as $tzid)
                                <option value="{{ $tzid }}" @selected($periodConfig['tz'] === $tzid)>{{ $tzid }}</option>
                            @endforeach
                        </select>
                        <small class="muted">Day/week/month boundaries are calculated here.</small></label>
                    <label>Week starts on
                        <select name="week_start">
                            @foreach ($weekDays as $n => $label)
                                <option value="{{ $n }}" @selected((int) $periodConfig['week_start'] === $n)>{{ $label }}</option>
                            @endforeach
                        </select></label>
                </div>
                <div class="inline">
                    <label>Payroll cycle
                        <select name="pay_cycle">
                            @foreach ($payCycles as $key => $label)
                                <option value="{{ $key }}" @selected($periodConfig['pay_cycle'] === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <small class="muted">Drives the <b>Pay period</b> pill on Payroll, Payslip and the salary run.</small></label>
                    <label>Cycle anchor date
                        <input type="date" name="pay_cycle_anchor" value="{{ $periodConfig['pay_cycle_anchor'] ?? '' }}">
                        <small class="muted">Only used by the 15-day / 14-day cycles — the first day of any period.</small></label>
                    <label>Payroll currency
                        <input type="text" name="pay_currency" maxlength="8" value="{{ $periodConfig['pay_currency'] }}"></label>
                </div>
                <button class="btn" type="submit">Save period settings</button>
            </form>
            <p class="muted small">Current pay period: <b>{{ $periodConfig['current_label'] }}</b></p>
        </div>

        <div class="panel">
            <h3>Monitoring policy <span class="tag">admin</span></h3>
            <p class="muted">Set centrally for everyone in your organization. The desktop agent
                fetches this and applies it on each new tracking session, overriding local app settings.</p>
            <form method="post" action="{{ url('/app/settings') }}" class="stack">
                @csrf
                <input type="hidden" name="action" value="policy">
                <div class="inline">
                    <label>Screenshot interval
                        <input type="number" name="screenshot_interval_min" min="1" max="120"
                            value="{{ (int) $policy['screenshot_interval_min'] }}"> <small class="muted">minutes</small></label>
                    <label>Idle threshold
                        <input type="number" name="idle_threshold_min" min="1" max="120"
                            value="{{ (int) $policy['idle_threshold_min'] }}"> <small class="muted">minutes</small></label>
                    <label>Sync interval
                        <input type="number" name="sync_interval_s" min="15" max="600"
                            value="{{ (int) $policy['sync_interval_s'] }}"> <small class="muted">seconds</small></label>
                </div>
                <label class="check"><input type="checkbox" name="track_screenshots" @checked($policy['track_screenshots'])> Capture screenshots</label>
                <label class="check"><input type="checkbox" name="screenshot_blur" @checked($policy['screenshot_blur'])> Blur screenshots</label>
                <label class="check"><input type="checkbox" name="track_windows" @checked($policy['track_windows'])> Track active window / apps</label>
                <label class="check"><input type="checkbox" name="track_processes" @checked($policy['track_processes'])> Record running tasks</label>
                <button class="btn" type="submit">Save policy</button>
            </form>
        </div>

        {{--
            Single sign-on, per organization. OIDC only — SAML is deliberately
            unsupported; hand-rolling XML-DSig verification is not worth the risk.
        --}}
        <div class="panel">
            <h3>Single sign-on</h3>
            <p class="muted">Let your team sign in with your own identity provider — Microsoft Entra
                ID, Google Workspace, Okta, Auth0, JumpCloud or anything else that speaks
                <b>OpenID Connect</b>. Add this redirect URI to the application you create there:</p>
            <p><code>{{ $publicBase }}/auth/sso/callback</code></p>

            <form method="post" action="{{ url('/app/settings') }}" class="stack">
                @csrf
                <input type="hidden" name="action" value="sso">
                <div class="price-fields">
                    <label>Issuer URL
                        <input type="url" name="sso_issuer" style="width:320px"
                            placeholder="https://login.microsoftonline.com/&lt;tenant&gt;/v2.0"
                            value="{{ $sso->sso_issuer ?? '' }}">
                        <small class="muted">We read its <code>/.well-known/openid-configuration</code>.</small>
                    </label>
                    <label>Client ID
                        <input type="text" name="sso_client_id" style="width:320px"
                            value="{{ $sso->sso_client_id ?? '' }}">
                    </label>
                    <label>Client secret
                        <input type="password" name="sso_client_secret" style="width:260px"
                            placeholder="{{ $sso?->sso_client_secret ? '•••••••• (stored)' : '' }}"
                            autocomplete="new-password">
                        <small class="muted">Encrypted at rest. Leave blank to keep the current one.</small>
                    </label>
                    <label>Email domains
                        <input type="text" name="sso_domains" style="width:260px" placeholder="acme.com, acme.co.uk"
                            value="{{ $sso->sso_domains ?? '' }}">
                        <small class="muted">Comma separated. People with these domains are offered SSO,
                            and may be provisioned on first sign-in.</small>
                    </label>
                </div>
                <label class="chk"><input type="checkbox" name="sso_enabled" value="1"
                    @checked($sso?->sso_enabled)> Enable single sign-on</label>
                <label class="chk"><input type="checkbox" name="sso_enforce" value="1"
                    @checked($sso?->sso_enforce)> <b>Require</b> it — block password
                    sign-in for everyone in this organization</label>
                <p class="muted small"><b>Test enabling before you enforce.</b> Open a private window and
                    sign in through SSO first. Enforcing a provider that is not working locks out every
                    account in this workspace, including yours.</p>
                <div><button class="btn" type="submit">Save single sign-on</button></div>
            </form>
        </div>
    @endif

    @if ($oauthProviders || count($identities))
        <div class="panel">
            <h3>Connected sign-in accounts</h3>
            @if (count($identities))
                <table class="data" data-nofilter>
                    <thead><tr><th>Provider</th><th>Account</th><th>Linked</th><th>Last used</th></tr></thead>
                    <tbody>
                        @foreach ($identities as $identity)
                            <tr>
                                <td>{{ ucfirst($identity->provider) }}</td>
                                <td>{{ $identity->email ?: '—' }}</td>
                                <td><x-time :at="$identity->created_at" fmt="date" /></td>
                                <td>
                                    @if ($identity->last_login_at)
                                        <x-time :at="$identity->last_login_at" fmt="date" />
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="muted">You sign in with a password. Link a provider to sign in with one click.</p>
            @endif
            <div class="actions-row">
                @foreach ($oauthProviders as $key => $provider)
                    @unless (in_array($key, $linkedProviders, true))
                        <a class="btn sm ghost" href="{{ url('/auth/' . $key) }}">Link {{ $provider['label'] }}</a>
                    @endunless
                @endforeach
            </div>
        </div>
    @endif

@endsection
