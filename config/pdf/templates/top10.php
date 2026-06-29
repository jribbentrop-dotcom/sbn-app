<?php
return [
    'key'   => 'top10',
    'label' => 'Top10 Chord Book (rich)',
    'blade' => 'admin.pdf.top10',
    'fields' => [
        // ── Cover ──
        ['type' => 'section', 'label' => 'Cover Page', 'key' => 'cover'],
        ['name' => 'eyebrow',  'type' => 'text',     'label' => 'Cover eyebrow'],
        ['name' => 'title',    'type' => 'text',     'label' => 'Title', 'multiline' => true],  // \n → <br>
        ['name' => 'subtitle', 'type' => 'text',     'label' => 'Subtitle'],
        ['name' => 'hook',     'type' => 'textarea', 'label' => 'Cover hook line'],
        ['name' => 'facts',    'type' => 'textarea', 'label' => 'Cover facts (one per line)'],

        // ── Theory page ──
        ['type' => 'section', 'label' => 'Theory Page', 'key' => 'theory'],
        ['name' => 'theory_title', 'type' => 'text',     'label' => 'Theory page title'],
        ['name' => 'theory_html',  'type' => 'richtext', 'label' => 'Theory page prose'],

        // ── 10 chord items ──
        ['type' => 'section', 'label' => 'Chord Pages', 'key' => 'chords'],
        ['name' => 'chords', 'type' => 'repeater', 'label' => 'Chord pages', 'item' => [
            ['name' => 'title',         'type' => 'text',        'label' => 'Item title (e.g. The Major 6/9 Chord)'],
            ['name' => 'display_chord', 'type' => 'text',        'label' => 'Display chord (plain text, e.g. Db6(9)/Ab)'],
            ['name' => 'slug',          'type' => 'chord-slug',  'label' => 'Diagram voicing slug'],
            ['name' => 'lede',          'type' => 'textarea',    'label' => 'Lede (italic intro line)'],
            // NOTE: body is TEXTAREA, not richtext — the editor repeater does NOT render richtext
            // sub-fields. Author HTML directly; Blade emits {!! !!}.
            ['name' => 'body',          'type' => 'textarea',    'label' => 'Body prose (HTML ok)'],
            ['name' => 'voicing_pill',  'type' => 'text',        'label' => 'Voicing pill (e.g. Shell Voicing)'],
            // pills: comma-separated "label:kind" — kind ∈ root|third|fifth|seventh|ext
            ['name' => 'intervals',     'type' => 'text',        'label' => 'Interval pills (e.g. "5th:fifth, 3rd:third, 6th:ext, 9th:ext")'],
            ['name' => 'listen',        'type' => 'textarea',    'label' => 'Listen note (HTML ok)'],
            ['name' => 'try_this',      'type' => 'textarea',    'label' => 'Try this note (HTML ok)'],
            ['name' => 'related',       'type' => 'textarea',    'label' => 'Related note (HTML ok)'],
            // practice pattern:
            ['name' => 'practice_label', 'type' => 'text',       'label' => 'Practice step-2 label (HTML ok, e.g. "Apply it: X → Y")'],
            ['name' => 'practice_meta',  'type' => 'text',       'label' => 'Practice step-2 meta line'],
            ['name' => 'rhythm_slug',    'type' => 'rhythm-slug','label' => 'Rhythm (step-1 grid)'],
            ['name' => 'rhythm_meta',    'type' => 'text',       'label' => 'Rhythm meta (e.g. "Gilberto Rhythm · 2/4 · sixteenth-note grid")'],
            // Practice TAB source: usually the `top10` leadsheet, but items 6 & 7 use other leadsheets.
            ['name' => 'practice_tab_slug', 'type' => 'song-slug', 'label' => 'Practice TAB leadsheet (default: top10)'],
            ['name' => 'tab_bars',           'type' => 'range',   'label' => 'Practice TAB bars (from–to, 1-indexed)', 'base' => 1],
            ['name' => 'tab_bars_per_row',   'type' => 'number',  'label' => 'Practice bars per row', 'default' => 4],
        ]],

        // ── 7 song examples ──
        ['type' => 'section', 'label' => 'Song Examples', 'key' => 'songs'],
        ['name' => 'songs', 'type' => 'repeater', 'label' => 'Song examples', 'item' => [
            ['name' => 'title',        'type' => 'text',     'label' => 'Song title'],
            ['name' => 'sub',          'type' => 'text',     'label' => 'Sub line (HTML ok)'],
            ['name' => 'eyebrow',      'type' => 'text',     'label' => 'Eyebrow (e.g. "Repertoire Example · 1 of 7")'],
            // legend chips: one per line, "NN PlainChordName" e.g. "01 Db6(9)/Ab"
            ['name' => 'legend',       'type' => 'textarea', 'label' => 'Legend chips (one per line: "NN ChordName")'],
            ['name' => 'note',         'type' => 'textarea', 'label' => 'Callout note (HTML ok)'],
            ['name' => 'slug',         'type' => 'song-slug','label' => 'Leadsheet slug'],
            ['name' => 'bars',         'type' => 'range',    'label' => 'Bars (from–to, 1-indexed inclusive)', 'base' => 1],
            ['name' => 'bars_per_row', 'type' => 'number',   'label' => 'Bars per row', 'default' => 4],
        ]],
    ],
];
