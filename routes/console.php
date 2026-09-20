<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Screenshot retention (decision D12). The legacy app purges opportunistically
 * — one upload in fifty — so retention tracks upload volume rather than the
 * clock, and a quiet organization keeps images past the window. That purge is
 * still in place on upload; this makes it timely.
 *
 * Nightly and capped, so one pass cannot spend the night deleting.
 */
Schedule::command('screenshots:prune')->dailyAt('03:20')->withoutOverlapping();
