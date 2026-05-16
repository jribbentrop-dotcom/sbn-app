<?php

namespace App\Console\Commands;

use App\Helpers\ChordName;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-shot cleanup: strip bare "maj" from every chord name stored
 * inside sbn_leadsheets.json_data (Gmaj → G, F#maj → F#, etc.).
 * Renames chordVoicings map keys so existing voicings still resolve.
 */
class NormalizeLeadsheetChordNames extends Command
{
    protected $signature = 'sbn:normalize-leadsheet-chord-names {--dry-run : Show what would change without writing}';

    protected $description = 'Strip bare "maj" from chord names in sbn_leadsheets.json_data';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $rows = DB::table('sbn_leadsheets')->select('id', 'title', 'json_data')->get();

        $touched = 0;
        $totalRenames = 0;

        foreach ($rows as $row) {
            if (empty($row->json_data)) continue;
            $data = json_decode($row->json_data, true);
            if (!is_array($data)) continue;

            $renames = 0;

            $walkMeasures = function (array &$measures) use (&$renames) {
                foreach ($measures as &$measure) {
                    if (!isset($measure['chords']) || !is_array($measure['chords'])) continue;
                    foreach ($measure['chords'] as &$chord) {
                        if (is_array($chord) && isset($chord['name'])) {
                            $orig = $chord['name'];
                            $norm = ChordName::normalize($orig);
                            if ($orig !== $norm) { $chord['name'] = $norm; $renames++; }
                        } elseif (is_string($chord)) {
                            $orig = $chord;
                            $norm = ChordName::normalize($orig);
                            if ($orig !== $norm) { $chord = $norm; $renames++; }
                        }
                    }
                    unset($chord);
                }
                unset($measure);
            };

            if (isset($data['measures']) && is_array($data['measures'])) {
                $walkMeasures($data['measures']);
            }
            if (isset($data['sections']) && is_array($data['sections'])) {
                foreach ($data['sections'] as &$section) {
                    if (isset($section['measures']) && is_array($section['measures'])) {
                        $walkMeasures($section['measures']);
                    }
                }
                unset($section);
            }

            if (!empty($data['chordVoicings']) && is_array($data['chordVoicings'])) {
                $remapped = [];
                foreach ($data['chordVoicings'] as $key => $voicing) {
                    $atIdx = strpos($key, '@');
                    if ($atIdx === false) {
                        $newKey = ChordName::normalize($key);
                    } else {
                        $newKey = ChordName::normalize(substr($key, 0, $atIdx)) . substr($key, $atIdx);
                    }
                    if ($newKey !== $key) $renames++;
                    $remapped[$newKey] = $voicing;
                }
                $data['chordVoicings'] = $remapped;
            }

            if ($renames > 0) {
                $touched++;
                $totalRenames += $renames;
                $this->line("  #{$row->id} {$row->title} — $renames rename(s)");
                if (!$dryRun) {
                    DB::table('sbn_leadsheets')
                        ->where('id', $row->id)
                        ->update([
                            'json_data'  => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'updated_at' => now(),
                        ]);
                }
            }
        }

        $verb = $dryRun ? 'would update' : 'updated';
        $this->info("Done: $verb $touched leadsheet(s), $totalRenames chord-name rename(s).");
        return self::SUCCESS;
    }
}
