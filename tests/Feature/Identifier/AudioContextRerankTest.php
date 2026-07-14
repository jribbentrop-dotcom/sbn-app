<?php

namespace Tests\Feature\Identifier;

use App\Services\TranscriptionAssembler;
use App\Services\VoicingCrossref;
use Tests\TestCase;

/**
 * T3 — verifies the context-aware identifier (ContextualReranker) is wired into
 * the audio-transcription assembly path (assembleTranscription), producing
 * clean, sequence-reranked chord labels with no scratch keys leaking out.
 */
class AudioContextRerankTest extends TestCase
{
    /**
     * Build a synthetic Python rawResult: one chord per 4/4 bar.
     * Each pitch set is a closed-position four-note voicing.
     *
     * @param array<array<int>> $barVoicings  MIDI pitch sets, one per bar
     */
    private function rawResultFor(array $barVoicings): array
    {
        $beats = [];
        $beatTimes = [];
        $t = 0.0;
        $secPerBeat = 0.5; // 120 BPM
        foreach ($barVoicings as $pitches) {
            for ($b = 0; $b < 4; $b++) {
                $durations = [];
                foreach ($pitches as $p) {
                    $durations[(string)$p] = 0.45; // > 0.1 duration-weight threshold
                }
                $beats[] = [
                    'start'          => $t,
                    'notes'          => $pitches,
                    'note_durations' => $durations,
                ];
                $beatTimes[] = $t;
                $t += $secPerBeat;
            }
        }

        // Notes list (for melody reconstruction) — top note of each bar.
        $notes = [];
        $tt = 0.0;
        foreach ($barVoicings as $pitches) {
            $top = max($pitches);
            $notes[] = ['pitch' => $top, 'start' => $tt, 'end' => $tt + 1.5];
            $tt += 2.0;
        }

        return [
            'beats'      => $beats,
            'beat_times' => $beatTimes,
            'notes'      => $notes,
            'tempo'      => 120,
            'duration'   => $t,
        ];
    }

    private function assemble(array $rawResult, array $opts): array
    {
        $assembler = app(TranscriptionAssembler::class);
        $crossref  = app(VoicingCrossref::class);

        // bass_snap off to keep the synthetic grid pristine.
        return $assembler->assembleTranscription($rawResult, $opts, 0, $crossref);
    }

    /** Flatten all chord labels across sections/bars, in order. */
    private function labels(array $analysis): array
    {
        $out = [];
        foreach ($analysis['sections'] as $section) {
            foreach ($section['bars'] as $bar) {
                foreach ($bar['chords'] as $chord) {
                    $out[] = $chord['label'];
                    // The transient reranking key must never survive assembly.
                    $this->assertArrayNotHasKey('_seq', $chord, 'raw _seq scratch key leaked into output');
                }
            }
        }
        return $out;
    }

    public function test_assembly_produces_chords_and_strips_scratch_key(): void
    {
        // ii–V–I in C: Dm7 / G7 / Cmaj7
        $raw = $this->rawResultFor([
            [62, 65, 69, 72], // D F A C   → Dm7
            [55, 59, 62, 65], // G B D F   → G7
            [60, 64, 67, 71], // C E G B   → Cmaj7
        ]);

        $analysis = $this->assemble($raw, [
            'title'      => 'Test ii-V-I',
            'key'        => 'C',
            'youtube_id' => 'test',
            'bass_snap'  => false,
        ]);

        $labels = $this->labels($analysis);

        $this->assertNotEmpty($labels, 'expected chord labels from assembly');
        // The deterministic engine identifies these cleanly; the reranker in a
        // clear ii-V-I must not corrupt them.
        $this->assertStringStartsWith('Dm7', $labels[0]);
        $this->assertStringStartsWith('G7', $labels[1] ?? '');
        $this->assertStringStartsWith('C', $labels[2] ?? '');
    }

    public function test_single_chord_song_skips_reranking_cleanly(): void
    {
        // Only one region → fewer than 2 slots → reranker short-circuits, but
        // _seq must still be stripped.
        $raw = $this->rawResultFor([
            [60, 64, 67, 71], // Cmaj7
        ]);

        $analysis = $this->assemble($raw, [
            'title'      => 'Single',
            'key'        => 'C',
            'youtube_id' => 'test',
            'bass_snap'  => false,
        ]);

        $labels = $this->labels($analysis);
        $this->assertNotEmpty($labels);
        $this->assertStringStartsWith('C', $labels[0]);
    }
}
