<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChordDiagram;
use App\Models\Leadsheet;
use App\Models\RhythmPattern;
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
        $data = $this->buildChordBookData($slug);
        return view('admin.pdf.chord-book', $data);
    }

    /**
     * Download: render via Browsershot to a PDF and stream it.
     * /admin/pdf/download/{slug}
     */
    public function download(string $slug)
    {
        $data  = $this->buildChordBookData($slug);
        $html  = view('admin.pdf.chord-book', $data)->render();
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

    /**
     * Build the view-data array for a chord-book PDF.
     *
     * For v1: a "slug" maps to a group of chords via config.
     * The config file (config/pdf/{slug}.php) returns an array of
     * sbn_chord_diagrams slugs in display order.
     *
     * Falls back to rendering a single chord if no config exists.
     */
    private function buildChordBookData(string $slug): array
    {
        $configFile = config_path("pdf/{$slug}.php");

        if (file_exists($configFile)) {
            $config      = require $configFile;
            $chordSlugs  = $config['chords'] ?? [];
            $title       = $config['title']  ?? $slug;
            $subtitle    = $config['subtitle'] ?? null;
            $series      = $config['series']   ?? 'SBN Teaching Hub · Top 10';
            $coverDescription = $config['description'] ?? null;
            $introHtml   = $config['intro_html'] ?? null;
        } else {
            // Single-chord fallback
            $config      = [];
            $chordSlugs  = [$slug];
            $title       = $slug;
            $subtitle    = null;
            $series      = 'SBN Teaching Hub';
            $coverDescription = null;
            $introHtml   = null;
        }

        // Optional rhythm slugs
        $rhythmSlugs = $config['rhythms'] ?? [];
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

        // notation_svg config: keyed by chord slug OR positional (0-based index).
        // Each entry: ['file' => 'svgexport/foo-2.svg', 'system' => 1]
        $notationMap = $config['notation_svg'] ?? [];

        $chords = [];
        foreach ($chordSlugs as $idx => $chordSlug) {
            $row = ChordDiagram::where('slug', $chordSlug)->first();
            if (! $row) continue;

            $svg = $this->renderDiagramSvg($chordSlug);

            // Resolve notation SVG: try slug key first, then positional index
            $notationCfg = $notationMap[$chordSlug] ?? $notationMap[$idx] ?? null;
            $notationSvg = null;
            if ($notationCfg && ! empty($notationCfg['file'])) {
                $notationSvg = self::extractTabSvg(
                    $notationCfg['file'],
                    $notationCfg['system'] ?? 1
                );
            }

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
                'description'      => $row->description,
                'svg'              => $svg,
                'notation_svg'     => $notationSvg,
            ];
        }

        // Optional song examples (leadsheet slugs with optional measure range)
        // Config entry: ['slug' => 'night-and-day', 'measures' => [0, 7], 'label' => 'Night and Day']
        $songExamples = [];
        foreach ($config['songs'] ?? [] as $songCfg) {
            $lsSlug = $songCfg['slug'] ?? null;
            if (! $lsSlug) continue;

            $ls = Leadsheet::where('slug', $lsSlug)->first();
            if (! $ls || ! $ls->tab_xml) continue;

            $parser  = new TabXmlParser();
            $tabData = $parser->parse($ls->tab_xml);

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
