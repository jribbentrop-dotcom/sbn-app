<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChordDiagram;
use App\Models\Leadsheet;
use App\Models\PdfDocument;
use App\Models\RhythmPattern;
use App\Models\VoicingUsage;
use App\Services\TabXmlParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Spatie\Browsershot\Browsershot;

class PdfController extends Controller
{
    /**
     * Preview: render the Blade template as HTML in the browser.
     * /admin/pdf/preview/{slug}
     */
    public function preview(string $slug)
    {
        $doc   = PdfDocument::where('slug', $slug)->firstOrFail();
        $data  = $this->buildData($doc);
        $blade = $this->resolveBlade($doc);
        return view($blade, $data);
    }

    /**
     * Download: render via Browsershot to a PDF and stream it.
     * /admin/pdf/download/{slug}
     */
    public function download(string $slug)
    {
        $doc   = PdfDocument::where('slug', $slug)->firstOrFail();
        $data  = $this->buildData($doc);
        $blade = $this->resolveBlade($doc);
        $html  = view($blade, $data)->render();
        $title = $data['title'] ?? $slug;

        $pdf = Browsershot::html($html)
            ->setChromePath('C:\Program Files\Google\Chrome\Application\chrome.exe')
            ->format('A4')
            ->showBackground()
            ->waitUntilNetworkIdle()
            ->pdf();

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $slug . '.pdf"',
        ]);
    }

    // -------------------------------------------------------------------------

    private function resolveBlade(PdfDocument $doc): string
    {
        // Composed documents (pages stored in DB) use the shared layout
        if (!empty($doc->pages)) {
            return 'admin.pdf.composed';
        }
        // Legacy: blade defined in the template PHP config
        return $doc->editorSchema()['blade'] ?? 'admin.pdf.composed';
    }

    /**
     * Build the view-data array for a PDF document sourced from the DB.
     */
    private function buildData(PdfDocument $doc): array
    {
        if (!empty($doc->pages)) {
            return $this->buildComposedData($doc);
        }
        if ($doc->template_key === 'top10') {
            return $this->buildTop10Data($doc);
        }

        $content = $doc->content ?? [];

        $title            = $content['title']       ?? $doc->slug;
        $subtitle         = $content['subtitle']    ?? null;
        $series           = $content['series']      ?? 'SBN Teaching Hub';
        $coverDescription = $content['description'] ?? null;
        $introHtml        = $content['intro_html']  ?? null;

        // Optional rhythm slugs
        $rhythmSlugs = $content['rhythms'] ?? [];
        $config      = [];  // kept for Blade compat (chord_descriptions key not needed)
        $rhythms = [];
        foreach ($rhythmSlugs as $rSlug) {
            $rRow = RhythmPattern::where('slug', $rSlug)->first();
            if (! $rRow) continue;
            $rhythms[] = [
                'slug'        => $rRow->slug,
                'name'        => $rRow->name,
                'description' => $rRow->description,
                'meta'        => ($rRow->time_signature ?? '') . ' · ' . ($rRow->default_bpm ?? '') . ' bpm',
                'svg'         => self::renderRhythmSvg(
                    $rRow->rhythm_pattern ?? '',
                    $rRow->thumb_pattern  ?? '',
                    '',
                    ($rRow->time_signature ?? '') . ' · ' . ($rRow->default_bpm ?? '') . ' bpm',
                ),
            ];
        }

        $chords = [];
        foreach ($content['chords'] ?? [] as $idx => $chordItem) {
            $chordSlug = is_array($chordItem) ? ($chordItem['slug'] ?? null) : $chordItem;
            if (! $chordSlug) continue;

            $row = ChordDiagram::where('slug', $chordSlug)->first();
            if (! $row) continue;

            $svg = $this->renderDiagramSvg($chordSlug);

            // Content description overrides DB description (falls back to DB if empty)
            $contentDesc = is_array($chordItem) ? ($chordItem['description'] ?? '') : '';
            $description = (strlen(trim($contentDesc)) > 0) ? $contentDesc : $row->description;

            $chords[] = [
                'slug'             => $row->slug,
                'name'             => $row->name,
                'voicing_category' => $row->voicing_category,
                'category_label'   => ChordDiagram::VOICING_CATEGORIES[$row->voicing_category] ?? $row->voicing_category,
                'root_string'      => $row->root_string,
                'root_string_label'=> $row->root_string_label ?? $row->root_string,
                'inversion'        => $row->inversion,
                'inversion_label'  => $row->inversion_label ?? $row->inversion,
                'shape_family'     => $row->shape_family,
                'interval_labels'  => $row->interval_labels,
                'notes'            => $row->notes,
                'description'      => $description,
                'svg'              => $svg,
                'notation_svg'     => null,
            ];
        }

        // Optional song examples
        $songExamples = [];
        foreach ($content['songs'] ?? [] as $songCfg) {
            $lsSlug = $songCfg['slug'] ?? null;
            if (! $lsSlug) continue;

            $ls = Leadsheet::where('slug', $lsSlug)->first();
            if (! $ls) continue;

            // 'layer' in the song config selects melody (default) or chord-comping TAB.
            $version = $ls->defaultVersion ?? $ls->versions()->first();
            $tabXml  = ($songCfg['layer'] ?? 'melody') === 'chord'
                ? ($version?->chord_tab_xml)
                : ($version?->melody_tab_xml ?: $ls->tab_xml);
            if (! $tabXml) continue;

            $parser  = new TabXmlParser();
            $tabData = $parser->parse($tabXml);

            $allMeasures = $tabData['measures'] ?? [];
            [$from, $to] = $songCfg['measures'] ?? [0, count($allMeasures) - 1];
            $slicedMeasures = array_slice($allMeasures, $from, $to - $from + 1);

            // Re-index measure indices to start from 0 for the renderer
            foreach ($slicedMeasures as $i => &$m) {
                $m['index'] = $i;
            }
            unset($m);

            // Split into rows of barsPerRow
            $barsPerRow = $songCfg['barsPerRow'] ?? 4;
            $rows = array_chunk($slicedMeasures, $barsPerRow);
            $tabSvgs = [];
            foreach ($rows as $rowMeasures) {
                $tabSvgs[] = self::renderTabSvg($rowMeasures, $tabData['timeSig'], $barsPerRow);
            }

            $songExamples[] = [
                'title'   => $songCfg['label'] ?? $ls->title,
                'tabSvgs' => $tabSvgs,
            ];
        }

        return compact('title', 'subtitle', 'series', 'coverDescription', 'introHtml', 'chords', 'rhythms', 'songExamples', 'config');
    }

    /**
     * Render a rhythm pattern as a static SVG strip.
     * Mirrors the visual logic of RhythmStrip.vue without Vue/AudioEngine.
     *
     * @param  string  $fingers   e.g. "x.xX.x.."  (x=hit, X=accent, .=rest)
     * @param  string  $thumb     e.g. "x...x..."
     * @param  string  $label     optional eyebrow label
     * @param  string  $meta      optional meta string (e.g. "2/4 · 87 bpm")
     * @param  string  $color     hit color (hex). Defaults to brand orange.
     */
    public static function renderRhythmSvg(
        string $fingers,
        string $thumb   = '',
        string $label   = '',
        string $meta    = '',
        string $color   = '#f39c12',
        string $accent  = '#e74c3c'
    ): string {
        $beats       = max(strlen($fingers), strlen($thumb), 1);
        $cellW       = 14;   // pt per cell
        $cellH       = 18;   // fingers row height
        $thumbH      = 7;    // thumb row height
        $gap         = 2;
        $eyebrowH    = ($label || $meta) ? 14 : 0;
        $rowsH       = $cellH + ($thumb !== '' ? $gap + $thumbH : 0);
        $totalH      = $eyebrowH + $rowsH;
        $totalW      = $beats * $cellW + ($beats - 1) * $gap;

        $restFill    = '#eef1f5';
        $restThumb   = '#e2e8f0';
        $hitFill     = $color;
        $accentFill  = $accent;

        $out = '<svg xmlns="http://www.w3.org/2000/svg"'
             . ' viewBox="0 0 ' . $totalW . ' ' . $totalH . '"'
             . ' width="' . $totalW . '" height="' . $totalH . '">';

        // Eyebrow
        if ($eyebrowH) {
            if ($label) {
                $out .= '<text x="0" y="10" font-family="DM Sans,sans-serif" font-size="8"'
                      . ' font-weight="600" fill="#5a5a5a">' . e($label) . '</text>';
            }
            if ($meta) {
                $out .= '<text x="' . $totalW . '" y="10" text-anchor="end"'
                      . ' font-family="DM Sans,sans-serif" font-size="7" fill="#8896a4">' . e($meta) . '</text>';
            }
        }

        // Fingers row
        for ($i = 0; $i < $beats; $i++) {
            $c   = $fingers[$i] ?? '.';
            $x   = $i * ($cellW + $gap);
            $y   = $eyebrowH;

            if ($c === '.') {
                // rest: slim bar centered in the cell
                $restY = $y + ($cellH - 5) / 2;
                $out .= '<rect x="' . $x . '" y="' . $restY . '" width="' . $cellW . '" height="5"'
                      . ' rx="2" fill="' . $restFill . '"/>';
            } else {
                $fill = ($c === 'X') ? $accentFill : $hitFill;
                $opacity = ($c === 'X') ? '1' : '0.82';
                $out .= '<rect x="' . $x . '" y="' . $y . '" width="' . $cellW . '" height="' . $cellH . '"'
                      . ' rx="3" fill="' . $fill . '" opacity="' . $opacity . '"/>';
            }
        }

        // Thumb row
        if ($thumb !== '') {
            $thumbY = $eyebrowH + $cellH + $gap;
            for ($i = 0; $i < $beats; $i++) {
                $c = $thumb[$i] ?? '.';
                $x = $i * ($cellW + $gap);

                if ($c === '.') {
                    $restY = $thumbY + ($thumbH - 3) / 2;
                    $out .= '<rect x="' . $x . '" y="' . $restY . '" width="' . $cellW . '" height="3"'
                          . ' rx="1" fill="' . $restThumb . '"/>';
                } else {
                    $fill = ($c === 'X') ? '#2c3e50' : '#5a5a5a';
                    $out .= '<rect x="' . $x . '" y="' . $thumbY . '" width="' . $cellW . '" height="' . $thumbH . '"'
                          . ' rx="2" fill="' . $fill . '" opacity="0.55"/>';
                }
            }
        }

        $out .= '</svg>';
        return $out;
    }

    /**
     * Render TAB measures as a static SVG row via render-tab.cjs.
     *
     * @param  array  $measures      Array of measure objects (see render-tab.cjs schema)
     * @param  string $timeSig       e.g. "4/4"
     * @param  int    $barsPerRow
     * @param  bool   $showChordNames
     */
    public static function renderTabSvg(
        array  $measures,
        string $timeSig       = '4/4',
        int    $barsPerRow    = 4,
        bool   $showChordNames = true
    ): string {
        $payload = json_encode(compact('measures', 'timeSig', 'barsPerRow', 'showChordNames'));

        // Write to a temp file (avoids PowerShell pipe encoding issues)
        $tmpFile = tempnam(sys_get_temp_dir(), 'sbn_tab_') . '.json';
        file_put_contents($tmpFile, $payload);

        $scriptPath = base_path('scripts/pdf/render-tab.cjs');
        $result     = Process::run("node \"{$scriptPath}\" \"{$tmpFile}\"");
        @unlink($tmpFile);

        if ($result->successful()) {
            return $result->output();
        }

        // Fallback placeholder
        return '<svg viewBox="0 0 640 98" width="640" height="98"><rect x="0" y="0" width="640" height="98" fill="none" stroke="#e2e8f0" stroke-width="1"/><text x="320" y="52" text-anchor="middle" font-size="9" fill="#8896a4">TAB (render failed)</text></svg>';
    }

    /**
     * Like renderTabSvg but renders chord diagrams above the staff.
     * Use for example pages; item pages use renderTabSvg (diagram shown separately).
     *
     * @param  array  $voicings  Map of chordName => inner SVG markup string (from render-diagram-inline.cjs)
     */
    public static function renderTabSvgWithDiagrams(
        array  $measures,
        string $timeSig        = '4/4',
        int    $barsPerRow     = 4,
        bool   $showChordNames = true,
        array  $voicings       = []
    ): string {
        $payload = json_encode(compact('measures', 'timeSig', 'barsPerRow', 'showChordNames', 'voicings'));

        $tmpFile = tempnam(sys_get_temp_dir(), 'sbn_tabdiag_') . '.json';
        file_put_contents($tmpFile, $payload);

        $scriptPath = base_path('scripts/pdf/render-tab-diagrams.cjs');
        $result     = Process::run("node \"{$scriptPath}\" \"{$tmpFile}\"");
        @unlink($tmpFile);

        if ($result->successful()) {
            return $result->output();
        }

        return '<svg viewBox="0 0 640 182" width="640" height="182"><rect x="0" y="0" width="640" height="182" fill="none" stroke="#e2e8f0" stroke-width="1"/><text x="320" y="94" text-anchor="middle" font-size="9" fill="#8896a4">TAB+diagrams (render failed)</text></svg>';
    }

    /**
     * Extract a TAB staff from a MuseScore SVG export.
     *
     * @param  string  $svgPath     Absolute or base_path()-relative path to the SVG file
     * @param  int     $systemIndex 1-based index over TAB-containing systems on the page
     */
    public static function extractTabSvg(string $svgPath, int $systemIndex = 1): string
    {
        if (! str_starts_with($svgPath, '/') && ! preg_match('/^[A-Za-z]:/', $svgPath)) {
            $svgPath = base_path($svgPath);
        }

        $scriptPath = base_path('scripts/pdf/extract-tab-svg.cjs');
        $result     = Process::run("node \"{$scriptPath}\" \"{$svgPath}\" {$systemIndex}");

        if ($result->successful()) {
            return $result->output();
        }

        $err = trim($result->errorOutput());
        return '<svg viewBox="0 0 560 80" width="560" height="80"><rect x="0" y="0" width="560" height="80" fill="none" stroke="#e2e8f0" stroke-width="1"/><text x="280" y="43" text-anchor="middle" font-size="9" fill="#8896a4">TAB extract failed: ' . e($err) . '</text></svg>';
    }

    /**
     * Build view-data for a composed document (pages stored in DB).
     * Reuses buildTop10Data and just injects the $pages array.
     */
    private function buildComposedData(PdfDocument $doc): array
    {
        $data          = $this->buildTop10Data($doc);
        $data['pages'] = $doc->pages ?? [];
        return $data;
    }

    /**
     * Build view-data for the top10 rich template.
     */
    private function buildTop10Data(PdfDocument $doc): array
    {
        $content = $doc->content ?? [];

        // ── Top-level authored fields ──────────────────────────────────────
        $title        = $content['title']        ?? 'TOP10 Bossa Nova Chords';
        $subtitle     = $content['subtitle']     ?? '';
        $eyebrow      = $content['eyebrow']      ?? '';
        $hook         = $content['hook']         ?? '';
        $facts        = $content['facts']        ?? '';
        $theory_title = $content['theory_title'] ?? 'Chord Theory in a Nutshell';
        $theory_html  = $content['theory_html']  ?? '';

        // ── Chord item pages ───────────────────────────────────────────────
        $chords = [];
        foreach ($content['chords'] ?? [] as $item) {
            if (empty($item['slug'])) continue;

            // Chord diagram SVG (color — no --bw flag)
            $diagramSvg = $this->renderDiagramSvg($item['slug']);

            // Practice TAB — slice from the item's own leadsheet (defaults to top10)
            $tabSvg  = '';
            $tabSlug = $item['practice_tab_slug'] ?? 'top10';
            $tabBars = $item['tab_bars'] ?? [0, 3];
            $barsPerRow = (int)($item['tab_bars_per_row'] ?? 4);

            $ls = Leadsheet::where('slug', $tabSlug)->first();
            if ($ls) {
                $version = $ls->defaultVersion ?? $ls->versions()->first();
                $tabXml  = ($item['tab_layer'] ?? 'melody') === 'chord'
                    ? ($version?->chord_tab_xml)
                    : ($version?->melody_tab_xml ?: $ls->tab_xml);
                if ($tabXml) {
                    $parser         = new TabXmlParser();
                    $chordNamesMap  = self::chordNamesMapFromLeadsheet($ls);
                    $tabData        = $parser->parse($tabXml, $chordNamesMap);
                    $allMeasures    = $tabData['measures'] ?? [];
                    // tab_bars are 1-indexed (bar 1 = first bar); convert to 0-indexed array offset
                    [$from, $to] = [intval($tabBars[0] ?? 1) - 1, intval($tabBars[1] ?? 4) - 1];
                    $sliced = array_slice($allMeasures, $from, $to - $from + 1);
                    foreach ($sliced as $k => &$m) { $m['index'] = $k; }
                    unset($m);
                    $tabSvg = self::renderTabSvg($sliced, $tabData['timeSig'] ?? '4/4', $barsPerRow);
                }
            }

            // Rhythm grid — fetch pattern strings for Blade
            $rhythmPattern = '';
            $rhythmThumb   = '';
            $rhythmLabels  = [];
            $rhythmSlug    = $item['rhythm_slug'] ?? '';
            if ($rhythmSlug) {
                $rRow = RhythmPattern::where('slug', $rhythmSlug)->first();
                if ($rRow) {
                    $rhythmPattern = $rRow->rhythm_pattern ?? '';
                    $rhythmThumb   = $rRow->thumb_pattern  ?? '';
                    // Build beat labels from time signature
                    $timeSig = $rRow->time_signature ?? '2/4';
                    $beats   = max(strlen($rhythmPattern), strlen($rhythmThumb), 1);
                    $rhythmLabels = self::buildRhythmLabels($timeSig, $beats);
                }
            }

            $chords[] = array_merge($item, [
                '_diagram_svg'    => $diagramSvg,
                '_tab_svg'        => $tabSvg,
                '_rhythm_pattern' => $rhythmPattern,
                '_rhythm_thumb'   => $rhythmThumb,
                '_rhythm_labels'  => $rhythmLabels,
            ]);
        }

        // ── Song example pages ─────────────────────────────────────────────
        $songs = [];
        foreach ($content['songs'] ?? [] as $songCfg) {
            $lsSlug = $songCfg['slug'] ?? null;
            if (!$lsSlug) continue;

            $ls = Leadsheet::where('slug', $lsSlug)->first();
            if (!$ls) continue;

            $version = $ls->defaultVersion ?? $ls->versions()->first();
            $tabXml  = ($songCfg['layer'] ?? 'melody') === 'chord'
                ? ($version?->chord_tab_xml)
                : ($version?->melody_tab_xml ?: $ls->tab_xml);
            if (!$tabXml) continue;

            $parser        = new TabXmlParser();
            $chordNamesMap = self::chordNamesMapFromLeadsheet($ls);
            $tabData       = $parser->parse($tabXml, $chordNamesMap);
            $allMeasures   = $tabData['measures'] ?? [];
            // bars are 1-indexed; convert to 0-indexed array offset
            $bars        = $songCfg['bars'] ?? [1, count($allMeasures)];
            [$from, $to] = [intval($bars[0] ?? 1) - 1, intval($bars[1] ?? count($allMeasures)) - 1];
            $sliced      = array_slice($allMeasures, $from, $to - $from + 1);
            foreach ($sliced as $k => &$m) { $m['index'] = $k; }
            unset($m);

            // Build voicings map: chordName => inner SVG markup for diagrams above the staff
            $voicings = self::buildVoicingsMap($ls->id, $sliced);

            $barsPerRow = (int)($songCfg['bars_per_row'] ?? 4);
            $rows       = array_chunk($sliced, $barsPerRow);
            $tabSvgs    = [];
            foreach ($rows as $rowMeasures) {
                $tabSvgs[] = self::renderTabSvgWithDiagrams($rowMeasures, $tabData['timeSig'] ?? '4/4', $barsPerRow, true, $voicings);
            }

            $songs[] = array_merge($songCfg, ['_tab_svgs' => $tabSvgs]);
        }

        return compact('title', 'subtitle', 'eyebrow', 'hook', 'facts', 'theory_title', 'theory_html', 'chords', 'songs');
    }

    /**
     * Build beat-label strings for the rhythm grid from a time signature + beat count.
     * Returns an array of labels matching each cell in the pattern string.
     *
     * 2/4 sixteenth (8 cells):  1 e + a 2 e + a
     * 4/4 eighth (8 cells):     1 + 2 + 3 + 4 +
     * 4/4 sixteenth (16 cells): 1 e + a 2 e + a 3 e + a 4 e + a
     */
    private static function buildRhythmLabels(string $timeSig, int $beats): array
    {
        $parts = explode('/', $timeSig);
        $num   = (int)($parts[0] ?? 4);

        if ($beats === $num) {
            // Quarter-note grid
            return array_map(fn($i) => (string)($i + 1), range(0, $beats - 1));
        }
        if ($beats === $num * 2) {
            // Eighth-note grid: 1 + 2 + …
            $labels = [];
            for ($i = 0; $i < $num; $i++) {
                $labels[] = (string)($i + 1);
                $labels[] = '+';
            }
            return $labels;
        }
        if ($beats === $num * 4) {
            // Sixteenth-note grid: 1 e + a 2 e + a …
            $labels = [];
            for ($i = 0; $i < $num; $i++) {
                $labels[] = (string)($i + 1);
                $labels[] = 'e';
                $labels[] = '+';
                $labels[] = 'a';
            }
            return $labels;
        }
        // Fallback: just number each cell
        return array_map(fn($i) => (string)($i + 1), range(0, $beats - 1));
    }

    /**
     * Extract chord-names-by-measure-index from a leadsheet's json_data column.
     * Returns e.g. [0 => ['Dm7'], 1 => ['G7', 'Cmaj7']]
     */
    private static function chordNamesMapFromLeadsheet(\App\Models\Leadsheet $ls): array
    {
        $map = [];
        if (!empty($ls->json_data)) {
            $json = json_decode($ls->json_data, true);
            foreach ($json['measures'] ?? [] as $i => $m) {
                $names = array_values(array_filter(
                    array_map(fn($c) => $c['name'] ?? '', $m['chords'] ?? [])
                ));
                if ($names) $map[$i] = $names;
            }
        }
        return $map;
    }

    /**
     * Build a voicings map { chordName => innerSvgMarkup } for chord names
     * that appear in $measures, looking up the best diagram via sbn_voicing_usage.
     */
    private static function buildVoicingsMap(int $leadsheetId, array $measures): array
    {
        // Collect unique chord names from the measures
        $names = [];
        foreach ($measures as $m) {
            foreach ($m['chordNames'] ?? [] as $name) {
                $names[$name] = true;
            }
        }
        if (empty($names)) return [];

        // Look up diagram slugs via voicing_usage for this leadsheet
        $usages = VoicingUsage::where('leadsheet_id', $leadsheetId)
            ->whereIn('chord_name', array_keys($names))
            ->with('diagram')
            ->get()
            ->groupBy('chord_name');

        $voicings = [];
        foreach ($usages as $chordName => $group) {
            // Pick the most popular diagram in the group
            $best = $group->sortByDesc(fn($u) => $u->diagram?->popularity ?? 0)->first();
            if (!$best?->diagram) continue;

            $slug       = $best->diagram->slug;
            $scriptPath = base_path('scripts/pdf/render-diagram.cjs');
            $result     = Process::run("node \"{$scriptPath}\" \"{$slug}\" --bw");
            if (!$result->successful()) continue;
            $fullSvg = $result->output();
            // Strip outer <svg ...> wrapper — keep only inner content
            $inner = preg_replace('/<svg[^>]*>(.*)<\/svg>/s', '$1', $fullSvg);
            if ($inner) $voicings[$chordName] = $inner;
        }

        return $voicings;
    }

    /**
     * Call the Node script to render a chord SVG.
     * Returns an SVG string, or a placeholder on failure.
     */
    private function renderDiagramSvg(string $slug): string
    {
        $scriptPath = base_path('scripts/pdf/render-diagram.cjs');
        $result     = Process::run("node \"{$scriptPath}\" \"{$slug}\"");

        if ($result->successful()) {
            return $result->output();
        }

        // Graceful fallback: empty box so the page still renders
        return '<svg viewBox="0 0 88 98" width="100%"><rect x="0" y="0" width="88" height="98" fill="none" stroke="#e2e8f0" stroke-width="1"/><text x="44" y="52" text-anchor="middle" font-size="8" fill="#8896a4">' . e($slug) . '</text></svg>';
    }
}
