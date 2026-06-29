<?php

namespace App\Console\Commands;

use App\Services\LLM\LookupClient;
use App\Services\LLM\LookupClientException;
use App\Services\LLM\OllamaLookupClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Few-shot spike for the Jazz AI LLM thread.
 *
 * Tests, on REAL leadsheet data, whether one local Ollama model can serve
 * BOTH directions of the chord/voicing mapping:
 *
 *   Recognition (identifier task):  fret string  -> chord name
 *   Generation  (style task):       chord name + context -> fret string
 *
 * The two tasks are literal inverses. This spike is the cheap empirical
 * test of the "fold both into one model" hypothesis BEFORE any GPU spend.
 * See docs/SBN-Tech-Stack-Reference.md and the project_llm_jazz_ai memory note.
 *
 * No training, no .env changes — instantiates OllamaLookupClient directly so
 * it does not disturb the live LLM_PROVIDER (groq, used for descriptions).
 */
class LlmSpike extends Command
{
    protected $signature = 'sbn:llm-spike
        {--model=qwen2.5:7b-instruct : Ollama model tag to spike against}
        {--base=http://localhost:11434/v1 : Ollama OpenAI-compatible base URL}
        {--leadsheet=438 : sbn_leadsheets.id to pull voicings from (438 = Georgia on my Mind)}
        {--n=6 : Number of chord voicings to test per direction}
        {--style=João Gilberto, bossa nova, clean low-register nylon-string voicings : Style instruction for the generation direction}';

    protected $description = 'Few-shot spike: test if one Ollama model does both chord recognition and style voicing on real leadsheet data';

    public function handle(): int
    {
        $model = (string) $this->option('model');
        $base  = (string) $this->option('base');
        $leadsheetId = (int) $this->option('leadsheet');
        $n = (int) $this->option('n');
        $style = (string) $this->option('style');

        $client = new OllamaLookupClient(model: $model, baseUrl: $base);

        $this->info("Spike model: {$model}  @ {$base}");

        // --- Load the ground-truth corpus from one leadsheet -------------------
        $row = DB::table('sbn_leadsheets')->where('id', $leadsheetId)->first(['title', 'song_key', 'json_data']);
        if (! $row || ! $row->json_data) {
            $this->error("Leadsheet {$leadsheetId} not found or has no json_data.");
            return self::FAILURE;
        }

        $data = json_decode($row->json_data, true);
        $voicings = $data['chordVoicings'] ?? [];
        if (empty($voicings)) {
            $this->error("Leadsheet {$leadsheetId} has no chordVoicings.");
            return self::FAILURE;
        }

        $key = $data['key'] ?? $row->song_key ?? '?';
        $voicingCount = count($voicings);
        $this->info("Source: \"{$row->title}\"  key={$key}  ({$voicingCount} voicings; testing {$n})");

        // Build the ground-truth list: [name => frets], take first $n.
        $truth = [];
        foreach ($voicings as $name => $v) {
            if (! isset($v['frets'])) {
                continue;
            }
            $truth[$name] = $v['frets'];
            if (count($truth) >= $n) {
                break;
            }
        }

        // A short progression context for the generation direction, drawn from
        // the first measures' chords (real harmonic context, not invented).
        $progression = $this->extractProgression($data, $n + 2);

        $this->recognitionPass($client, $truth);
        $this->generationPass($client, $truth, $key, $progression, $style);

        $this->newLine();
        $this->line('<comment>Read the two tables together:</comment>');
        $this->line('  - Recognition strong + Generation weak  => SPLIT confirmed (two evals/objectives).');
        $this->line('  - Both acceptable                       => evidence the fold hypothesis has legs.');

        return self::SUCCESS;
    }

    /**
     * Direction 1 — Recognition: fret string -> chord name (identifier task).
     */
    private function recognitionPass(LookupClient $client, array $truth): void
    {
        $this->newLine();
        $this->line('<info>=== Direction 1: RECOGNITION  (frets -> name) ===</info>');

        $system = 'You are a jazz guitar harmony analyst. Given a 6-character guitar fret '
            . 'string (low E to high E; "x" = muted, "0" = open, digits = fret number, '
            . 'standard tuning EADGBE), name the chord. Use standard jazz chord symbols '
            . '(e.g. Cmaj7, Dm7, G7, F#7(b13), Am7(9)). Return only the chord name.';

        $schema = ['type' => 'object', 'properties' => ['chord' => ['type' => 'string']], 'required' => ['chord']];

        $rows = [];
        $hits = 0;
        foreach ($truth as $expected => $frets) {
            $user = "Fret string: {$frets}\nName this chord.";
            try {
                $res = $client->complete($system, $user, $schema, ['timeoutSeconds' => 60]);
                $got = trim((string) ($res['data']['chord'] ?? '?'));
            } catch (LookupClientException $e) {
                $got = '[ERR] ' . $e->getMessage();
            }

            $exactMatch = $this->normalizeName($got) === $this->normalizeName($expected);
            $rootMatch  = $this->root($got) === $this->root($expected);
            $verdict = $exactMatch ? 'EXACT' : ($rootMatch ? 'root' : 'miss');
            if ($exactMatch) {
                $hits++;
            }

            $rows[] = [$frets, $expected, $got, $verdict];
        }

        $this->table(['frets', 'truth', 'model said', 'verdict'], $rows);
        $this->line(sprintf('  Exact: %d/%d', $hits, count($truth)));
    }

    /**
     * Direction 2 — Generation: name + context -> fret string (style/builder task).
     * No automatic grading: voicing quality/style needs human judgement (the eval
     * that does not exist yet). Show truth alongside for eyeballing.
     */
    private function generationPass(LookupClient $client, array $truth, string $key, string $progression, string $style): void
    {
        $this->newLine();
        $this->line('<info>=== Direction 2: GENERATION  (name + context -> frets) ===</info>');
        $this->line("  Style: <comment>{$style}</comment>");
        $this->line("  Key: {$key}   Progression context: {$progression}");

        $system = 'You are a jazz guitar arranger. Given a chord name, a key, a surrounding '
            . 'progression, and a target player/style, output ONE playable 6-character guitar '
            . 'fret string (low E to high E; "x" = muted, "0" = open, digits = fret number, '
            . 'standard tuning EADGBE) that voices that chord in the requested style. '
            . 'Prefer idiomatic, playable shapes over wide stretches. Return only the fret string.';

        $schema = ['type' => 'object', 'properties' => ['frets' => ['type' => 'string']], 'required' => ['frets']];

        $rows = [];
        foreach ($truth as $name => $truthFrets) {
            $user = "Chord: {$name}\nKey: {$key}\nProgression: {$progression}\n"
                . "Voice this chord in the style of: {$style}\nGive one fret string.";
            try {
                $res = $client->complete($system, $user, $schema, ['timeoutSeconds' => 60]);
                $got = trim((string) ($res['data']['frets'] ?? '?'));
            } catch (LookupClientException $e) {
                $got = '[ERR] ' . $e->getMessage();
            }

            $valid = (bool) preg_match('/^[x0-9]{6}$/i', $got);
            $rows[] = [$name, $truthFrets, $got, $valid ? 'valid shape' : 'INVALID'];
        }

        $this->table(['chord', 'sheet voicing', 'model voicing', 'shape check'], $rows);
        $this->line('  (No accuracy score — voicing/style quality is a human call. Compare columns by eye.)');
    }

    /**
     * Pull a readable chord sequence from the leadsheet measures for context.
     */
    private function extractProgression(array $data, int $limit): string
    {
        $names = [];
        foreach (($data['measures'] ?? []) as $measure) {
            foreach (($measure['chords'] ?? []) as $chord) {
                $label = is_array($chord) ? ($chord['name'] ?? $chord['chord'] ?? null) : $chord;
                if ($label) {
                    $names[] = $label;
                }
                if (count($names) >= $limit) {
                    break 2;
                }
            }
        }

        return $names ? implode(' | ', $names) : '(none)';
    }

    /** Loose name normalization for exact-match comparison. */
    private function normalizeName(string $s): string
    {
        $s = strtolower(trim($s));
        $s = str_replace([' ', 'major', 'maj', '△', 'min', '-', '°', 'dim'], ['', 'maj7', 'maj7', 'maj7', 'm', 'm', 'dim', 'dim'], $s);
        return preg_replace('/[()]/', '', $s);
    }

    /** Extract the root (with accidental) for graded "root correct" credit. */
    private function root(string $s): string
    {
        if (preg_match('/^([A-Ga-g][#b]?)/', trim($s), $m)) {
            return strtoupper($m[1][0]) . (isset($m[1][1]) ? $m[1][1] : '');
        }
        return '?';
    }
}
