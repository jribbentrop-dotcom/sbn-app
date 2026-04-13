<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChordDiagram;
use App\Models\VoicingDraft;
use App\Models\VoicingUsage;
use App\Services\VoicingCrossref;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class VoicingController extends Controller
{
    /**
     * Voicing crossref main page — stats + pending drafts.
     */
    public function index()
    {
        // ---------- stats ----------
        $totalMatches       = VoicingUsage::count();
        $diagramsWithMatch  = VoicingUsage::distinct('chord_diagram_id')->count('chord_diagram_id');
        $leadsheetsMatched  = VoicingUsage::distinct('leadsheet_id')->count('leadsheet_id');
        $totalLeadsheets    = Schema::hasTable('sbn_leadsheets')
                                ? DB::table('sbn_leadsheets')->count()
                                : 0;
        $totalDiagrams      = ChordDiagram::count();
        $pendingDrafts      = VoicingDraft::pending()->count();
        $dismissedDrafts    = VoicingDraft::dismissed()->count();

        $stats = [
            'total_matches'        => $totalMatches,
            'diagrams_with_match'  => $diagramsWithMatch,
            'leadsheets_matched'   => $leadsheetsMatched,
            'total_leadsheets'     => $totalLeadsheets,
            'total_diagrams'       => $totalDiagrams,
            'pending_drafts'       => $pendingDrafts,
            'dismissed_drafts'     => $dismissedDrafts,
        ];

        // ---------- most popular voicings ----------
        $popularVoicings = ChordDiagram::where('popularity', '>', 0)
            ->orderByDesc('popularity')
            ->limit(10)
            ->get(['id', 'name', 'quality', 'voicing_category', 'root_string', 'popularity']);

        // ---------- pending drafts grouped by leadsheet ----------
        $drafts = VoicingDraft::pending()
            ->orderBy('leadsheet_title')
            ->orderBy('chord_name')
            ->get();

        $groupedDrafts = $drafts->groupBy('leadsheet_id')->map(function ($group) {
            return [
                'title'  => $group->first()->leadsheet_title ?: 'Leadsheet #' . $group->first()->leadsheet_id,
                'drafts' => $group->values(),
            ];
        });

        // ---------- constants for labels ----------
        $voicingCategories = ChordDiagram::VOICING_CATEGORIES;
        $rootStrings       = ChordDiagram::ROOT_STRINGS;

        return view('admin.voicings.index', compact(
            'stats', 'popularVoicings', 'groupedDrafts',
            'voicingCategories', 'rootStrings'
        ));
    }

    // =========================================================================
    // API ENDPOINTS
    // =========================================================================

    /**
     * Dismiss a pending draft (AJAX).
     */
    public function dismiss(VoicingDraft $draft)
    {
        if ($draft->status !== 'pending') {
            return response()->json(['success' => false, 'error' => 'Draft is not pending.'], 422);
        }

        $draft->dismiss();

        return response()->json(['success' => true]);
    }

    /**
     * Clear all pending drafts (AJAX).
     */
    public function clearAll()
    {
        $deleted = VoicingDraft::pending()->delete();

        return response()->json(['success' => true, 'deleted' => $deleted]);
    }

    /**
     * Promote a draft to the chord diagram library (AJAX).
     *
     * Creates a new ChordDiagram pre-filled from the draft data,
     * marks the draft as promoted, and returns the edit URL.
     */
    public function promote(VoicingDraft $draft)
    {
        if ($draft->status !== 'pending') {
            return response()->json(['success' => false, 'error' => 'Draft is not pending.'], 422);
        }

        // Build diagram_data from fret string
        $diagramData = $draft->toDiagramData();
        $rootString  = $draft->detectRootString();

        // Generate a slug
        $slugRoot = strtolower(str_replace(['#', 'b'], ['s', 'b'], $draft->root_note ?? ''));
        $slug     = $slugRoot . ($draft->quality ?? '') . '-draft-' . $draft->id;

        // Ensure unique slug
        $finalSlug = $slug;
        $counter   = 1;
        while (ChordDiagram::where('slug', $finalSlug)->exists()) {
            $finalSlug = $slug . '-' . $counter;
            $counter++;
        }

        // Create the chord diagram
        $chord = ChordDiagram::create([
            'slug'              => $finalSlug,
            'name'              => ($draft->chord_name ?: 'Draft') . ' (from ' . ($draft->leadsheet_title ?: 'leadsheet') . ')',
            'root_note'         => $draft->root_note ?? '',
            'quality'           => $draft->quality ?? '',
            'extensions'        => '',
            'voicing_category'  => '',          // Teacher fills in
            'root_string'       => $rootString,
            'inversion'         => 'root',      // Teacher fills in
            'bass_note'         => $draft->bass_note ?? '',
            'start_fret'        => max(1, $draft->position ?? 1),
            'diagram_data'      => json_encode($diagramData),
            'interval_labels'   => '',
            'notes'             => '',
            'description'       => 'Imported from leadsheet: ' . ($draft->leadsheet_title ?? ''),
        ]);

        // Auto-compute intervals
        $computed = $chord->computeIntervalsAndNotes();
        $chord->update($computed);

        // Mark draft as promoted
        $draft->markPromoted($chord->id);

        return response()->json([
            'success'  => true,
            'edit_url' => route('admin.chords.edit', $chord),
        ]);
    }

    /**
     * Reprocess all leadsheets — extract voicings, match against chord library.
     */
    public function reprocess(VoicingCrossref $crossref)
    {
        $results = $crossref->processAllLeadsheets();

        return response()->json([
            'success' => true,
            'message' => "Processed {$results['processed']} leadsheets, skipped {$results['skipped']}. "
                       . "{$results['matched']} matches, {$results['unmatched']} unmatched.",
            'results' => $results,
        ]);
    }
}
