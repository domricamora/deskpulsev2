{{--
    Drains the flash queue. Ports the take_flashes() loop in both legacy layouts:
    reading clears, so a message rendered on the page that queued it does not
    reappear on the next request.

    `center` is only applied on the public shell, matching the legacy markup.
--}}
@foreach (\App\Support\Flash::take() as $flash)
    <div class="flash {{ $flash['type'] }} {{ ($centered ?? false) ? 'center' : '' }}">{{ $flash['msg'] }}</div>
@endforeach
