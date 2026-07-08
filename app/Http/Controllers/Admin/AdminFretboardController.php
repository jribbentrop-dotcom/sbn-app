<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Fretboard;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
            'theme'           => 'dark',
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

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['slug'] = $this->uniqueSlug($data['slug'] ?? '', null);

        Fretboard::create($data);

        return redirect()->route('admin.fretboards.index')
            ->with('success', 'Fretboard created.');
    }

    public function edit(Fretboard $fretboard)
    {
        $isNew = false;
        return view('admin.fretboards.edit', compact('fretboard', 'isNew'));
    }

    public function update(Request $request, Fretboard $fretboard)
    {
        $data = $this->validated($request);
        $data['slug'] = $this->uniqueSlug($data['slug'] ?? '', $fretboard->id);

        $fretboard->update($data);

        return redirect()->route('admin.fretboards.index')
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
            'slug'  => $fb->slug,
            'label' => $fb->title,
            'meta'  => $fb->display_mode,
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
            'description'      => $fb->description,
            'display_mode'     => $fb->display_mode,
            'theme'            => $fb->theme,
            'fret_count'       => $fb->fret_count,
            'start_fret'       => $fb->start_fret,
            'show_guide_tones' => $fb->show_guide_tones,
            'show_rh_fingers'  => $fb->show_rh_fingers,
            'voicings'         => $fb->voicings ?? [],
            'windows'          => $fb->windows ?? [],
            'start_window'     => $fb->start_window ?? 0,
        ]);
    }

    // ─────────────────────────────────────────────────────────────

    private function validated(Request $request): array
    {
        $raw = $request->validate([
            'title'            => 'required|string|max:255',
            'slug'             => 'nullable|string|max:120',
            'description'      => 'nullable|string|max:1000',
            'display_mode'     => 'required|in:chord,scale,sequence,positions',
            'theme'            => 'required|in:dark,light',
            'fret_count'       => 'required|integer|min:4|max:24',
            'start_fret'       => 'required|integer|min:1|max:20',
            'show_guide_tones' => 'nullable|boolean',
            'show_rh_fingers'  => 'nullable|boolean',
            'voicings'         => 'nullable|string', // JSON string from hidden field
            'windows'          => 'nullable|string', // JSON string from hidden field (positions mode)
            'start_window'     => 'nullable|integer|min:0|max:255',
        ]);

        // Checkboxes arrive as '1' or absent; cast to bool
        $raw['show_guide_tones'] = (bool) ($raw['show_guide_tones'] ?? false);
        $raw['show_rh_fingers']  = (bool) ($raw['show_rh_fingers']  ?? false);

        // Decode voicings JSON → array
        $raw['voicings'] = $raw['voicings']
            ? json_decode($raw['voicings'], true) ?? []
            : [];

        // Decode windows JSON → array (positions mode; null when unused)
        $raw['windows'] = ($raw['windows'] ?? null)
            ? json_decode($raw['windows'], true) ?? []
            : [];

        // Clamp start_window to a valid index into windows[] (0 when out of range)
        $windowCount = count($raw['windows']);
        $startWindow = (int) ($raw['start_window'] ?? 0);
        $raw['start_window'] = ($windowCount > 0 && $startWindow >= 0 && $startWindow < $windowCount)
            ? $startWindow
            : 0;

        // Default slug from title if blank
        if (empty($raw['slug'])) {
            $raw['slug'] = Str::slug($raw['title']);
        }

        return $raw;
    }

    private function uniqueSlug(string $slug, ?int $exceptId): string
    {
        $base = Str::slug($slug) ?: 'fretboard';
        $candidate = $base;
        $i = 2;
        while (
            Fretboard::where('slug', $candidate)
                ->when($exceptId, fn($q) => $q->where('id', '!=', $exceptId))
                ->exists()
        ) {
            $candidate = $base . '-' . $i++;
        }
        return $candidate;
    }
}
