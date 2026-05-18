<?php

namespace App\Console\Commands;

use App\Http\Controllers\Admin\LeadsheetController;
use App\Models\Leadsheet;
use Illuminate\Console\Command;

/**
 * Dev tool — inspect what bass-snap beat correction did to a transcribed
 * leadsheet. Reads the cached transcriptionRaw, recomputes the bass-snapped
 * grid, and prints the original vs corrected beat_times side by side plus the
 * detected bass anchors. No UI, no route — just numbers for spot-checking.
 *
 *   php artisan sbn:bass-snap-debug {leadsheetId}
 */
class BassSnapDebug extends Command
{
    protected $signature = 'sbn:bass-snap-debug {leadsheet : Leadsheet ID}
                            {--limit=64 : Max beats to print}';
    protected $description = 'Show what bass-snap did to a transcribed leadsheet';

    public function handle()
    {
        $leadsheet = Leadsheet::find($this->argument('leadsheet'));
        if (!$leadsheet) {
            $this->error('Leadsheet not found.');
            return 1;
        }

        $raw = $leadsheet->parsed_data['transcriptionRaw'] ?? null;
        if (empty($raw) || empty($raw['beat_times']) || empty($raw['notes'])) {
            $this->error('No cached audio transcription on this leadsheet (transcriptionRaw missing).');
            return 1;
        }

        $orig  = $raw['beat_times'];
        $notes = $raw['notes'];

        $this->info("Leadsheet #{$leadsheet->id}: {$leadsheet->title}");
        $this->line('Original beats: ' . count($orig) . ' · notes: ' . count($notes)
            . ' · bassSnap on import: ' . (!empty($raw['bassSnap']) ? 'yes' : 'no'));

        // Reach the protected bass-snap methods on the controller.
        $ctrl = app(LeadsheetController::class);
        $ref  = new \ReflectionClass($ctrl);

        $snap = $ref->getMethod('bassSnapBeatTimes');
        $snap->setAccessible(true);
        $corrected = $snap->invoke($ctrl, $notes, $orig);

        if ($corrected === $orig) {
            $this->warn('Bass-snap made NO change — too few anchors, or grid already matched.');
            $this->line('(needs >= 3 bass anchors; check note count / register)');
            return 0;
        }

        // Per-beat comparison table.
        $limit = (int) $this->option('limit');
        $rows  = [];
        $maxDelta = 0.0;
        $count = min(count($orig), count($corrected), $limit);
        for ($i = 0; $i < $count; $i++) {
            $delta = $corrected[$i] - $orig[$i];
            $maxDelta = max($maxDelta, abs($delta));
            $bar = (int) floor($i / 4) + 1;
            $beat = ($i % 4) + 1;
            $rows[] = [
                $i,
                "{$bar}.{$beat}",
                number_format($orig[$i], 3),
                number_format($corrected[$i], 3),
                ($delta >= 0 ? '+' : '') . number_format($delta, 3),
            ];
        }

        $this->table(
            ['Beat#', 'Bar.Beat', 'beat_track (s)', 'bass-snap (s)', 'delta (s)'],
            $rows
        );
        $this->info('Max beat shift: ' . number_format($maxDelta, 3) . 's'
            . ($count < count($orig) ? "  (showing first {$count} of " . count($orig) . ')' : ''));
        $this->line('A delta near 0 means beat_track and bass-snap agreed on that beat.');

        return 0;
    }
}
