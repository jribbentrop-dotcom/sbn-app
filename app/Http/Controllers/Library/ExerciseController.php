<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\Exercise;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExerciseController extends Controller
{
    public function apiShow(Request $request, string $slug): JsonResponse
    {
        $exercise = Exercise::query()->where('slug', $slug)->firstOrFail();

        $targetKey = trim((string) $request->query('key', $exercise->key_center));
        if ($targetKey === '') {
            $targetKey = $exercise->key_center;
        }

        $content = $exercise->content_json ?? [];
        if (is_string($content)) {
            $content = json_decode($content, true) ?: [];
        }

        if ($targetKey !== $exercise->key_center) {
            $content = $this->transposeExerciseContent($content, $exercise->key_center, $targetKey);
        }

        // Return meta separately, content_json fields at top level.
        // SheetMiniPlayer reads: exercise.meta.* + exercise.sections/melody/
        // timeSignature/chordVoicings/repeatMarkers/voltaEndings (LeadsheetJson shape).
        $response = array_merge(
            is_array($content) ? $content : [],
            [
                'meta' => [
                    'slug'        => $exercise->slug,
                    'title'       => $exercise->title,
                    'key_center'  => $targetKey,
                    'time_sig'    => $exercise->time_sig,
                    'bpm_default' => $exercise->bpm_default,
                    'type'        => $exercise->type,
                ],
            ]
        );

        return response()->json($response);
    }

    public function apiSearch(Request $request): JsonResponse
    {
        $q = trim((string) $request->get('q', ''));

        $query = Exercise::query();
        if ($q !== '') {
            $query->where(function ($qb) use ($q) {
                $qb->where('title', 'like', "%{$q}%")
                   ->orWhere('slug', 'like', "%{$q}%")
                   ->orWhere('type', 'like', "%{$q}%");
            });
        }

        $results = $query->orderBy('title')->limit(20)->get()->map(fn (Exercise $e) => [
            'slug' => $e->slug,
            'label' => $e->title,
            'title' => $e->title,
            'type' => $e->type,
            'meta' => $e->type,
        ]);

        return response()->json(['results' => $results]);
    }

    private function transposeExerciseContent(array $content, string $fromKey, string $toKey): array
    {
        $delta = $this->semitone($toKey) - $this->semitone($fromKey);
        if ($delta === 0 || !isset($content['sections']) || !is_array($content['sections'])) {
            return $content;
        }

        foreach ($content['sections'] as &$section) {
            if (!isset($section['measures']) || !is_array($section['measures'])) continue;
            foreach ($section['measures'] as &$measure) {
                if (!isset($measure['chords']) || !is_array($measure['chords'])) continue;
                foreach ($measure['chords'] as &$chord) {
                    if (!is_array($chord)) continue;
                    if (!empty($chord['symbol']) && is_string($chord['symbol'])) {
                        $chord['symbol'] = $this->transposeSymbol($chord['symbol'], $delta);
                    }
                    if (!empty($chord['slug']) && is_string($chord['slug'])) {
                        $chord['slug'] = $this->transposeSlugRoot($chord['slug'], $delta);
                    }
                }
                unset($chord);
            }
            unset($measure);
        }
        unset($section);

        return $content;
    }

    private function transposeSymbol(string $symbol, int $delta): string
    {
        if (!preg_match('/^([A-G](?:#|b)?)(.*)$/', $symbol, $m)) {
            return $symbol;
        }

        $root = $m[1];
        $rest = $m[2] ?? '';
        $preferFlat = str_contains($root, 'b');
        $next = $this->transposeNote($root, $delta, $preferFlat);

        return $next . $rest;
    }

    private function transposeSlugRoot(string $slug, int $delta): string
    {
        if (!preg_match('/^([a-g](?:#|b)?)(-.+)$/', $slug, $m)) {
            return $slug;
        }

        $root = strtoupper($m[1]);
        $preferFlat = str_contains($m[1], 'b');
        $next = strtolower($this->transposeNote($root, $delta, $preferFlat));

        return $next . $m[2];
    }

    private function transposeNote(string $note, int $delta, bool $preferFlat): string
    {
        $namesSharp = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];
        $namesFlat = ['C', 'Db', 'D', 'Eb', 'E', 'F', 'Gb', 'G', 'Ab', 'A', 'Bb', 'B'];
        $idx = $this->semitone($note);
        $to = ($idx + $delta) % 12;
        if ($to < 0) $to += 12;

        return $preferFlat ? $namesFlat[$to] : $namesSharp[$to];
    }

    private function semitone(string $note): int
    {
        $map = [
            'C' => 0, 'B#' => 0,
            'C#' => 1, 'DB' => 1,
            'D' => 2,
            'D#' => 3, 'EB' => 3,
            'E' => 4, 'FB' => 4,
            'F' => 5, 'E#' => 5,
            'F#' => 6, 'GB' => 6,
            'G' => 7,
            'G#' => 8, 'AB' => 8,
            'A' => 9,
            'A#' => 10, 'BB' => 10,
            'B' => 11, 'CB' => 11,
        ];

        $up = strtoupper(trim($note));

        return $map[$up] ?? 0;
    }
}
