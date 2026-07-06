<?php

namespace App\Console\Commands;

use App\Services\MidiTranscriptionService;
use Illuminate\Console\Command;

/**
 * Sweep orphaned audio-stem audition sessions. Phase 1 of the audio import
 * (separate → audition → transcribe) persists the six Demucs stems under
 * storage/app/stems/{session}/. A completed transcription removes its own
 * session, but an admin who separates then walks away leaves the stems behind.
 * This removes session dirs older than --hours (default 6).
 *
 *   php artisan sbn:sweep-stem-sessions
 *   php artisan sbn:sweep-stem-sessions --hours=1
 *
 * Wire into the scheduler (routes/console.php or bootstrap) hourly if desired.
 */
class SweepStemSessions extends Command
{
    protected $signature = 'sbn:sweep-stem-sessions {--hours=6 : Remove sessions older than this many hours}';
    protected $description = 'Delete orphaned audio stem audition sessions';

    public function handle(MidiTranscriptionService $transcriber): int
    {
        $hours = (int) $this->option('hours');
        $removed = $transcriber->sweepStaleStemSessions($hours);
        $this->info("Swept {$removed} stale stem session(s) older than {$hours}h.");
        return self::SUCCESS;
    }
}
