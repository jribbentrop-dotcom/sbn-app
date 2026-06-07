<?php

namespace Tests\Feature;

use App\Models\Leadsheet;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LibraryRelatedSongStyleSlugTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sqlite.database' => database_path('sbn.db')]);
        DB::reconnect('sqlite');
    }

    public function test_chord_show_related_songs_use_canonical_style_slugs(): void
    {
        $row = DB::table('sbn_leadsheets as l')
            ->join('sbn_voicing_usage as u', 'l.id', '=', 'u.leadsheet_id')
            ->select('l.id', 'l.slug', 'l.genre', 'l.rhythm', 'u.chord_diagram_id')
            ->where('l.status', 'publish')
            ->whereNotNull('l.genre')
            ->where('l.genre', '!=', '')
            ->whereColumn('l.genre', '!=', 'l.rhythm')
            ->orderBy('l.id')
            ->first();

        if (!$row) {
            $this->markTestSkipped('No published leadsheet with a genre/rhythm mismatch and chord usage was found.');
        }

        $expectedStyleSlug = Leadsheet::resolveStyleSlug($row->genre, $row->rhythm);
        $songSlug = $row->slug;

        $this->get('/library/chords/' . DB::table('sbn_chord_diagrams')->where('id', $row->chord_diagram_id)->value('slug'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Library/Chords/Show')
                ->where('songs', function ($songs) use ($songSlug, $expectedStyleSlug) {
                    foreach ($songs as $song) {
                        if (($song['slug'] ?? null) !== $songSlug) {
                            continue;
                        }

                        return ($song['styleSlug'] ?? null) === $expectedStyleSlug;
                    }

                    return false;
                })
            );
    }

    public function test_rhythm_show_related_songs_use_canonical_style_slugs(): void
    {
        $row = DB::table('sbn_leadsheets as l')
            ->join('sbn_rhythm_patterns as r', 'l.rhythm', '=', 'r.slug')
            ->select('l.slug', 'l.genre', 'l.rhythm')
            ->where('l.status', 'publish')
            ->whereNotNull('l.genre')
            ->where('l.genre', '!=', '')
            ->whereColumn('l.genre', '!=', 'l.rhythm')
            ->orderBy('l.id')
            ->first();

        if (!$row) {
            $this->markTestSkipped('No published leadsheet with a genre/rhythm mismatch was found.');
        }

        $expectedStyleSlug = Leadsheet::resolveStyleSlug($row->genre, $row->rhythm);

        $this->get('/library/rhythms/' . $row->rhythm)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Library/Rhythms/Show')
                ->where('songs', function ($songs) use ($row, $expectedStyleSlug) {
                    foreach ($songs as $song) {
                        if (($song['slug'] ?? null) !== $row->slug) {
                            continue;
                        }

                        return ($song['styleSlug'] ?? null) === $expectedStyleSlug;
                    }

                    return false;
                })
            );
    }

    public function test_progression_show_related_songs_use_canonical_style_slugs(): void
    {
        $row = DB::table('sbn_leadsheets as l')
            ->join('sbn_progression_occurrences as o', 'l.id', '=', 'o.leadsheet_id')
            ->select('l.slug', 'l.genre', 'l.rhythm', 'o.progression_id')
            ->where('l.status', 'publish')
            ->whereNotNull('l.genre')
            ->where('l.genre', '!=', '')
            ->whereColumn('l.genre', '!=', 'l.rhythm')
            ->orderBy('l.id')
            ->first();

        if (!$row) {
            $this->markTestSkipped('No published leadsheet with a genre/rhythm mismatch and progression usage was found.');
        }

        $expectedStyleSlug = Leadsheet::resolveStyleSlug($row->genre, $row->rhythm);

        $this->get('/library/progressions/' . DB::table('sbn_chord_progressions')->where('id', $row->progression_id)->value('slug'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Library/Progressions/Show')
                ->where('songs', function ($songs) use ($row, $expectedStyleSlug) {
                    foreach ($songs as $song) {
                        if (($song['slug'] ?? null) !== $row->slug) {
                            continue;
                        }

                        return ($song['styleSlug'] ?? null) === $expectedStyleSlug;
                    }

                    return false;
                })
            );
    }
}
