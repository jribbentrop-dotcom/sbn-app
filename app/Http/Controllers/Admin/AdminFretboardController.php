<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FretboardRequest;
use App\Models\Fretboard;

class AdminFretboardController extends Controller
{
    public function index()
    {
        $fretboards = Fretboard::orderBy('title')->get();
        return view('admin.fretboards.index', compact('fretboards'));
    }

    public function create()
    {
        $fretboard = new Fretboard([
            'display_mode'    => 'chord',
            'root_note'       => null,
            'fret_count'      => 12,
            'start_fret'      => 1,
            'show_guide_tones' => false,
            'show_rh_fingers'  => false,
            'voicings'        => [
                ['label' => '', 'frets' => 'xxxxxx', 'fingers' => '000000', 'interval_labels' => ''],
            ],
            'start_window'    => 0,
        ]);
        $isNew = true;
        return view('admin.fretboards.edit', compact('fretboard', 'isNew'));
    }

    public function store(FretboardRequest $request)
    {
        $data = $request->payload();

        Fretboard::create($data);

        return redirect()->route('admin.fretboards.index')
            ->with('success', 'Fretboard created.');
    }

    public function edit(Fretboard $fretboard)
    {
        $isNew = false;
        return view('admin.fretboards.edit', compact('fretboard', 'isNew'));
    }

    public function update(FretboardRequest $request, Fretboard $fretboard)
    {
        $data = $request->payload();

        $fretboard->update($data);

        return redirect()->route('admin.fretboards.edit', $fretboard)
            ->with('success', 'Fretboard updated.');
    }

    public function destroy(Fretboard $fretboard)
    {
        $fretboard->delete();
        return redirect()->route('admin.fretboards.index')
            ->with('success', 'Fretboard deleted.');
    }

    /**
     * Public search endpoint consumed by LessonPalette.vue.
     * GET /api/sbn/fretboards?q=…
     */
    public function apiSearch(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $q = trim((string) $request->get('q', ''));

        $query = Fretboard::query();
        if ($q !== '') {
            $query->where(function ($qb) use ($q) {
                $qb->where('title', 'like', "%{$q}%")
                   ->orWhere('slug', 'like', "%{$q}%")
                   ->orWhere('display_mode', 'like', "%{$q}%");
            });
        }

        $results = $query->orderBy('title')->limit(30)->get()->map(fn (Fretboard $fb) => [
            'slug'      => $fb->slug,
            'label'     => $fb->title,
            'meta'      => $fb->display_mode,
            // Positions-mode window labels, so the palette can offer a
            // "which position?" picker without a second round-trip.
            'windows'   => $fb->display_mode === 'positions' ? ($fb->windows ?? []) : null,
            'root_note' => $fb->root_note,
        ]);

        return response()->json(['results' => $results]);
    }

    /**
     * Public JSON endpoint consumed by mountSbnNodes.ts.
     * GET /api/sbn/fretboards/{slug}
     */
    public function apiShow(string $slug)
    {
        $fb = Fretboard::where('slug', $slug)->firstOrFail();

        return response()->json([
            'slug'             => $fb->slug,
            'title'            => $fb->title,
            'root_note'        => $fb->root_note,
            'description'      => $fb->description,
            'display_mode'     => $fb->display_mode,
            'fret_count'       => $fb->fret_count,
            'start_fret'       => $fb->start_fret,
            'show_guide_tones' => $fb->show_guide_tones,
            'show_rh_fingers'  => $fb->show_rh_fingers,
            'voicings'         => $fb->voicings ?? [],
            'windows'          => $fb->windows ?? [],
            'start_window'     => $fb->start_window ?? 0,
        ]);
    }

}
