<?php

namespace App\Console\Commands;

use App\Models\PdfDocument;
use Illuminate\Console\Command;

class ImportPdfConfig extends Command
{
    protected $signature   = 'sbn:import-pdf-config {slug : The config slug (e.g. top10-bossa-nova-chords)}';
    protected $description = 'Import a legacy config/pdf/{slug}.php file into the sbn_pdf_documents table';

    public function handle(): int
    {
        $slug       = $this->argument('slug');
        $configFile = config_path("pdf/{$slug}.php");

        if (! file_exists($configFile)) {
            $this->error("Config file not found: {$configFile}");
            return 1;
        }

        $config = require $configFile;

        // Build chords repeater: zip chords[] with chord_descriptions[slug]
        $chordSlugs   = $config['chords']             ?? [];
        $descriptions = $config['chord_descriptions'] ?? [];

        $chords = array_map(function ($chordSlug) use ($descriptions) {
            return [
                'slug'        => $chordSlug,
                'description' => $descriptions[$chordSlug] ?? '',
            ];
        }, $chordSlugs);

        $content = [
            'title'       => $config['title']       ?? $slug,
            'subtitle'    => $config['subtitle']    ?? '',
            'series'      => $config['series']      ?? '',
            'description' => $config['description'] ?? '',
            'intro_html'  => $config['intro_html']  ?? '',
            'chords'      => $chords,
            'rhythms'     => $config['rhythms']     ?? [],
            'songs'       => $config['songs']       ?? [],
        ];

        $doc = PdfDocument::updateOrCreate(
            ['slug' => $slug],
            [
                'template_key' => 'chord-book',
                'title'        => is_string($content['title'])
                    ? str_replace("\n", ' ', $content['title'])
                    : $slug,
                'content'      => $content,
                'status'       => 'publish',
            ]
        );

        $action = $doc->wasRecentlyCreated ? 'Created' : 'Updated';
        $this->info("{$action} PdfDocument #{$doc->id} (slug: {$doc->slug})");
        $this->line('  Chords:  ' . count($chords));
        $this->line('  Rhythms: ' . count($content['rhythms']));
        $this->line('  Songs:   ' . count($content['songs']));

        return 0;
    }
}
