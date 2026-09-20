{{-- Password reset email. Expects $name, $link, $ttl, $ip. --}}
@php
    $bodyHtml = view('email.partials.password_reset_body', [
        'name' => $name, 'ttl' => $ttl, 'ip' => $ip,
    ])->render();
@endphp
@include('email.layout', [
    'org'       => ['name' => 'DeskPulse'],
    'heading'   => 'Reset your password',
    'bodyHtml'  => $bodyHtml,
    'ctaUrl'    => $link,
    'ctaLabel'  => 'Choose a new password',
])
