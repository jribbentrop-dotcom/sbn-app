<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sweep orphaned audio-stem audition sessions (storage/app/stems/{session}).
// Harmless if no scheduler daemon is running — the command is also manual.
Schedule::command('sbn:sweep-stem-sessions --hours=6')->hourly();
