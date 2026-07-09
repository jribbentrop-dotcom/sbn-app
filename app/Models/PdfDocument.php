<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdfDocument extends Model
{
    protected $table = 'sbn_pdf_documents';

    protected $fillable = ['slug', 'template_key', 'title', 'content', 'pages', 'status'];

    protected $casts = ['content' => 'array', 'pages' => 'array'];

    // ── Page-type registry ────────────────────────────────────────────────────────
    // Each entry: label, partial view name, and the fields it contributes to the editor.

    public static function pageRegistry(): array
    {
        return [
            'cover' => [
                'label'   => 'Cover Page',
                'partial' => 'admin.pdf.partials._cover',
                'fields'  => [
                    ['name' => 'eyebrow',  'type' => 'text',     'label' => 'Cover eyebrow'],
                    ['name' => 'title',    'type' => 'text',     'label' => 'Title', 'multiline' => true],
                    ['name' => 'subtitle', 'type' => 'text',     'label' => 'Subtitle'],
                    ['name' => 'hook',     'type' => 'textarea', 'label' => 'Cover hook line'],
                    ['name' => 'facts',    'type' => 'textarea', 'label' => 'Cover facts (one per line)'],
                ],
            ],
            'theory' => [
                'label'   => 'Theory Page',
                'partial' => 'admin.pdf.partials._theory',
                'fields'  => [
                    ['name' => 'theory_title', 'type' => 'text',     'label' => 'Theory page title'],
                    ['name' => 'theory_html',  'type' => 'richtext', 'label' => 'Theory page prose'],
                ],
            ],
            'chords' => [
                'label'   => 'Chord Pages',
                'partial' => 'admin.pdf.partials._chord-item',
                'fields'  => [
                    ['name' => 'chords', 'type' => 'repeater', 'label' => 'Chord pages', 'item' => [
                        ['name' => 'title',            'type' => 'text',       'label' => 'Item title'],
                        ['name' => 'display_chord',    'type' => 'text',       'label' => 'Display chord (e.g. Db6(9)/Ab)'],
                        ['name' => 'slug',             'type' => 'chord-slug', 'label' => 'Diagram voicing slug'],
                        ['name' => 'lede',             'type' => 'textarea',   'label' => 'Lede (italic intro line)'],
                        ['name' => 'body',             'type' => 'textarea',   'label' => 'Body prose (HTML ok)'],
                        ['name' => 'voicing_pill',     'type' => 'text',       'label' => 'Voicing pill'],
                        ['name' => 'intervals',        'type' => 'text',       'label' => 'Interval pills (e.g. "5th:fifth, 3rd:third")'],
                        ['name' => 'listen',           'type' => 'textarea',   'label' => 'Listen note (HTML ok)'],
                        ['name' => 'try_this',         'type' => 'textarea',   'label' => 'Try this note (HTML ok)'],
                        ['name' => 'related',          'type' => 'textarea',   'label' => 'Related note (HTML ok)'],
                        ['name' => 'practice_label',   'type' => 'text',       'label' => 'Practice step-2 label'],
                        ['name' => 'practice_meta',    'type' => 'text',       'label' => 'Practice step-2 meta line'],
                        ['name' => 'rhythm_slug',      'type' => 'rhythm-slug','label' => 'Rhythm (step-1 grid)'],
                        ['name' => 'rhythm_meta',      'type' => 'text',       'label' => 'Rhythm meta'],
                        ['name' => 'practice_tab_slug','type' => 'tab-source-slug', 'label' => 'Practice TAB source (leadsheet or exercise)'],
                        ['name' => 'tab_bars',         'type' => 'range',      'label' => 'Practice TAB bars (1-indexed)', 'base' => 1],
                        ['name' => 'tab_bars_per_row', 'type' => 'number',     'label' => 'Bars per row', 'default' => 4],
                    ]],
                ],
            ],
            'songs' => [
                'label'   => 'Song Examples',
                'partial' => 'admin.pdf.partials._song-example',
                'fields'  => [
                    ['name' => 'songs', 'type' => 'repeater', 'label' => 'Song examples', 'item' => [
                        ['name' => 'title',        'type' => 'text',     'label' => 'Song title'],
                        ['name' => 'sub',          'type' => 'text',     'label' => 'Sub line (HTML ok)'],
                        ['name' => 'eyebrow',      'type' => 'text',     'label' => 'Eyebrow'],
                        ['name' => 'legend',       'type' => 'textarea', 'label' => 'Legend chips (one per line: "NN ChordName")'],
                        ['name' => 'note',         'type' => 'textarea', 'label' => 'Callout note (HTML ok)'],
                        ['name' => 'slug',         'type' => 'song-slug','label' => 'Leadsheet slug'],
                        ['name' => 'bars',         'type' => 'range',    'label' => 'Bars (from–to, 1-indexed)', 'base' => 1],
                        ['name' => 'bars_per_row', 'type' => 'number',   'label' => 'Bars per row', 'default' => 4],
                    ]],
                ],
            ],
        ];
    }

    // Build the editor field list from the document's pages array.
    // Falls back to the legacy template PHP config if pages is null (backwards compat).
    public function editorSchema(): array
    {
        if (!empty($this->pages)) {
            $registry = self::pageRegistry();
            $fields   = [];
            foreach ($this->pages as $pageType) {
                if (!isset($registry[$pageType])) continue;
                $entry    = $registry[$pageType];
                $fields[] = ['type' => 'section', 'label' => $entry['label'], 'key' => $pageType];
                foreach ($entry['fields'] as $f) {
                    $fields[] = $f;
                }
            }
            return ['fields' => $fields];
        }

        // Legacy: load from config/pdf/templates/{key}.php
        return require config_path("pdf/templates/{$this->template_key}.php");
    }

    public static function templateKeys(): array
    {
        return collect(glob(config_path('pdf/templates/*.php')))
            ->map(fn ($p) => basename($p, '.php'))->all();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
