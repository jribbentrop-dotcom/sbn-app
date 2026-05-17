<?php

namespace Tests\Feature;

use App\Models\Leadsheet;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the Task 3 (8.2) wiring: SongLibraryController::viewer and
 * apiViewerData both pass eduRelatedConcepts — a map of concept topics keyed
 * by slug — alongside eduChordQualities.
 */
class EduPanelConceptTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sqlite.database' => database_path('sbn.db')]);
        DB::reconnect('sqlite');
    }

    // ── viewer (Inertia) ──────────────────────────────────────────────────────

    public function test_viewer_payload_contains_edu_related_concepts_key(): void
    {
        $leadsheet = Leadsheet::query()->firstOrFail();

        $this->get("/library/songs/{$leadsheet->slug}/viewer")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Library/Songs/Viewer')
                ->has('eduRelatedConcepts')
            );
    }

    public function test_viewer_edu_related_concepts_contains_no_nulls(): void
    {
        $leadsheet = Leadsheet::query()->firstOrFail();

        $this->get("/library/songs/{$leadsheet->slug}/viewer")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Library/Songs/Viewer')
                ->where('eduRelatedConcepts', function ($concepts) {
                    // Every value in the map must be a non-null array (a resolved topic).
                    foreach ($concepts as $slug => $topic) {
                        if ($topic === null) {
                            return false;
                        }
                    }
                    return true;
                })
            );
    }

    public function test_viewer_edu_related_concepts_only_contains_existing_concept_slugs(): void
    {
        $leadsheet = Leadsheet::query()->firstOrFail();

        $this->get("/library/songs/{$leadsheet->slug}/viewer")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Library/Songs/Viewer')
                ->where('eduRelatedConcepts', function ($concepts) {
                    // The map must only contain slugs that resolve to real concept files —
                    // i.e. the key matches the topic's own slug field.
                    foreach ($concepts as $slug => $topic) {
                        if (($topic['slug'] ?? null) !== $slug) {
                            return false;
                        }
                        if (($topic['type'] ?? null) !== 'concept') {
                            return false;
                        }
                    }
                    return true;
                })
            );
    }

    public function test_viewer_qualities_with_no_related_contribute_nothing_to_the_map(): void
    {
        $leadsheet = Leadsheet::query()->firstOrFail();

        $this->get("/library/songs/{$leadsheet->slug}/viewer")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Library/Songs/Viewer')
                ->where('eduRelatedConcepts', function ($concepts) {
                    // Slugs that exist only in quality files but not as concepts
                    // must not appear in the map (e.g. a bad slug would have been
                    // filtered by ->filter() in buildEduRelatedConcepts).
                    // We verify by checking every key actually has a body_html
                    // field — a sure sign it's a resolved EduTopic, not a null stub.
                    foreach ($concepts as $slug => $topic) {
                        if (! array_key_exists('body_html', $topic)) {
                            return false;
                        }
                    }
                    return true;
                })
            );
    }

    // ── apiViewerData (JSON) ──────────────────────────────────────────────────

    public function test_api_viewer_data_contains_edu_related_concepts(): void
    {
        $leadsheet = Leadsheet::query()->firstOrFail();

        $this->getJson("/api/sbn/songs/{$leadsheet->slug}/viewer-data")
            ->assertOk()
            ->assertJsonStructure(['eduRelatedConcepts']);
    }

    public function test_api_viewer_data_edu_related_concepts_contains_no_nulls(): void
    {
        $leadsheet = Leadsheet::query()->firstOrFail();

        $response = $this->getJson("/api/sbn/songs/{$leadsheet->slug}/viewer-data")
            ->assertOk();

        $concepts = $response->json('eduRelatedConcepts');
        $this->assertIsArray($concepts);
        foreach ($concepts as $slug => $topic) {
            $this->assertNotNull($topic, "Concept slug '{$slug}' resolved to null in apiViewerData");
            $this->assertSame($slug, $topic['slug'] ?? null);
            $this->assertSame('concept', $topic['type'] ?? null);
        }
    }
}
