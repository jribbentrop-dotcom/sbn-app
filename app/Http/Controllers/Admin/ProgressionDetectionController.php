<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Leadsheet;
use App\Services\ProgressionDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 5d — Progression detection API for the admin leadsheet editor.
 *
 * Endpoints:
 *   POST /admin/leadsheets/{id}/detect-progressions  → run detection + store
 *   GET  /admin/leadsheets/{id}/analyse-progressions  → analyse without storing (preview)
 *   POST /admin/leadsheets/reprocess-progressions     → batch reprocess all
 */
class ProgressionDetectionController extends Controller
{
    protected ProgressionDetector $detector;

    public function __construct(ProgressionDetector $detector)
    {
        $this->detector = $detector;
    }

    /**
     * Run progression detection on a single leadsheet and store results.
     */
    public function detect(Leadsheet $leadsheet): JsonResponse
    {
        $result = $this->detector->processLeadsheet($leadsheet);

        return response()->json([
            'success' => true,
            'data'    => $result,
        ]);
    }

    /**
     * Analyse a leadsheet's progressions (preview — does NOT store results).
     * Returns section-by-section numerals and detected matches.
     */
    public function analyse(Leadsheet $leadsheet): JsonResponse
    {
        $analysis = $this->detector->analyseLeadsheet($leadsheet);

        return response()->json([
            'success' => true,
            'data'    => $analysis,
        ]);
    }

    /**
     * Batch reprocess all leadsheets.
     */
    public function reprocessAll(): JsonResponse
    {
        $result = $this->detector->processAllLeadsheets();

        return response()->json([
            'success' => true,
            'data'    => $result,
        ]);
    }
}
