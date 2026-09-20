@extends('layouts.app')

{{--
    Agents. Ported from server/templates/dashboard/agents.php.

    One route, two pages. A manager sees assignments and task time; a client
    portal sees the same roster read-only, which is what it lands on straight
    from /app. The copy differs for each, deliberately — "no agents under you"
    and "no agents are assigned to your account" mean different things.
--}}

@section('content')

    @if ($canManage)
        <p class="muted">Manage the agents under you — assign each to one or more clients, and
            create, update or remove their tasks. Click <b>View full details</b> for activity, devices and more.</p>
    @else
        <p class="muted">The team members working on your engagement. Click <b>View full details</b>
            for each agent's activity, tasks and screenshots.</p>
    @endif

    @if (! $agents)
        <div class="panel">
            <div class="empty-state">
                {{ $canManage ? 'No agents under you yet.' : 'No agents are assigned to your account yet.' }}
            </div>
        </div>
    @endif

    @foreach ($agents as $agent)
        <details class="org-panel">
            <summary>
                <span class="op-name">
                    @if ($agent['live'])<span class="dot live"></span>@endif
                    <b>{{ $agent['name'] }}</b>
                    @if ($canManage)<span class="status pending">{{ $agent['role']?->label() }}</span>@endif
                </span>
                <span class="muted small">
                    @if ($canManage)
                        {{ count($agent['tasks']) }} task{{ count($agent['tasks']) === 1 ? '' : 's' }}
                        · {{ count($agent['client_ids']) }} client{{ count($agent['client_ids']) === 1 ? '' : 's' }} ·
                    @endif
                    {{ \App\Support\Format::hms($agent['week_active_s']) }} this week
                </span>
            </summary>
            <div class="op-body">
                <div class="agent-meta">
                    <span class="muted">{{ $agent['email'] }}@if ($agent['job_title']) · {{ $agent['job_title'] }}@endif</span>
                    <a class="btn sm ghost" href="{{ url('/app/agents/' . $agent['id']) }}">View full details &rarr;</a>
                </div>

                @if ($canManage)
                    <h4>Client assignment</h4>
                    @if (count($clients))
                        <form method="post" action="{{ url('/app/agents') }}" class="row-form assign-form">
                            @csrf
                            <input type="hidden" name="action" value="assign_clients">
                            <input type="hidden" name="agent_id" value="{{ $agent['id'] }}">
                            <label class="grow">Clients <span class="muted small">(Ctrl/⌘-click for multiple)</span>
                                <select name="client_ids[]" multiple size="{{ max(3, min(6, count($clients))) }}">
                                    @foreach ($clients as $client)
                                        <option value="{{ (int) $client->id }}"
                                            @selected(in_array((int) $client->id, $agent['client_ids'], true))>{{ $client->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <button class="btn" type="submit">Save clients</button>
                        </form>
                    @else
                        <p class="muted small">No clients yet — add them on the
                            <a href="{{ url('/app/clients') }}">Clients</a> page.</p>
                    @endif

                    <h4>Tasks &amp; time spent <span class="muted small">— managed by the agent</span></h4>
                    <table class="data">
                        <thead><tr><th>Task</th><th>Client</th><th>Status</th><th>Sessions</th><th>Time spent</th></tr></thead>
                        <tbody>
                            @forelse ($agent['tasks'] as $task)
                                @php $spent = $timeByTask[$task->id] ?? ['secs' => 0, 'cnt' => 0]; @endphp
                                <tr>
                                    <td>{{ $task->title }}</td>
                                    <td>{{ $task->client_id ? ($clients->firstWhere('id', $task->client_id)?->name ?? '—') : '—' }}</td>
                                    <td><span class="status {{ $task->status === 'done' ? 'approved' : 'pending' }}">{{ $task->status }}</span></td>
                                    <td>{{ $spent['cnt'] }}</td>
                                    <td><b>{{ \App\Support\Format::hms($spent['secs']) }}</b></td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="muted">No tasks yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                @endif
            </div>
        </details>
    @endforeach

@endsection
