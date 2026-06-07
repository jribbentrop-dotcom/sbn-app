<?php

namespace Tests\Feature;

use App\Models\Leadsheet;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SongLibraryStyleSlugTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sqlite.database' => database_path('sbn.db')]);
        DB::reconnect('sqlite');
    }

    public function test_song_library_index_and_show_use_normalized_style_slugs(): void
    {
        $song = Leadsheet::published()
            ->whereNotNull('genre')
            ->where('genre', '!=', '')
            ->get()
            ->first(fn (Leadsheet $candidate) => $candidate->genre !== $candidate->rhythm);

        if (!$song) {
            $this->markTestSkipped('No published leadsheet with a genre/rhythm mismatch was found in the catalogue.');
        }

        $expectedStyleSlug = Leadsheet::resolveStyleSlug($song->genre, $song->rhythm);

        $this->get('/library/songs')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Library/Songs/Index')
                ->where('songs', function ($songs) use ($song, $expectedStyleSlug) {
                    foreach ($songs as $entry) {
                        if (($entry['slug'] ?? null) !== $song->slug) {
                            continue;
                        }

                        return ($entry['styleSlug'] ?? null) === $expectedStyleSlug;
                    }

                    return false;
                })
            );

        $this->get("/library/songs/{$song->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Library/Songs/Show')
                ->where('song.styleSlug', $expectedStyleSlug)
            );
    }
}
