<?php

namespace App\Console\Commands;

use App\Services\VoicingCrossref;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use SimpleXMLElement;

/**
 * Run every chord stack in a MusicXML file through VoicingCrossref::identifyFromFrets
 * and emit a diagnostic report.
 *
 * Strategy: parse the XML, group notes by onset (notes after <chord/> share the
 * onset of the preceding non-<chord/> note), build a 6-char fret string per stack,
 * call identifyFromFrets, and dump the result alongside the raw frets and pitch
 * classes so we can eyeball misidentifications.
 *
 * Two passes per chord:
 *   1. identifyFromFrets (no context) — what the algo says in isolation.
 *   2. identifyFromFretsWithContext (with neighbor chord names + key) — what the
 *      context-aware pass produces. If they differ, the context layer is doing
 *      something; if they're identical, context isn't moving the needle.
 *
 * Diagnostic flags:
 *   - rare_quality       — output uses a quality outside the common set
 *   - exotic_extension   — output has #11/b13/#9/b9 on a non-dom chord
 *   - bass_root_mismatch — bass note ≠ root and inversion looks unusual
 *   - context_no_change  — context pass produced same result as isolated pass
 *                          (informational, not necessarily a bug)
 */
class AuditChordIdentifier extends Command
{
    protected $signature = 'sbn:audit-identifier
                            {file : Path to MusicXML file (relative to base_path)}
                            {--key= : Song key for context pass (e.g. "F", "Am")}
                            {--out=storage/audits : Output directory}';

    protected $description = 'Audit VoicingCrossref::identifyFromFrets across a MusicXML chord melody';

    private const COMMON_QUALITIES = [
        'maj', 'min', 'm', 'maj7', 'm7', 'dom7', '7', 'maj6', 'm6',
        'm7b5', 'dim', 'dim7', 'o7', 'sus4', 'sus2', 'aug',
    ];

    public function handle(VoicingCrossref $crossref): int
    {
        $relPath = $this->argument('file');
        $absPath = base_path($relPath);
        if (!File::exists($absPath)) {
            $this->error("File not found: $absPath");
            return self::FAILURE;
        }

        $xml = simplexml_load_file($absPath);
        if (!$xml) {
            $this->error("Failed to parse XML");
            return self::FAILURE;
        }

        $chords = $this->extractChordStacks($xml);
        $this->info("Extracted " . count($chords) . " chord stacks (≥2 notes) from $relPath");

        $key = $this->option('key') ?: $this->guessKey($xml, $absPath);
        $this->info("Using key: " . ($key ?: '(none — context pass disabled)'));

        // Pass 1: identify each in isolation
        $isolatedResults = [];
        foreach ($chords as $i => $c) {
            $isolatedResults[$i] = $crossref->identifyFromFrets($c['frets'], $c['position']);
        }

        // Pass 2: identify with context
        $contextResults = [];
        if ($key) {
            foreach ($chords as $i => $c) {
                $prev = [];
                $next = [];
                for ($b = 1; $b <= 2; $b++) {
                    if (isset($isolatedResults[$i - $b]['name'])) {
                        array_unshift($prev, $isolatedResults[$i - $b]['name']);
                    }
                    if (isset($isolatedResults[$i + $b]['name'])) {
                        $next[] = $isolatedResults[$i + $b]['name'];
                    }
                }
                $contextResults[$i] = $crossref->identifySingleWithContext(
                    $c['frets'], $c['position'], $key, $prev, $next
                );
            }
        }

        $report = [
            'generated_at' => now()->toIso8601String(),
            'source' => $relPath,
            'key' => $key,
            'chord_count' => count($chords),
            'chords' => [],
        ];

        $flagCounts = [];
        foreach ($chords as $i => $c) {
            $iso = $isolatedResults[$i] ?? null;
            $ctx = $contextResults[$i] ?? null;

            $flags = $this->analyze($c, $iso, $ctx);
            foreach ($flags as $f) {
                $flagCounts[$f['kind']] = ($flagCounts[$f['kind']] ?? 0) + 1;
            }

            $report['chords'][] = [
                'index' => $i,
                'measure' => $c['measure'],
                'frets' => $c['frets'],
                'position' => $c['position'],
                'pitch_classes' => $c['pcs'],
                'note_names' => $c['note_names'],
                'isolated' => [
                    'name' => $iso['name'] ?? null,
                    'root' => $iso['root'] ?? null,
                    'quality' => $iso['quality'] ?? null,
                    'extensions' => $iso['extensions'] ?? null,
                    'bass_note' => $iso['bass_note'] ?? null,
                    'inversion' => $iso['inversion'] ?? null,
                    'confidence' => $iso['confidence'] ?? null,
                    'candidates' => $iso['candidates'] ?? [],
                ],
                'context' => $ctx ? [
                    'name' => $ctx['name'] ?? null,
                    'root' => $ctx['root'] ?? null,
                    'quality' => $ctx['quality'] ?? null,
                    'extensions' => $ctx['extensions'] ?? null,
                    'bass_note' => $ctx['bass_note'] ?? null,
                    'inversion' => $ctx['inversion'] ?? null,
                    'confidence' => $ctx['confidence'] ?? null,
                ] : null,
                'flags' => $flags,
            ];
        }

        $report['flag_counts'] = $flagCounts;

        $outDir = base_path($this->option('out'));
        if (!File::isDirectory($outDir)) File::makeDirectory($outDir, 0755, true);
        $stamp = date('Ymd-His');
        $base = preg_replace('/[^a-z0-9]+/i', '-', pathinfo($relPath, PATHINFO_FILENAME));
        $base = trim($base, '-');
        $jsonPath = "$outDir/identifier-$base-$stamp.json";
        $mdPath = "$outDir/identifier-$base-$stamp.md";

        File::put($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        File::put($mdPath, $this->renderMarkdown($report));

        $this->info("Wrote $jsonPath");
        $this->info("Wrote $mdPath");
        $this->newLine();
        $this->info("Flag summary:");
        foreach ($flagCounts as $k => $n) {
            $this->line("  $k: $n");
        }
        $changed = 0;
        foreach ($report['chords'] as $c) {
            $iso = $c['isolated']['name'] ?? null;
            $ctx = $c['context']['name'] ?? null;
            if ($ctx !== null && $ctx !== $iso) $changed++;
        }
        $this->line("  context_pass_changed_label: $changed of " . count($report['chords']));

        return self::SUCCESS;
    }

    /**
     * Extract chord stacks from the MusicXML. A chord stack is the principal
     * note plus all subsequent notes marked <chord/> at the same onset.
     * Only stacks of ≥2 notes are returned.
     *
     * @return array<array{frets:string,position:int,pcs:array,note_names:array,measure:int}>
     */
    private function extractChordStacks(SimpleXMLElement $xml): array
    {
        $stacks = [];
        $measureNum = 0;

        // Iterate parts → measures → notes
        foreach ($xml->part as $part) {
            foreach ($part->measure as $measure) {
                $measureNum = (int) ($measure['number'] ?? $measureNum + 1);
                $current = null;

                foreach ($measure->note as $note) {
                    $isChord = isset($note->chord);
                    $tech = $note->notations->technical ?? null;
                    $string = $tech ? (int) $tech->string : null;
                    $fret = $tech ? (int) $tech->fret : null;

                    // Skip rests
                    if (isset($note->rest)) {
                        if ($current && count($current['notes']) >= 2) {
                            $stacks[] = $this->finalizeStack($current);
                        }
                        $current = null;
                        continue;
                    }

                    // Pitch info (for verification)
                    $step = (string) ($note->pitch->step ?? '');
                    $alter = (int) ($note->pitch->alter ?? 0);
                    $octave = (int) ($note->pitch->octave ?? 0);

                    if (!$isChord) {
                        // New note onset — finalize any prior stack
                        if ($current && count($current['notes']) >= 2) {
                            $stacks[] = $this->finalizeStack($current);
                        }
                        $current = ['notes' => [], 'measure' => $measureNum];
                    }

                    if ($string !== null && $fret !== null && $current !== null) {
                        $current['notes'][] = [
                            'string' => $string,
                            'fret' => $fret,
                            'step' => $step,
                            'alter' => $alter,
                            'octave' => $octave,
                        ];
                    }
                }

                // End of measure — flush
                if ($current && count($current['notes']) >= 2) {
                    $stacks[] = $this->finalizeStack($current);
                    $current = null;
                }
            }
        }

        return $stacks;
    }

    private function finalizeStack(array $stack): array
    {
        $frets = array_fill(0, 6, 'x');
        $pcs = [];
        $names = [];

        foreach ($stack['notes'] as $n) {
            $s = $n['string'];
            if ($s < 1 || $s > 6) continue;
            $f = $n['fret'];
            // SBN convention: index 0 = string 1 (low E)
            // MusicXML guitar convention: string 1 = high E
            // So we reverse: SBN_string = 7 - musicxml_string
            $sbnIdx = 6 - $s; // string 6 → idx 0 (low E), string 1 → idx 5 (high E)
            $frets[$sbnIdx] = $f <= 9 ? (string) $f : dechex($f);

            $pc = $this->stepAlterToPc($n['step'], $n['alter']);
            $pcs[] = $pc;
            $names[] = $this->stepAlterToName($n['step'], $n['alter']) . $n['octave'];
        }

        // Compute position = lowest non-zero fret
        $numericFrets = array_filter(array_map(fn($x) => is_numeric($x) || ctype_xdigit($x) ? hexdec($x) : null, $frets), fn($x) => $x !== null && $x > 0);
        $position = !empty($numericFrets) ? min($numericFrets) : 1;

        return [
            'frets' => implode('', $frets),
            'position' => $position,
            'pcs' => array_values(array_unique($pcs)),
            'note_names' => $names,
            'measure' => $stack['measure'],
        ];
    }

    private function stepAlterToPc(string $step, int $alter): int
    {
        $base = ['C'=>0,'D'=>2,'E'=>4,'F'=>5,'G'=>7,'A'=>9,'B'=>11][$step] ?? 0;
        return ($base + $alter + 12) % 12;
    }

    private function stepAlterToName(string $step, int $alter): string
    {
        if ($alter === 1) return $step . '#';
        if ($alter === -1) return $step . 'b';
        return $step;
    }

    private function guessKey(SimpleXMLElement $xml, string $path): ?string
    {
        // Filename hint
        $base = strtolower(pathinfo($path, PATHINFO_FILENAME));
        if (str_contains($base, 'days of wine')) return 'F';
        if (str_contains($base, 'barquinho')) return 'G';
        if (str_contains($base, 'nature boy')) return 'Dm';
        if (str_contains($base, 'tarrega')) return 'Em';
        return null;
    }

    private function analyze(array $chord, ?array $iso, ?array $ctx): array
    {
        $flags = [];
        if (!$iso || !($iso['name'] ?? null)) {
            $flags[] = ['kind' => 'no_identification', 'detail' => 'No chord name returned'];
            return $flags;
        }

        $quality = $iso['quality'] ?? '';
        if (!in_array($quality, self::COMMON_QUALITIES, true)) {
            $flags[] = ['kind' => 'rare_quality', 'detail' => "Quality '$quality' is outside the common set"];
        }

        $ext = $iso['extensions'] ?? '';
        $isDom = in_array($quality, ['dom7', '7'], true);
        $exotic = ['#11', 'b13', '#9'];
        foreach ($exotic as $e) {
            if (str_contains($ext, $e) && !$isDom) {
                $flags[] = ['kind' => 'exotic_extension', 'detail' => "Extension '$e' on non-dominant quality '$quality' (full: $ext)"];
                break;
            }
        }

        if ($ctx && ($ctx['name'] ?? null) !== ($iso['name'] ?? null)) {
            $flags[] = [
                'kind' => 'context_changed_label',
                'detail' => "Isolated: {$iso['name']} → Context: {$ctx['name']}",
            ];
        }

        return $flags;
    }

    private function renderMarkdown(array $report): string
    {
        $md = "# ChordIdentifier Audit — " . basename($report['source']) . "\n\n";
        $md .= "Generated: {$report['generated_at']}\n\n";
        $md .= "Source: `{$report['source']}`\n\n";
        $md .= "Key (for context pass): `" . ($report['key'] ?: '—') . "`\n\n";
        $md .= "Chord stacks (≥2 notes): {$report['chord_count']}\n\n";

        $md .= "## Flag Summary\n\n";
        if (empty($report['flag_counts'])) {
            $md .= "_No flags raised._\n\n";
        } else {
            $md .= "| Flag | Count |\n|---|---|\n";
            foreach ($report['flag_counts'] as $kind => $n) {
                $md .= "| $kind | $n |\n";
            }
            $md .= "\n";
        }

        $md .= "## Chords\n\n";
        $md .= "| # | M | Frets | Notes | PCs | Isolated | Context | Flags |\n";
        $md .= "|---|---|---|---|---|---|---|---|\n";

        foreach ($report['chords'] as $c) {
            $iso = $c['isolated'];
            $ctx = $c['context'];
            $isoStr = ($iso['name'] ?? '—');
            if (!empty($iso['inversion']) && $iso['inversion'] !== 'root') {
                $isoStr .= " *({$iso['inversion']})*";
            }
            $ctxStr = $ctx ? ($ctx['name'] ?? '—') : '—';
            if ($ctx && !empty($ctx['inversion']) && $ctx['inversion'] !== 'root') {
                $ctxStr .= " *({$ctx['inversion']})*";
            }
            $flagStr = implode(', ', array_map(fn($f) => $f['kind'], $c['flags']));
            $notes = implode(' ', $c['note_names']);
            $pcs = implode(',', $c['pitch_classes']);

            $md .= "| {$c['index']} | {$c['measure']} | `{$c['frets']}` | $notes | $pcs | $isoStr | $ctxStr | $flagStr |\n";
        }

        $md .= "\n## Detailed Flags\n\n";
        foreach ($report['chords'] as $c) {
            if (empty($c['flags'])) continue;
            $md .= "**Chord #{$c['index']} (m. {$c['measure']}, `{$c['frets']}`):**\n";
            foreach ($c['flags'] as $f) {
                $md .= "- `{$f['kind']}` — {$f['detail']}\n";
            }
            $md .= "\n";
        }

        return $md;
    }
}
