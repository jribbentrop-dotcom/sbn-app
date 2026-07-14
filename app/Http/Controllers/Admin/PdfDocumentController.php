<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PdfDocumentStoreRequest;
use App\Http\Requests\Admin\PdfDocumentUpdateRequest;
use App\Models\ChordDiagram;
use App\Models\Exercise;
use App\Models\Leadsheet;
use App\Models\PdfDocument;
use App\Models\RhythmPattern;
use Illuminate\Http\Request;

class PdfDocumentController extends Controller
{
    public function index()
    {
        $documents = PdfDocument::orderBy('title')->get();

        $templateLabels = [];
        foreach (PdfDocument::templateKeys() as $key) {
            $schema = require config_path("pdf/templates/{$key}.php");
            $templateLabels[$key] = $schema['label'] ?? $key;
        }

        return view('admin.pdf.index', compact('documents', 'templateLabels'));
    }

    public function store(PdfDocumentStoreRequest $request)
    {
        $doc = PdfDocument::create([
            'template_key' => 'composed',
            'title'        => $request->input('title'),
            'slug'         => $request->input('slug'),
            'pages'        => $request->pagesArray(),
            'status'       => 'draft',
            'content'      => [],
        ]);

        return redirect()->route('admin.pdf.edit', $doc->slug)
            ->with('success', 'Document created.');
    }

    public function edit(PdfDocument $document)
    {
        $schema = $document->editorSchema();
        return view('admin.pdf.edit', compact('document', 'schema'));
    }

    public function update(PdfDocumentUpdateRequest $request, PdfDocument $document)
    {
        $document->update([
            'title'   => $request->input('title', $document->title),
            'status'  => $request->input('status', $document->status),
            'content' => $request->contentValue(),
        ]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('admin.pdf.edit', $document->slug)
            ->with('success', 'Document saved.');
    }

    public function destroy(PdfDocument $document)
    {
        $document->delete();

        return redirect()->route('admin.pdf.index')->with('success', "Deleted \"{$document->title}\".");
    }

    // ── Autocomplete endpoints ─────────────────────────────────────────────────

    public function searchChords(Request $request)
    {
        $q = $request->input('q', '');

        $rows = ChordDiagram::where('slug', 'like', "%{$q}%")
            ->orWhere('name', 'like', "%{$q}%")
            ->orderBy('slug')
            ->limit(20)
            ->get(['slug', 'name']);

        return response()->json($rows->map(fn ($r) => ['slug' => $r->slug, 'label' => $r->name]));
    }

    public function searchRhythms(Request $request)
    {
        $q = $request->input('q', '');

        $rows = RhythmPattern::where('slug', 'like', "%{$q}%")
            ->orWhere('name', 'like', "%{$q}%")
            ->orderBy('slug')
            ->limit(20)
            ->get(['slug', 'name']);

        return response()->json($rows->map(fn ($r) => ['slug' => $r->slug, 'label' => $r->name]));
    }

    public function searchSongs(Request $request)
    {
        $q = $request->input('q', '');

        $rows = Leadsheet::where('slug', 'like', "%{$q}%")
            ->orWhere('title', 'like', "%{$q}%")
            ->orderBy('slug')
            ->limit(20)
            ->get(['slug', 'title']);

        return response()->json($rows->map(fn ($r) => ['slug' => $r->slug, 'label' => $r->title]));
    }

    // Practice TAB source picker: searches leadsheets AND exercises together,
    // since practice_tab_slug can slice bars from either (both have tab_xml).
    public function searchTabSources(Request $request)
    {
        $q = $request->input('q', '');

        $songs = Leadsheet::where('slug', 'like', "%{$q}%")
            ->orWhere('title', 'like', "%{$q}%")
            ->orderBy('slug')
            ->limit(20)
            ->get(['slug', 'title'])
            ->map(fn ($r) => ['slug' => $r->slug, 'label' => $r->title, 'kind' => 'leadsheet']);

        $exercises = Exercise::where('slug', 'like', "%{$q}%")
            ->orWhere('title', 'like', "%{$q}%")
            ->orderBy('slug')
            ->limit(20)
            ->get(['slug', 'title'])
            ->map(fn ($r) => ['slug' => $r->slug, 'label' => $r->title, 'kind' => 'exercise']);

        return response()->json($songs->concat($exercises)->sortBy('slug')->values());
    }
}
