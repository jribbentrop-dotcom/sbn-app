<?php

namespace App\Console\Commands;

use App\Models\ChordProgression;
use App\Services\HarmonicContext;
use App\Services\ProgressionBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PhaseERegress extends Command
{
    protected $signature = 'phase-e:regress
                            {--dump : Capture current output as fixture}
                            {--fixture=tests/fixtures/phase-e-regression.json : Fixture path}
                            {--verify-examples : Verify against hand-written progression examples}
                            {--examples-file=docs/progressionexamples.txt : Path to examples file}';

    protected $description = 'Phase E regression suite';

    /**
     * Shorthand → voicing_style preset (see ProgressionBuilder::VOICING_STYLE_PRESETS).
     *   rootd = high voicing (top strings, drop2 high)
     *   roota = mid voicing  (A-string root)
     *   roote = low voicing  (E-string root)
     */
    private const STYLE_MAP = [
        'rootd' => 'drop2_high',
        'roota' => 'roota',
        'roote' => 'roote',
    ];

    public function handle(HarmonicContext $ctx, ProgressionBuilder $bld): int
    {
        if ($this->option('verify-examples')) {
            return $this->verifyExamples($ctx, $bld);
        }

        $progs = ChordProgression::whereIn('category', ['jazz', 'latin'])
            ->orderBy('sort_order')->get();

        if ($progs->isEmpty()) {
            $this->warn('No jazz/latin progressions.');
            return self::SUCCESS;
        }

        $this->info("Processing {$progs->count()} progressions...");

        $out = [];
        foreach ($progs as $p) {
            $key = $p->tonality === 'minor' ? 'A' : 'C';
            try {
                $r = $bld->buildVoicings($ctx->buildFromNumerals($key, $p->numerals), [
                    'category' => $p->category,
                    'extensions' => true,
                ]);
                $out[$p->slug] = [
                    'name'     => $p->name,
                    'category' => $p->category,
                    'numerals' => $p->numerals,
                    'key'      => $key,
                    'slots'    => $this->slots($r),
                    'phase_e'  => $r['diagnostics']['phase_e'] ?? null,
                ];
            } catch (\Throwable $e) {
                $this->error("  {$p->slug}: {$e->getMessage()}");
                $out[$p->slug] = ['error' => $e->getMessage()];
            }
        }

        return $this->option('dump') ? $this->dump($out) : $this->verify($out);
    }

    private function slots(array $r): array
    {
        $s = [];
        foreach ($r['selections'] ?? [] as $sel) {
            $ext = $sel['voicing']['extensions'] ?? '';
            $s[] = [
                'chord_name'    => $sel['chord_name'] ?? '?',
                'extension_set' => $ext !== ''
                    ? array_map('trim', explode(',', $ext))
                    : [],
            ];
        }
        return $s;
    }

    private function dump(array $out): int
    {
        $path = base_path($this->option('fixture'));
        if (!File::isDirectory(dirname($path))) {
            File::makeDirectory(dirname($path), 0755, true);
        }

        $fix = [
            'generated_at' => now()->toIso8601String(),
            'progressions' => [],
        ];

        foreach ($out as $slug => $d) {
            if (isset($d['error'])) {
                $fix['progressions'][$slug] = ['error' => $d['error']];
                continue;
            }
            $fix['progressions'][$slug] = [
                'name'                => $d['name'],
                'category'            => $d['category'],
                'numerals'            => $d['numerals'],
                'key'                 => $d['key'],
                'slots'               => array_map(
                    fn($s) => [
                        'chord_name'    => $s['chord_name'],
                        'extension_set' => $s['extension_set'],
                    ],
                    $d['slots']
                ),
                'expected_resolutions' => $d['phase_e']['pass2_fired_resolutions'] ?? [],
            ];
        }

        File::put($path, json_encode($fix, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info("Fixture: {$path}");
        return self::SUCCESS;
    }

    private function verify(array $out): int
    {
        $path = base_path($this->option('fixture'));
        if (!File::exists($path)) {
            $this->error("No fixture at {$path}. Run --dump first.");
            return self::FAILURE;
        }

        $exp  = json_decode(File::get($path), true)['progressions'] ?? [];
        $pass = $fail = $total = 0;

        $this->newLine();
        $this->line(str_repeat('-', 70));

        foreach ($out as $slug => $d) {
            $total++;
            $e = $exp[$slug] ?? null;

            if (!$e || isset($e['error'])) {
                $this->line("  <fg=yellow>SKIP</> {$slug}");
                continue;
            }
            if (isset($d['error'])) {
                $this->line("  <fg=red>ERR</> {$slug} — {$d['error']}");
                $fail++;
                continue;
            }

            $errs = [];

            foreach ($e['slots'] as $i => $es) {
                $as = $d['slots'][$i] ?? null;
                if (!$as) {
                    $errs[] = "  slot {$i}: missing";
                    continue;
                }
                $exSet = $es['extension_set'] ?? [];
                $acSet = $as['extension_set'] ?? [];
                sort($exSet);
                sort($acSet);
                if ($exSet !== $acSet) {
                    $errs[] = sprintf(
                        '  slot %d (%s): expected [%s], got [%s]',
                        $i,
                        $es['chord_name'],
                        implode(',', $exSet) ?: 'none',
                        implode(',', $acSet) ?: 'none'
                    );
                }
            }

            $exRes = $e['expected_resolutions'] ?? [];
            $acRes = $d['phase_e']['pass2_fired_resolutions'] ?? [];
            if ($exRes && !array_intersect($exRes, $acRes)) {
                $errs[] = sprintf(
                    '  resolutions: expected >=1 of [%s], got [%s]',
                    implode(', ', $exRes),
                    implode(', ', $acRes) ?: 'none'
                );
            }

            if (empty($errs)) {
                $this->line("  <fg=green>PASS</> {$slug}");
                $pass++;
            } else {
                $this->line("  <fg=red>FAIL</> {$slug}");
                foreach ($errs as $err) {
                    $this->line($err);
                }
                $fail++;
            }
        }

        $this->line(str_repeat('-', 70));
        $rate = $total > 0 ? round($pass / $total * 100, 1) : 0;
        $this->info("{$pass}/{$total} passed ({$rate}%), {$fail} failed");

        return $fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Verify builder output against hand-written progression examples.
     * Each example case asserts the *exact frets* of every slot for a
     * given (key × voicing_style × extensions on/off) combination.
     */
    private function verifyExamples(HarmonicContext $ctx, ProgressionBuilder $bld): int
    {
        $filePath = base_path($this->option('examples-file'));
        if (!File::exists($filePath)) {
            $this->error("Examples file not found: {$filePath}");
            return self::FAILURE;
        }

        $cases = $this->parseExamplesFile($filePath);
        if (empty($cases)) {
            $this->warn('No examples parsed from file.');
            return self::SUCCESS;
        }

        $this->info('Processing ' . count($cases) . ' example cases...');
        $this->newLine();
        $this->line(str_repeat('-', 70));

        $pass = $fail = $total = 0;

        foreach ($cases as $case) {
            $total++;
            $label = $case['label'];

            try {
                $opts = [
                    'category'   => 'jazz',
                    'extensions' => $case['extensions'],
                ];
                if ($case['style'] !== null) {
                    $opts['voicing_style'] = $case['style'];
                }

                $numeralsStr = is_array($case['numerals'])
                    ? implode(',', $case['numerals'])
                    : $case['numerals'];

                $result = $bld->buildVoicings(
                    $ctx->buildFromNumerals($case['key'], $numeralsStr),
                    $opts
                );

                $selections = $result['selections'] ?? [];
                $errors = [];

                foreach ($case['expected'] as $slotIdx => $expectedFrets) {
                    $actual = $selections[$slotIdx]['voicing']['frets'] ?? null;
                    if ($actual === null) {
                        $errors[] = "  slot {$slotIdx}: missing voicing";
                    } elseif (strcasecmp((string) $actual, $expectedFrets) !== 0) {
                        $errors[] = "  slot {$slotIdx}: expected {$expectedFrets}, got {$actual}";
                    }
                }

                if (empty($errors)) {
                    $this->line("  <fg=green>PASS</> {$label}");
                    $pass++;
                } else {
                    $this->line("  <fg=red>FAIL</> {$label}");
                    foreach ($errors as $e) {
                        $this->line("    {$e}");
                    }
                    $fail++;
                }
            } catch (\Throwable $e) {
                $this->line("  <fg=red>ERR</> {$label} — {$e->getMessage()}");
                $fail++;
            }
        }

        $this->line(str_repeat('-', 70));
        $rate = $total > 0 ? round($pass / $total * 100, 1) : 0;
        $this->info("{$pass}/{$total} passed ({$rate}%), {$fail} failed");

        return $fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Parse the hand-written examples file.
     *
     * Format (whitespace-tolerant, blank lines ignored):
     *
     *   <Numeral header>           e.g. "IIm7-V7-Imaj7:" or "Imaj7 II7 Im7 V7 Imaj7"
     *   no extensions              (optional, default — toggles extension flag for following data lines)
     *   <Key> <style> <fret> <fret> ...
     *   ...
     *   extensions
     *   <Key> <style> <fret> <fret> ...
     *
     * A header line is anything that's not a data line and not the literal
     * "extensions"/"no extensions" toggle. Data lines start with a key letter
     * (A–G, optionally with #/b) followed by a style shorthand from STYLE_MAP.
     *
     * Lines that contain only frets (no key/style prefix) are emitted as a
     * style-agnostic case so the builder picks defaults.
     */
    private function parseExamplesFile(string $path): array
    {
        $content = File::get($path);
        $lines = explode("\n", str_replace("\r\n", "\n", $content));

        $cases = [];
        $currentNumerals = null;
        $currentExtensions = false;

        $dataLineRe   = '/^([A-G][#b]?)\s+(\S+)\s+(.+)$/';
        $fretsOnlyRe  = '/^(?:[0-9a-fxX]{6}(?:\s+[0-9a-fxX]{6})*)$/';

        foreach ($lines as $raw) {
            $line = trim($raw);
            if ($line === '') continue;

            $lower = strtolower($line);
            if ($lower === 'no extensions') {
                $currentExtensions = false;
                continue;
            }
            if ($lower === 'extensions') {
                $currentExtensions = true;
                continue;
            }

            // Data line with explicit key + style: "C rootd xx7768 xx5767 xx5557"
            if (preg_match($dataLineRe, $line, $m) && isset(self::STYLE_MAP[$m[2]])) {
                if ($currentNumerals === null) continue; // skip orphans
                $key       = $m[1];
                $styleKey  = $m[2];
                $frets     = preg_split('/\s+/', trim($m[3]));
                $cases[] = [
                    'label'      => implode('-', $currentNumerals)
                                  . " {$key} {$styleKey}"
                                  . ($currentExtensions ? ' +ext' : ''),
                    'key'        => $key,
                    'numerals'   => $currentNumerals,
                    'extensions' => $currentExtensions,
                    'style'      => self::STYLE_MAP[$styleKey],
                    'expected'   => $frets,
                ];
                continue;
            }

            // Frets-only line (no key/style prefix): use C major + auto style.
            if (preg_match($fretsOnlyRe, $line)) {
                if ($currentNumerals === null) continue;
                $frets = preg_split('/\s+/', $line);
                $cases[] = [
                    'label'      => implode('-', $currentNumerals)
                                  . ' C auto'
                                  . ($currentExtensions ? ' +ext' : ''),
                    'key'        => 'C',
                    'numerals'   => $currentNumerals,
                    'extensions' => $currentExtensions,
                    'style'      => null,
                    'expected'   => $frets,
                ];
                continue;
            }

            // Anything else is a numeral header. Reset extension flag so the
            // next "no extensions"/"extensions" toggle starts each block fresh.
            $header = rtrim($line, ':');
            $currentNumerals = preg_split('/[\s\-]+/', $header);
            $currentExtensions = false;
        }

        return $cases;
    }
}
