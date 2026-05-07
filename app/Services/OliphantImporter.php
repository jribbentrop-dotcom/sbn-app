<?php

namespace App\Services;

use App\Models\JazzStandard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OliphantImporter — imports the Mike Oliphant JazzStandards JSON.
 *
 * Source: https://raw.githubusercontent.com/mikeoliphant/JazzStandards/main/JazzStandards.json
 *
 * Decisions applied:
 *   - SEPARATE TABLE (sbn_jazz_standards) — no mixing with sbn_leadsheets
 *   - NOT YET exposed in the public frontend
 *   - Progression analysis: ON DEMAND — this importer does NOT run it
 *   - Re-seeding: DEFER — caller truncates first when re-running
 *
 * Chord notation handled by OliphantChordNormalizer (embedded below).
 * ~90% of Oliphant notation is already compatible with our system; the
 * normalizer handles the ~10% edge cases.
 *
 * Usage:
 *   $importer = new OliphantImporter();
 *   $result = $importer->import();
 *   // $result = ['imported' => 1382, 'skipped' => 0, 'errors' => []]
 *
 * To re-import from scratch:
 *   DB::table('sbn_jazz_standards')->truncate();
 *   (new OliphantImporter())->import();
 */
class OliphantImporter
{
    public const SOURCE_URL = 'https://raw.githubusercontent.com/mikeoliphant/JazzStandards/main/JazzStandards.json';

    /** How many records to upsert in one DB call. */
    private int $chunkSize = 50;

    // =========================================================================
    // PUBLIC
    // =========================================================================

    /**
     * Download and import the full Oliphant dataset.
     *
     * @param  string|null $localPath  Optional path to a local JSON file (for tests / offline use).
     * @return array{imported: int, skipped: int, errors: string[]}
     */
    public function import(?string $localPath = null): array
    {
        $raw = $localPath
            ? file_get_contents($localPath)
            : $this->download();

        if (!$raw) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => ['Failed to fetch Oliphant JSON.']];
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => ['JSON decode failed.']];
        }

        $imported = 0;
        $skipped  = 0;
        $errors   = [];

        $batch = [];

        foreach ($data as $entry) {
            try {
                $record = $this->transformEntry($entry);

                if (!$record) {
                    $skipped++;
                    continue;
                }

                $batch[] = $record;

                if (count($batch) >= $this->chunkSize) {
                    $imported += $this->insertBatch($batch, $errors);
                    $batch = [];
                }
            } catch (\Throwable $e) {
                $errors[] = ($entry['Title'] ?? '?') . ': ' . $e->getMessage();
                $skipped++;
            }
        }

        // Flush remaining
        if ($batch) {
            $imported += $this->insertBatch($batch, $errors);
        }

        Log::info("OliphantImporter: imported={$imported}, skipped={$skipped}, errors=" . count($errors));

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    // =========================================================================
    // TRANSFORM
    // =========================================================================

    /**
     * Transform a single Oliphant entry into a DB-ready array.
     * Returns null if the entry is invalid / should be skipped.
     */
    public function transformEntry(array $entry): ?array
    {
        $title = trim($entry['Title'] ?? '');
        if (empty($title)) return null;

        $sections = $entry['Sections'] ?? [];
        if (empty($sections)) return null;

        // Normalize each section's chord strings
        $normalizedSections = $this->normalizeSections($sections);

        // Derive flat chord string for search / matching
        $chordString = $this->buildChordString($normalizedSections);

        // Count bars
        $barCount = $this->countBars($normalizedSections);

        // Derive form label (AABA, ABAC, etc.)
        $form = $this->deriveForm($normalizedSections);

        // Normalize the key: "Dmin" → keep as-is (our L3 bridge understands it)
        $key = $this->normalizeKey($entry['Key'] ?? '');

        return [
            'title'          => $title,
            'composer'       => trim($entry['Composer'] ?? '') ?: null,
            'song_key'       => $key ?: null,
            'rhythm'         => trim($entry['Rhythm'] ?? '') ?: null,
            'time_signature' => $this->normalizeTimeSig($entry['TimeSignature'] ?? '4/4'),
            'bar_count'      => $barCount ?: null,
            'form'           => $form ?: null,
            'sections_json'  => json_encode($normalizedSections),
            'chord_string'   => $chordString ?: null,
            'source'         => 'oliphant',
            'slug'           => JazzStandard::generateSlug($title),
            'created_at'     => now(),
        ];
    }

    // =========================================================================
    // CHORD NORMALIZER (embedded — ~30 lines)
    // =========================================================================

    /**
     * Normalize a single chord token from Oliphant iReal Pro notation to the
     * notation our ProgressionDetector / VoicingCrossref systems expect.
     *
     * Compatibility map (from analysis):
     *   ✅ Em7b5, A7b9, Dm6, Bbmaj7/D, G7#11    — already compatible
     *   ⚠️ F07 → Fdim7                            — handled
     *   ⚠️ Dmaj7(Em7b5) → strip parens → Dmaj7   — handled
     *   ⚠️ G7,, (double-comma rest) → empty       — handled at bar level
     *   ⚠️ D7alt → D7alt                          — kept as-is
     *   ⚠️ B7b9sus → B7b9sus                      — kept as-is (compound suffix)
     */
    public function normalizeChord(string $chord): string
    {
        $chord = trim($chord);
        if ($chord === '' || $chord === 'p' || $chord === 'x') return '';

        // Strip outer parentheses for optional/cue chords: Dmaj7(Em7b5) → Dmaj7
        $chord = preg_replace('/\([^)]+\)/', '', $chord);
        $chord = trim($chord);

        // iReal Pro uses 'n' for "no chord" (N.C.)
        if (strtolower($chord) === 'n' || strtolower($chord) === 'nc') return 'N.C.';

        // Map '07' suffix → 'dim7': F07 → Fdim7
        // Pattern: root letters optionally followed by '#' or 'b', then '07'
        if (preg_match('/^([A-G][b#]?)07$/', $chord, $m)) {
            return $m[1] . 'dim7';
        }

        // Map 'o7' (lowercase o) → 'dim7': Fo7 → Fdim7
        if (preg_match('/^([A-G][b#]?)o7$/', $chord, $m)) {
            return $m[1] . 'dim7';
        }

        // Map 'o' alone → 'dim': Fo → Fdim
        if (preg_match('/^([A-G][b#]?)o$/', $chord, $m)) {
            return $m[1] . 'dim';
        }

        // ^ (caret) prefix in iReal Pro means major 7 — rare but exists
        if (preg_match('/^([A-G][b#]?)\^(.*)$/', $chord, $m)) {
            return $m[1] . 'maj7' . $m[2];
        }

        return $chord;
    }

    // =========================================================================
    // SECTION PARSING
    // =========================================================================

    private function normalizeSections(array $sections): array
    {
        $out = [];
        foreach ($sections as $section) {
            $normalized = [
                'Label'       => $section['Label'] ?? 'A',
                'MainSegment' => $this->normalizeSegment($section['MainSegment'] ?? []),
            ];

            if (!empty($section['Endings'])) {
                $normalized['Endings'] = array_map(
                    fn($e) => $this->normalizeSegment($e),
                    $section['Endings']
                );
            }

            $out[] = $normalized;
        }
        return $out;
    }

    private function normalizeSegment(array $segment): array
    {
        $rawChords = $segment['Chords'] ?? '';
        if (!$rawChords) return ['Chords' => ''];

        $bars = explode('|', $rawChords);
        $normalizedBars = [];

        foreach ($bars as $bar) {
            // Double-comma = rest on a beat; collapse to chords only
            $beats = array_filter(array_map('trim', explode(',', $bar)));

            $chords = [];
            foreach ($beats as $beat) {
                $normalized = $this->normalizeChord($beat);
                if ($normalized !== '') {
                    $chords[] = $normalized;
                }
            }

            // Re-join multiple chords per bar with comma
            $normalizedBars[] = implode(',', $chords);
        }

        // Drop trailing empty bars
        while (!empty($normalizedBars) && $normalizedBars[array_key_last($normalizedBars)] === '') {
            array_pop($normalizedBars);
        }

        return ['Chords' => implode('|', $normalizedBars)];
    }

    /**
     * Build a flat chord string from all sections (for text search).
     * Format: "Dm7 Em7b5 A7 | Dm7 ..." (bars separated by " | ").
     */
    private function buildChordString(array $sections): string
    {
        $parts = [];
        foreach ($sections as $section) {
            $chords = $section['MainSegment']['Chords'] ?? '';
            if ($chords) {
                $parts[] = $chords;
            }
        }
        return implode(' || ', $parts);
    }

    private function countBars(array $sections): int
    {
        $total = 0;
        foreach ($sections as $section) {
            $chords = $section['MainSegment']['Chords'] ?? '';
            if ($chords) {
                $total += count(explode('|', $chords));
            }
        }
        return $total;
    }

    /**
     * Derive a form label (AABA, ABAC, etc.) from section labels.
     */
    private function deriveForm(array $sections): string
    {
        return implode('', array_map(fn($s) => strtoupper($s['Label'] ?? '?'), $sections));
    }

    /**
     * Normalize key from iReal Pro format.
     * "Dmin" → "Dmin", "Bb" → "Bb", "F" → "F".
     * We store Oliphant's format directly — our bridge (toIntermediateAnalysis)
     * handles the minor suffix when returning to callers.
     */
    private function normalizeKey(string $key): string
    {
        return trim($key);
    }

    private function normalizeTimeSig(string $ts): string
    {
        // iReal Pro uses "4/4", "3/4", "6/8" etc. — already correct format.
        return preg_match('/^\d+\/\d+$/', trim($ts)) ? trim($ts) : '4/4';
    }

    // =========================================================================
    // DB WRITE
    // =========================================================================

    private function insertBatch(array $batch, array &$errors): int
    {
        try {
            // Use insertOrIgnore to skip duplicates (slug is unique).
            // On a re-seed, truncate first; this guard just protects partial imports.
            \Illuminate\Support\Facades\DB::table('sbn_jazz_standards')->insertOrIgnore($batch);
            return count($batch);
        } catch (\Throwable $e) {
            $errors[] = 'Batch insert error: ' . $e->getMessage();
            return 0;
        }
    }

    // =========================================================================
    // DOWNLOAD
    // =========================================================================

    private function download(): string|false
    {
        try {
            $response = Http::timeout(30)->get(self::SOURCE_URL);
            if ($response->successful()) {
                return $response->body();
            }
            Log::warning('OliphantImporter: HTTP ' . $response->status() . ' from ' . self::SOURCE_URL);
            return false;
        } catch (\Throwable $e) {
            Log::error('OliphantImporter: download failed — ' . $e->getMessage());
            return false;
        }
    }
}
