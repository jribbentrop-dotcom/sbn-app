<?php

namespace App\Console\Commands;

use App\Services\VoicingCrossref;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Audit VoicingCrossref::identifyVoicingsBatch (Phase 2) across a MusicXML chord melody.
 *
 * This command uses the new Phase 2 pipeline which includes:
 * - Phase 1: Top-K candidate identification
 * - Phase 2: Contextual re-ranking via ContextualReranker (pedal detection, diminished resolution, diatonicity)
 *
 * Outputs a JSON report with reinterpreted flags and reasons for verification.
 */
class AuditIdentifierContext extends Command
{
    protected $signature = 'sbn:audit-identifier-context
                            {file : Path to MusicXML file (relative to base_path)}
                            {--key= : Song key for context pass (e.g. "F", "Am")}
                            {--out=storage/audits : Output directory}
                            {--expected= : Path to expected.json file for comparison}';

    protected $description = 'Audit VoicingCrossref::identifyVoicingsBatch (Phase 2) across a MusicXML chord melody';

    public function handle(VoicingCrossref $crossref): int
    {
        $relPath = $this->argument('file');
        $absPath = base_path($relPath);
        if (!File::exists($absPath)) {
            $this->error("File not found: $absPath");
            return self::FAILURE;
        }

        $key = $this->option('key');
        $outDir = $this->option('out');
        $expectedPath = $this->option('expected');

        // Load expected results if provided
        $expected = null;
        if ($expectedPath) {
            $expectedAbsPath = base_path($expectedPath);
            if (!File::exists($expectedAbsPath)) {
                $this->error("Expected file not found: $expectedAbsPath");
                return self::FAILURE;
            }
            $expected = json_decode(File::get($expectedAbsPath), true);
            $this->info("Loaded " . count($expected['expected'] ?? []) . " expected re-interpretations from $expectedPath");
        }

        $xml = simplexml_load_file($absPath);
        if (!$xml) {
            $this->error("Failed to parse XML");
            return self::FAILURE;
        }

        // Extract chord stacks from MusicXML
        $chords = $this->extractChordStacks($xml);
        $this->info("Extracted " . count($chords) . " chord stacks (≥2 notes) from $relPath");
        $this->info("Using key: " . ($key ?: 'none'));

        // Build voicings array for identifyVoicingsBatch
        $voicings = [];
        foreach ($chords as $i => $c) {
            $voicings["slot$i"] = [
                'frets' => $c['frets'],
                'position' => $c['position'],
            ];
        }

        // Build harmonic context
        $harmonicContext = null;
        if ($key) {
            $harmonicContext = [
                'song_key' => $key,
            ];
        }

        // Call identifyVoicingsBatch (Phase 1 + Phase 2)
        $results = $crossref->identifyVoicingsBatch($voicings, $harmonicContext);

        // Build output report
        $report = [
            'generated_at' => now()->toIso8601String(),
            'source' => $relPath,
            'key' => $key,
            'chord_count' => count($chords),
            'chords' => [],
        ];

        foreach ($chords as $i => $c) {
            $result = $results["slot$i"] ?? [];
            $report['chords'][] = [
                'index' => $i,
                'measure' => $c['measure'],
                'frets' => $c['frets'],
                'position' => $c['position'],
                'pitch_classes' => $c['pcs'],
                'note_names' => $c['note_names'],
                'phase2' => [
                    'name' => $result['name'] ?? null,
                    'root' => $result['root'] ?? null,
                    'quality' => $result['quality'] ?? null,
                    'extensions' => $result['extensions'] ?? null,
                    'bass_note' => $result['bass_note'] ?? null,
                    'confidence' => $result['confidence'] ?? null,
                    'reinterpreted' => $result['reinterpreted'] ?? false,
                    'reinterpret_reason' => $result['reinterpret_reason'] ?? null,
                    'pedal_bass' => $result['pedal_bass'] ?? null,
                    'pedal_upper_name' => $result['pedal_upper_name'] ?? null,
                ],
                'candidates' => $result['candidates'] ?? [],
            ];
        }

        // Compare against expected results if provided
        $comparison = null;
        if ($expected) {
            $comparison = $this->compareResults($report['chords'], $expected['expected'] ?? []);
            $report['comparison'] = $comparison;
        }

        // Write output files
        $outDir = base_path($this->option('out'));
        if (!File::isDirectory($outDir)) File::makeDirectory($outDir, 0755, true);

        $basename = pathinfo($relPath, PATHINFO_FILENAME);
        $timestamp = now()->format('Ymd-His');
        $jsonPath = "$outDir/identifier-context-$basename-$timestamp.json";
        $mdPath = "$outDir/identifier-context-$basename-$timestamp.md";

        File::put($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info("Wrote $jsonPath");

        $summary = $this->generateMarkdownSummary($report);
        File::put($mdPath, $summary);
        $this->info("Wrote $mdPath");

        // Print flag summary
        $this->printFlagSummary($report);

        return self::SUCCESS;
    }

    /**
     * Extract chord stacks from MusicXML.
     * Copied from AuditChordIdentifier.
     */
    private function extractChordStacks(\SimpleXMLElement $xml): array
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
        $names = ['C'=>0,'C#'=>1,'Db'=>1,'D'=>2,'D#'=>3,'Eb'=>3,'E'=>4,'F'=>5,'F#'=>6,'Gb'=>6,'G'=>7,'G#'=>8,'Ab'=>8,'A'=>9,'A#'=>10,'Bb'=>10,'B'=>11];
        $base = $step;
        if ($alter === 1) {
            return $base . '#';
        } elseif ($alter === -1) {
            return $base . 'b';
        }
        return $base;
    }

    private function generateMarkdownSummary(array $report): string
    {
        $lines = [];
        $lines[] = "# Identifier Context Audit Report";
        $lines[] = "";
        $lines[] = "**Generated:** " . $report['generated_at'];
        $lines[] = "**Source:** " . $report['source'];
        $lines[] = "**Key:** " . ($report['key'] ?: 'none');
        $lines[] = "**Chord Count:** " . $report['chord_count'];
        $lines[] = "";
        $lines[] = "## Reinterpreted Chords";
        $lines[] = "";

        $reinterpreted = array_filter($report['chords'], fn($c) => $c['phase2']['reinterpreted'] ?? false);

        if (empty($reinterpreted)) {
            $lines[] = "No chords were reinterpreted by Phase 2.";
        } else {
            foreach ($reinterpreted as $c) {
                $lines[] = "- **Index {$c['index']}** (Measure {$c['measure']})";
                $lines[] = "  - Frets: `{$c['frets']}`";
                $lines[] = "  - Phase 2: `{$c['phase2']['name']}`";
                $lines[] = "  - Reason: `{$c['phase2']['reinterpret_reason']}`";
                $lines[] = "";
            }
        }

        return implode("\n", $lines);
    }

    private function printFlagSummary(array $report): void
    {
        $reinterpretedCount = count(array_filter($report['chords'], fn($c) => $c['phase2']['reinterpreted'] ?? false));
        $this->newLine();
        $this->info("Reinterpreted by Phase 2: $reinterpretedCount of {$report['chord_count']}");

        if (isset($report['comparison'])) {
            $this->newLine();
            $this->info("Expected comparison:");
            $this->line("  Total expected: {$report['comparison']['expected']}");
            $this->line("  Passed: {$report['comparison']['passed']}");
            $this->line("  Failed: {$report['comparison']['failed']}");
            if (!empty($report['comparison']['failures'])) {
                $this->newLine();
                $this->line("Failed expectations:");
                foreach ($report['comparison']['failures'] as $failure) {
                    $this->line("  - Index {$failure['stack_index']}: expected '{$failure['expected_label']}', got '{$failure['actual_label']}'");
                }
            }
        }
    }

    private function compareResults(array $actualChords, array $expected): array
    {
        $passed = 0;
        $failed = 0;
        $failures = [];

        // Build index map for quick lookup
        $actualMap = [];
        foreach ($actualChords as $c) {
            $actualMap[$c['index']] = $c;
        }

        foreach ($expected as $exp) {
            $idx = $exp['stack_index'];
            $actual = $actualMap[$idx] ?? null;

            if (!$actual) {
                $failed++;
                $failures[] = [
                    'stack_index' => $idx,
                    'frets' => $exp['frets'],
                    'expected_label' => $exp['phase2_label'],
                    'actual_label' => 'NOT FOUND',
                ];
                continue;
            }

            $actualLabel = $actual['phase2']['name'] ?? '';
            $expectedLabel = $exp['phase2_label'];

            if ($actualLabel === $expectedLabel) {
                $passed++;
            } else {
                $failed++;
                $failures[] = [
                    'stack_index' => $idx,
                    'frets' => $exp['frets'],
                    'expected_label' => $expectedLabel,
                    'actual_label' => $actualLabel,
                ];
            }
        }

        return [
            'expected' => count($expected),
            'passed' => $passed,
            'failed' => $failed,
            'failures' => $failures,
        ];
    }
}
