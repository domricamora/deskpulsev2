@extends('layouts.app')

{{--
    Devices. Ported from server/templates/dashboard/devices.php.

    Revoking deletes the row, which is the entire mechanism: the device signs
    with a secret that no longer exists, every request answers 401, and the
    agent treats that as being signed out. The inline confirm() is data-confirm
    now (decision D13); the wording is unchanged.
--}}

@section('content')

    <div class="panel">
        <h3>Registered devices</h3>
        <p class="muted">Every desktop-agent install across the organization. Revoking a device
            stops it from syncing until the user signs in again.</p>
        <table class="data">
            <thead><tr><th>Device</th><th>User</th><th>Registered</th><th>Last seen</th><th></th></tr></thead>
            <tbody>
                @forelse ($devices as $device)
                    <tr>
                        <td>{{ $device->name }}</td>
                        <td>{{ $device->user_name }}<br><small class="muted">{{ $device->email }}</small></td>
                        <td><x-time :at="$device->getRawOriginal('created_at')" /></td>
                        <td>
                            @if ($device->last_seen)
                                <x-time :at="$device->getRawOriginal('last_seen')" />
                            @else
                                <span class="muted">never</span>
                            @endif
                        </td>
                        <td class="row-actions">
                            @if ($canRemote)
                                <a class="lnk" href="{{ url('/app/remote/' . (int) $device->id) }}">remote control</a>
                            @endif
                            <form method="post" action="{{ url('/app/devices') }}" data-confirm="Revoke this device?">
                                @csrf
                                <input type="hidden" name="action" value="revoke">
                                <input type="hidden" name="device_id" value="{{ (int) $device->id }}">
                                <button class="lnk danger" type="submit">revoke</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">No devices registered yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

@endsection
