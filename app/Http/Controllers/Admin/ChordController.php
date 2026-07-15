<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChordDiagramRequest;
use App\Http\Requests\Admin\StoreChordAliasRequest;
use App\Http\Requests\Admin\UpdateChordDescriptionRequest;
use App\Models\ChordDiagram;
use App\Models\ChordDiagramAlias;
use App\Models\VoicingDraft;
use App\Models\VoicingUsage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ChordController extends Controller
{
    /**
     * Chord diagrams index — card grid + unmatched voicings tab.
     */
    public function index(Request $request)
    {
        $stats = ChordDiagram::getStats();

        // All diagrams serialised for Alpine.js client-side filtering
        $chords = ChordDiagram::query()
            ->orderBy('voicing_category')
            ->orderBy('quality')
            ->orderBy('root_note')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(function ($c) {
                return [
                    'id'               => $c->id,
                    'slug'             => $c->slug,
                    'name'             => $c->name,
                    'root_note'        => $c->root_note,
                    'quality'          => $c->quality,
                    'quality_label'    => $c->quality_label,
                    'quality_short'    => self::shortQualityLabel($c->quality),
                    'extensions'       => $c->extensions,
                    'voicing_category' => $c->voicing_category,
                    'category_label'   => $c->category_label,
                    'root_string'      => $c->root_string,
                    'root_string_label'=> $c->root_string_label,
                    'inversion'        => $c->inversion ?? 'root',
                    'inversion_label'  => $c->inversion_label,
                    'bass_note'        => $c->bass_note,
                    'shape_family'     => $c->shape_family,
                    'is_fixed_position'=> $c->is_fixed_position,
                    'start_fret'       => $c->start_fret,
                    'diagram_data'     => json_decode($c->diagram_data, true) ?: ['positions'=>[],'barres'=>[],'muted'=>[],'open'=>[]],
                    'interval_labels'  => $c->interval_labels,
                    'notes'            => $c->notes,
                    'shape_slug'       => $c->shape_slug,
                    'description'      => $c->description ?? '',
                ];
            });

        $voicingCategories = ChordDiagram::VOICING_CATEGORIES;
        $chordQualities    = ChordDiagram::CHORD_QUALITIES;
        $rootStrings       = ChordDiagram::ROOT_STRINGS;

        // ---------- Voicing crossref data (unmatched tab) ----------
        $pendingDrafts = VoicingDraft::pending()
            ->orderBy('leadsheet_title')
            ->orderBy('chord_name')
            ->get();

        $groupedDrafts = $pendingDrafts->groupBy('leadsheet_id')->map(function ($group) {
            return [
                'title'  => $group->first()->leadsheet_title ?: 'Leadsheet #' . $group->first()->leadsheet_id,
                'drafts' => $group->values(),
            ];
        });

        $pendingCount = $pendingDrafts->count();

        // Most popular voicings (with diagram data for rendering)
        $popularVoicings = ChordDiagram::where('popularity', '>', 0)
            ->orderByDesc('popularity')
            ->limit(10)
            ->get();

        // Enriched voicings tab — diagrams matched with extra/doubled notes
        // Groups sbn_voicing_usage rows that have added_notes, joined to diagram + leadsheet
        $enrichedGroups = DB::table('sbn_voicing_usage as u')
            ->join('sbn_chord_diagrams as d', 'd.id', '=', 'u.chord_diagram_id')
            ->join('sbn_leadsheets as l', 'l.id', '=', 'u.leadsheet_id')
            ->whereNotNull('u.added_notes')
            ->where('u.added_notes', '!=', '')
            ->select(
                'u.chord_diagram_id',
                'd.name as diagram_name',
                'd.slug as diagram_slug',
                'd.diagram_data',
                'd.start_fret',
                'd.interval_labels',
                'u.chord_name',
                'u.added_notes',
                'l.id as leadsheet_id',
                'l.title as leadsheet_title',
                'u.id as usage_id'
            )
            ->orderBy('d.name')
            ->orderBy('l.title')
            ->get()
            ->groupBy('chord_diagram_id')
            ->map(function ($rows) {
                $first = $rows->first();
                return [
                    'diagram_id'     => $first->chord_diagram_id,
                    'diagram_name'   => $first->diagram_name,
                    'diagram_slug'   => $first->diagram_slug,
                    'diagram_data'   => json_decode($first->diagram_data, true) ?: ['positions'=>[],'barres'=>[],'muted'=>[],'open'=>[]],
                    'start_fret'     => $first->start_fret,
                    'interval_labels'=> $first->interval_labels,
                    'count'          => $rows->count(),
                    'occurrences'    => $rows->map(fn($r) => [
                        'usage_id'        => $r->usage_id,
                        'chord_name'      => $r->chord_name,
                        'added_notes'     => $r->added_notes,
                        'leadsheet_id'    => $r->leadsheet_id,
                        'leadsheet_title' => $r->leadsheet_title,
                    ])->values(),
                ];
            })
            ->sortByDesc('count')
            ->values();

        $enrichedCount = $enrichedGroups->count();

        return view('admin.chords.index', compact(
            'stats', 'chords', 'voicingCategories', 'chordQualities', 'rootStrings',
            'groupedDrafts', 'pendingCount', 'popularVoicings',
            'enrichedGroups', 'enrichedCount'
        ));
    }

    /**
     * Show create form.
     */
    public function create()
    {
        return view('admin.chords.edit', [
            'chord'             => null,
            'isNew'             => true,
            'voicingCategories' => ChordDiagram::VOICING_CATEGORIES,
            'chordQualities'    => ChordDiagram::CHORD_QUALITIES,
            'extensions'        => ChordDiagram::EXTENSIONS,
            'rootNotes'         => ChordDiagram::ROOT_NOTES,
            'rootStrings'       => ChordDiagram::ROOT_STRINGS,
            'inversions'        => ChordDiagram::INVERSIONS,
            'diagramData'       => ['positions' => [], 'barres' => [], 'muted' => [6, 1], 'open' => []],
            'aliases'           => collect(),
        ]);
    }

    /**
     * Show edit form.
     */
    public function edit(ChordDiagram $chord)
    {
        $diagramData = json_decode($chord->diagram_data, true)
            ?: ['positions' => [], 'barres' => [], 'muted' => [], 'open' => []];

        $aliases = ChordDiagramAlias::where('diagram_id', $chord->id)
            ->orderBy('id')
            ->get();

        return view('admin.chords.edit', [
            'chord'             => $chord,
            'isNew'             => false,
            'voicingCategories' => ChordDiagram::VOICING_CATEGORIES,
            'chordQualities'    => ChordDiagram::CHORD_QUALITIES,
            'extensions'        => ChordDiagram::EXTENSIONS,
            'rootNotes'         => ChordDiagram::ROOT_NOTES,
            'rootStrings'       => ChordDiagram::ROOT_STRINGS,
            'inversions'        => ChordDiagram::INVERSIONS,
            'diagramData'       => $diagramData,
            'aliases'           => $aliases,
        ]);
    }

    /**
     * Store a new chord diagram.
     */
    public function store(ChordDiagramRequest $request)
    {
        $validated = $this->normalizeChordData($request->validated());

        if (empty($validated['slug'])) {
            $validated['slug'] = $this->generateSlug($validated);
        }
        $validated['slug'] = $this->ensureUniqueSlug($validated['slug']);

        if (empty($validated['name'])) {
            $validated['name'] = $this->generateName($validated);
        }

        $chord = ChordDiagram::create($validated);

        $computed = $chord->computeIntervalsAndNotes();
        $chord->update($computed);

        return redirect()
            ->route('admin.chords.edit', $chord)
            ->with('success', 'Chord diagram created.');
    }

    /**
     * Update an existing chord diagram.
     */
    public function update(ChordDiagramRequest $request, ChordDiagram $chord)
    {
        $validated = $this->normalizeChordData($request->validated());

        if (empty($validated['slug'])) {
            $validated['slug'] = $this->generateSlug($validated);
        }
        $validated['slug'] = $this->ensureUniqueSlug($validated['slug'], $chord->id);

        if (empty($validated['name'])) {
            $validated['name'] = $this->generateName($validated);
        }

        $chord->update($validated);

        $computed = $chord->computeIntervalsAndNotes();
        $chord->update($computed);

        return redirect()
            ->route('admin.chords.edit', $chord)
            ->with('success', 'Chord diagram updated.');
    }

    /**
     * Short quality labels for card display.
     */
    private static function shortQualityLabel(string $quality): string
    {
        return match ($quality) {
            'maj'   => 'Major',
            'min'   => 'Minor',
            'aug'   => 'Aug',
            'dim'   => 'Dim',
            '5'     => 'Power',
            'sus4'  => 'Sus4',
            'sus2'  => 'Sus2',
            'add9'  => 'Add9',
            'madd9' => 'Minor Add9',
            'maj7'  => 'Major 7',
            'm7'    => 'Minor 7',
            'dom7'  => 'Dominant 7',
            'm7b5'  => 'm7♭5',
            'o7'    => 'Diminished 7',
            'maj6'  => 'Major 6',
            'm6'    => 'Minor 6',
            'mMaj7' => 'mMaj7',
            'aug7'  => 'Aug7',
            '7sus4' => '7sus4',
            default => $quality,
        };
    }

    // =========================================================================
    // VALIDATION & HELPERS
    // =========================================================================

    private function normalizeChordData(array $data): array
    {
        $decoded = json_decode($data['diagram_data'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $data['diagram_data'] = json_encode([
                'positions' => [], 'barres' => [], 'muted' => [6, 1], 'open' => [],
            ]);
        }

        $data['inversion'] = $data['inversion'] ?? 'root';
        $data['is_fixed_position'] = $data['is_fixed_position'] ?? false;

        return $data;
    }

    private function generateSlug(array $data): string
    {
        $parts = [$data['quality'], $data['voicing_category'], $data['root_string']];

        $inv = $data['inversion'] ?? 'root';
        if ($inv && $inv !== 'root') {
            $parts[] = $inv;
        }
        if (!empty($data['extensions'])) {
            $parts[] = str_replace(['#', '♯', '♭', ' '], ['s', 's', 'b', ''], $data['extensions']);
        }
        if (!empty($data['bass_note'])) {
            $parts[] = 'over' . $data['bass_note'];
        }

        return implode('-', $parts);
    }

    private function ensureUniqueSlug(string $slug, ?int $excludeId = null): string
    {
        $query = ChordDiagram::where('slug', $slug);
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        if (!$query->exists()) {
            return $slug;
        }

        $counter = 1;
        while (true) {
            $candidate = $slug . '-' . $counter;
            $q = ChordDiagram::where('slug', $candidate);
            if ($excludeId) {
                $q->where('id', '!=', $excludeId);
            }
            if (!$q->exists()) {
                return $candidate;
            }
            $counter++;
        }
    }

    private function generateName(array $data): string
    {
        $qualityLabels = [
            'maj' => 'Maj', 'min' => 'min', 'aug' => 'Aug', 'dim' => 'Dim',
            '5' => '5', 'sus4' => 'sus4', 'sus2' => 'sus2', 'add9' => 'add9', 'madd9' => 'madd9',
            'maj7' => 'Maj7', 'm7' => 'm7', 'dom7' => '7', 'm7b5' => 'm7♭5',
            'o7' => '°7', 'maj6' => 'Maj6', 'm6' => 'm6', 'mMaj7' => 'mMaj7', 'aug7' => 'Aug7',
            'quartal' => 'Quartal',
        ];
        $catLabels = ChordDiagram::VOICING_CATEGORIES;
        $rootLabels = ['roote' => 'E', 'roota' => 'A', 'rootd' => 'D', 'rootg' => 'G', 'custom' => '?'];
        $invLabels = ['root' => '', 'inv1' => '1st Inv', 'inv2' => '2nd Inv', 'inv3' => '3rd Inv'];

        $name = $qualityLabels[$data['quality']] ?? $data['quality'];
        if (!empty($data['extensions'])) {
            $name .= $data['extensions'];
        }
        $name .= ' ' . ($catLabels[$data['voicing_category']] ?? $data['voicing_category']);

        $inv = $data['inversion'] ?? 'root';
        if ($inv && $inv !== 'root') {
            $name .= ' ' . ($invLabels[$inv] ?? $inv);
        }
        $name .= ' (Root ' . ($rootLabels[$data['root_string']] ?? $data['root_string']) . ')';

        return $name;
    }

    // =========================================================================
    // API ENDPOINTS
    // =========================================================================

    /**
     * Delete a chord diagram (AJAX).
     */
    public function updateDescription(UpdateChordDescriptionRequest $request, ChordDiagram $chord)
    {
        $validated = $request->validated();
        $chord->update(['description' => $validated['description'] ?? '']);
        return response()->json(['success' => true, 'description' => $chord->description]);
    }

    public function destroy(ChordDiagram $chord)
    {
        ChordDiagramAlias::where('diagram_id', $chord->id)->delete();
        $chord->delete();
        return response()->json(['success' => true]);
    }

    /**
     * Duplicate a chord diagram (AJAX).
     */
    public function duplicate(ChordDiagram $chord)
    {
        $baseSlug = $chord->slug . '-copy';
        $slug = $baseSlug;
        $counter = 1;
        while (ChordDiagram::where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        $new = $chord->replicate();
        $new->slug       = $slug;
        $new->name       = $chord->name . ' (Copy)';
        $new->is_default = false;
        $new->save();

        return response()->json([
            'success' => true,
            'id'      => $new->id,
            'slug'    => $new->slug,
        ]);
    }

    /**
     * Recompute interval labels and notes for all diagrams (AJAX).
     */
    public function recomputeIntervals()
    {
        $result = ChordDiagram::recomputeAllIntervals();
        return response()->json(['success' => true, 'data' => $result]);
    }

    // =========================================================================
    // ALIAS API ENDPOINTS
    // =========================================================================

    /**
     * Get all aliases for a diagram (AJAX).
     */
    public function getAliases(ChordDiagram $chord)
    {
        $aliases = ChordDiagramAlias::where('diagram_id', $chord->id)
            ->orderBy('id')
            ->get();

        return response()->json(['success' => true, 'data' => $aliases]);
    }

    /**
     * Add an alias to a diagram (AJAX).
     */
    public function storeAlias(StoreChordAliasRequest $request, ChordDiagram $chord)
    {
        $data = $request->validated();

        $altName = ChordDiagramAlias::buildAltName(
            $data['alt_root_note'],
            $data['alt_quality'],
            $data['alt_extensions'] ?? '',
            $data['alt_bass_note'] ?? ''
        );

        $computed = ChordDiagramAlias::computeAliasIntervals(
            $chord,
            $data['alt_root_note'],
            $data['alt_quality']
        );

        $alias = ChordDiagramAlias::create([
            'diagram_id'      => $chord->id,
            'alt_name'        => $altName,
            'alt_root_note'   => $data['alt_root_note'],
            'alt_quality'     => $data['alt_quality'],
            'alt_extensions'  => $data['alt_extensions'] ?? '',
            'alt_bass_note'   => $data['alt_bass_note'] ?? '',
            'interval_labels' => $computed['interval_labels'],
            'notes'           => $computed['notes'],
            'created_at'      => now(),
        ]);

        return response()->json(['success' => true, 'data' => $alias]);
    }

    /**
     * Delete an alias (AJAX).
     */
    public function destroyAlias(ChordDiagramAlias $alias)
    {
        $alias->delete();
        return response()->json(['success' => true]);
    }
}
