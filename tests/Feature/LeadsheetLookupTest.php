<?php

namespace Tests\Feature;

use App\Models\Leadsheet;
use App\Models\LookupCache;
use App\Services\LLM\FakeLookupClient;
use App\Services\LLM\LookupClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

use App\Models\User;

class LeadsheetLookupTest extends TestCase
{
    // use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sqlite.database' => database_path('sbn.db')]);
        \Illuminate\Support\Facades\DB::reconnect('sqlite');
    }

    protected function tearDown(): void
    {
        Leadsheet::whereIn('title', ['Alone Together', 'Wave'])->delete();
        LookupCache::whereIn('title', ['Alone Together', 'Wave'])->delete();
        parent::tearDown();
    }

    public function test_it_creates_leadsheet_from_lookup()
    {
        $user = User::first() ?? User::factory()->create();

        $fakeClient = new FakeLookupClient();
        $fakeClient->responses[] = [
            'data' => [
                'title' => 'Alone Together',
                'key' => 'Dm',
                'timeSignature' => '4/4',
                'tempo' => 120,
                'source_note' => 'Fake Real Book',
                'confidence' => 'high',
                'sections' => [
                    [
                        'name' => 'A',
                        'bars' => [
                            ['chords' => [['label' => 'Dm7b5', 'beats' => 4]]],
                        ]
                    ]
                ]
            ],
            'citations' => [],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 10, 'search_count' => 0],
            'model' => 'fake',
        ];

        $this->app->instance(LookupClient::class, $fakeClient);

        // Make the POST request
        $response = $this->actingAs($user)->post(route('admin.leadsheets.create-from-lookup'), [
            'title' => 'Alone Together',
            'artist_hint' => 'Fake Real Book',
            'version' => 'most_common',
            'build_voicings' => false,
        ]);

        $leadsheet = Leadsheet::where('title', 'Alone Together')->first();
        
        $response->assertRedirect(route('admin.leadsheets.edit', $leadsheet));
        $response->assertSessionHas('success');
        $response->assertSessionHas('lookup_confidence', 'high');

        $this->assertNotNull($leadsheet);
        $this->assertEquals('Alone Together', $leadsheet->title);
        $this->assertEquals('Dm', $leadsheet->song_key);
        $this->assertEquals('4/4', $leadsheet->time_signature);
        $this->assertEquals(120, $leadsheet->tempo);
        $this->assertEquals('Fake Real Book', $leadsheet->description);
        $this->assertStringContainsString('[A label="A"]', $leadsheet->shortcode_content);
        $this->assertStringContainsString('| Dm7b5 |', $leadsheet->shortcode_content);
        
        // Assert it cached
        $cache = LookupCache::where('title', 'Alone Together')->first();
        $this->assertNotNull($cache);
        $this->assertEquals('Alone Together', $cache->title);
        $this->assertEquals('Dm', $cache->analysis['key']);
    }

    public function test_it_handles_assistant_mode_lookup()
    {
        $user = User::first() ?? User::factory()->create();

        $fakeClient = new FakeLookupClient();
        $fakeClient->responses[] = [
            'data' => [
                'title' => 'Wave v99',
                'key' => 'D',
                'timeSignature' => '4/4',
                'tempo' => 140,
                'source_note' => 'Jobim Analysis',
                'confidence' => 'high',
                'sections' => [
                    [
                        'name' => 'A',
                        'bars' => [
                            ['chords' => [['label' => 'Dmaj7', 'beats' => 4]]],
                        ]
                    ]
                ],
                'research' => [
                    'mode' => 'assistant',
                    'canonical_changes_source' => 'Real Book',
                    'notable_versions' => [
                        [
                            'artist' => 'Antonio Carlos Jobim',
                            'recording' => 'Wave',
                            'year' => 1967,
                            'differences' => 'Original arrangement',
                            'source_type' => 'general_knowledge'
                        ]
                    ],
                    'suggested_videos' => [
                        [
                            'url' => 'https://www.youtube.com/watch?v=123',
                            'title' => 'Wave - Jobim',
                            'channel' => 'JobimOfficial',
                            'rationale' => 'Original',
                            'recording_match' => 'exact'
                        ]
                    ]
                ]
            ],
            'citations' => [],
            'usage' => ['input_tokens' => 20, 'output_tokens' => 50, 'search_count' => 1],
            'model' => 'fake',
        ];

        $this->app->instance(LookupClient::class, $fakeClient);

        // Make the POST request with build_voicings = false to keep it simple
        $response = $this->actingAs($user)->post(route('admin.leadsheets.create-from-lookup'), [
            'title' => 'Wave v99',
            'mode' => 'assistant',
            'build_voicings' => false,
        ]);

        $leadsheet = Leadsheet::where('title', 'Wave v99')->first();
        $this->assertNotNull($leadsheet);
        
        $jsonData = json_decode($leadsheet->json_data, true);
        $this->assertArrayHasKey('research', $jsonData);
        $this->assertEquals('assistant', $jsonData['research']['mode']);
        $this->assertEquals('Antonio Carlos Jobim', $jsonData['research']['notable_versions'][0]['artist']);
    }
}
