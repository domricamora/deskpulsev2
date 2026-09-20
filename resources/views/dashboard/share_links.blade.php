@extends('layouts.app')

{{--
    Share links. Ported from server/templates/dashboard/share_links.php.

    A share URL is a capability token: anybody holding it can read that
    person's summary without signing in. The page says so, because the most
    likely way one leaks is somebody pasting it somewhere public without
    realising it needs no password.
--}}

@section('content')

    <div class="panel">
        <h3>Your personal link</h3>
        <p class="muted">A read-only activity summary — stats and graphs only. It shows
            <b>no screenshots</b> and no pay information, and it needs no sign-in, so treat the
            URL itself as the password.</p>
        @if ($self['token'])
            <div class="row-form">
                <input class="grow" type="text" readonly value="{{ url('/share/' . $self['token']) }}">
                <a class="btn ghost" target="_blank" href="{{ url('/share/' . $self['token']) }}">Open</a>
            </div>
        @else
            <p class="muted">No personal link yet.</p>
        @endif
    </div>

    @forelse ($groups as $group)
        <div class="panel">
            <h3>{{ $group['name'] }} <span class="muted small">— {{ count($group['members']) }} link(s)</span></h3>
            <table class="data">
                <thead><tr><th>Member</th><th>Role</th><th>Share link</th><th></th></tr></thead>
                <tbody>
                    @foreach ($group['members'] as $member)
                        <tr>
                            <td>{{ $member['name'] }}</td>
                            <td><span class="tag">{{ $member['role']?->label() }}</span></td>
                            <td class="small">
                                @if ($member['token'])
                                    <code>{{ url('/share/' . $member['token']) }}</code>
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                            <td>
                                @if ($member['token'])
                                    <a class="lnk" target="_blank" href="{{ url('/share/' . $member['token']) }}">open</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @empty
        <div class="panel"><div class="empty-state">No other members to share.</div></div>
    @endforelse

@endsection
