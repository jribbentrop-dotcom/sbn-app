<?php

return [
    'key'   => 'chord-book',
    'label' => 'Chord Book (Top10)',
    'blade' => 'admin.pdf.chord-book',
    'fields' => [
        ['name' => 'title',       'type' => 'text',     'label' => 'Title', 'multiline' => true],
        ['name' => 'subtitle',    'type' => 'text',     'label' => 'Subtitle'],
        ['name' => 'series',      'type' => 'text',     'label' => 'Series / eyebrow'],
        ['name' => 'description', 'type' => 'textarea', 'label' => 'Cover description'],
        ['name' => 'intro_html',  'type' => 'richtext', 'label' => 'Intro'],

        ['name' => 'chords', 'type' => 'repeater', 'label' => 'Chord pages',
         'item' => [
            ['name' => 'slug',        'type' => 'chord-slug', 'label' => 'Voicing'],
            ['name' => 'description', 'type' => 'textarea',   'label' => 'Description'],
         ]],

        ['name' => 'rhythms', 'type' => 'rhythm-slug', 'label' => 'Rhythm patterns', 'multiple' => true],

        ['name' => 'songs', 'type' => 'repeater', 'label' => 'Song examples',
         'item' => [
            ['name' => 'slug',       'type' => 'song-slug', 'label' => 'Song'],
            ['name' => 'label',      'type' => 'text',      'label' => 'Display label'],
            ['name' => 'measures',   'type' => 'range',     'label' => 'Bars (from–to)', 'base' => 0],
            ['name' => 'barsPerRow', 'type' => 'number',    'label' => 'Bars per row', 'default' => 4],
         ]],
    ],
];
