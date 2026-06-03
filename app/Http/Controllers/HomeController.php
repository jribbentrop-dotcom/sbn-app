<?php

namespace App\Http\Controllers;

use App\Models\RhythmPattern;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function index(): Response
    {
        $pattern = RhythmPattern::where('category', 'bossa-nova')
            ->orderByDesc('default_bpm')
            ->first();

        $rhythmPattern = $pattern ? [
            'name'          => $pattern->name,
            'beats'         => $pattern->beats,
            'gridType'      => $pattern->grid_type,
            'bpm'           => $pattern->default_bpm,
            'thumb'         => $pattern->thumb_pattern,
            'fingers'       => $pattern->rhythm_pattern,
            'timeSignature' => $pattern->time_signature,
            'percTop'       => $pattern->perc_top,
            'percBass'      => $pattern->perc_bass,
        ] : null;

        return Inertia::render('Home', [
            'rhythmPattern' => $rhythmPattern,
        ]);
    }
}
