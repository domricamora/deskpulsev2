@props(['name'])

@php
    // Loaded once per request; opcache keeps the array warm.
    static $dpIcons = null;
    $dpIcons ??= require resource_path('icons/icons.php');

    $path = $dpIcons[$name] ?? '';
@endphp

@if ($path !== '')
    <svg {{ $attributes->merge(['class' => 'dp-icon']) }}
         viewBox="0 0 24 24"
         fill="none"
         stroke="currentColor"
         stroke-width="1.8"
         stroke-linecap="round"
         stroke-linejoin="round"
         aria-hidden="true">{!! $path !!}</svg>
@endif
