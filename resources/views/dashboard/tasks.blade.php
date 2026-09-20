@extends('layouts.app')

{{--
    Tasks. Ported from server/templates/dashboard/tasks.php.

    $canManage is true only for a member — the person who owns the work. Every
    other role gets the read-only table. See TaskController for why that split
    is by role rather than by capability.
--}}

@section('content')

    @if ($canManage)
        <div class="panel">
            <h3>Add a task</h3>
            <p class="muted">You manage your own tasks here or in the desktop app, and pick the one
                you're working on. Time tracked while a task is selected rolls up below.</p>
            <form method="post" action="{{ url('/app/tasks') }}" class="row-form">
                @csrf
                <input type="hidden" name="action" value="add">
                <label class="grow">Task <input type="text" name="title" placeholder="e.g. Build login page" required></label>
                <label>Client / company
                    <select name="client_id">
                        <option value="">— none —</option>
                        @foreach ($clients as $client)
                            <option value="{{ $client->id }}">{{ $client->name }}</option>
                        @endforeach
                    </select>
                </label>
                <button class="btn" type="submit">Add task</button>
            </form>
        </div>
    @else
        <p class="muted">Tasks are created and managed by each agent. This is a read-only view with
            <b>time spent per task</b>.</p>
    @endif

    <div class="panel">
        <h3>Tasks &amp; time spent</h3>

        @if ($canManage)
            {{-- The owner's view: every task is editable in place. --}}
            <div class="task-list">
                @forelse ($tasks as $task)
                    @php $tracked = $timeByTask[(int) $task->id] ?? ['secs' => 0, 'cnt' => 0]; @endphp
                    <div class="task-row">
                        <form method="post" action="{{ url('/app/tasks') }}" class="task-edit">
                            @csrf
                            <input type="hidden" name="action" value="edit">
                            <input type="hidden" name="task_id" value="{{ $task->id }}">
                            <input type="text" name="title" value="{{ $task->title }}" aria-label="Task title">
                            <select name="client_id" aria-label="Client">
                                <option value="">— no client —</option>
                                @foreach ($clients as $client)
                                    <option value="{{ $client->id }}" @selected((int) $task->client_id === (int) $client->id)>{{ $client->name }}</option>
                                @endforeach
                            </select>
                            <select name="status" aria-label="Status">
                                <option value="open" @selected($task->status !== 'done')>open</option>
                                <option value="done" @selected($task->status === 'done')>done</option>
                            </select>
                            <span class="muted small nowrap">{{ \App\Support\Format::hms($tracked['secs']) }} · {{ $tracked['cnt'] }} sess</span>
                            <button class="btn sm" type="submit">Save</button>
                        </form>
                        <form method="post" action="{{ url('/app/tasks') }}" class="task-del"
                              data-confirm="Remove this task?">
                            @csrf
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="task_id" value="{{ $task->id }}">
                            <button class="lnk danger" type="submit">delete</button>
                        </form>
                    </div>
                @empty
                    <p class="muted small">No tasks yet — add one above.</p>
                @endforelse
            </div>
        @else
            {{-- Manager, admin or client portal: read-only stats. --}}
            <table class="data">
                <thead>
                <tr><th>Task</th><th>Owner</th><th>Status</th><th>Sessions</th><th>Time spent</th></tr>
                </thead>
                <tbody>
                @forelse ($tasks as $task)
                    @php $tracked = $timeByTask[(int) $task->id] ?? ['secs' => 0, 'cnt' => 0]; @endphp
                    <tr>
                        <td>{{ $task->title }}
                            @if ($task->client_id)
                                <br><small class="muted">{{ $clientNames[$task->client_id] ?? '—' }}</small>
                            @endif
                        </td>
                        <td>{{ $task->owner_name }}</td>
                        <td><span class="status {{ $task->status === 'done' ? 'approved' : 'pending' }}">{{ $task->status }}</span></td>
                        <td>{{ $tracked['cnt'] }}</td>
                        <td><b>{{ \App\Support\Format::hms($tracked['secs']) }}</b></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">No tasks yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        @endif
    </div>

@endsection
