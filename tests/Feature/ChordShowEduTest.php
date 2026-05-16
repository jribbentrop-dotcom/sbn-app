<?php

namespace Tests\Feature;

use App\Models\ChordDiagram;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the Task 3 (8.1) wiring: ChordLibraryController::show resolves the
 * chord's quality topic through EduContentService and passes it to the
 * Library/Chords/Show Inertia page as the `qualityTopic` prop.
 */
class ChordShowEduTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // phpunit.xml points the sqlite connection at :memory:; this controller
        // path needs the real catalogue. Re-point to the on-disk DB, matching
        // LeadsheetLookupTest's pattern for DB-backed feature tests.
        config(['database.connections.sqlite.database' => database_path('sbn.db')]);
        DB::reconnect('sqlite');
    }

    public function test_show_passes_the_quality_topic_in_the_inertia_payload(): void
    {
        $chord = ChordDiagram::query()->firstOrFail();

        $this->get("/library/chords/{$chord->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Library/Chords/Show')
                ->has('qualityTopic', fn ($topic) => $topic
                    ->where('slug', $chord->quality)
                    ->whereNot('description', null)
                    ->whereNot('usage', null)
                    ->where('has_widgets', false)   // no quality body has a widget yet
                    ->etc()
                )
            );
    }
}
