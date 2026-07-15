<?php

namespace Tests\Feature;

use App\Models\Leadsheet;
use App\Models\User;
use Tests\TestCase;

class LeadsheetProgressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sqlite.database' => database_path('sbn.db')]);
        \Illuminate\Support\Facades\DB::reconnect('sqlite');
    }

    protected function tearDown(): void
    {

        Leadsheet::where('title', 'Test Progression Leadsheet')->delete();
        parent::tearDown();
    }

    public function test_can_create_leadsheet_from_sequence()
    {
        $user = User::first() ?? User::factory()->create();

        $response = $this->actingAs($user)->post('/admin/leadsheets/create-from-sequence', [
            'title'          => 'Test Progression Leadsheet',
            'composer'       => 'Test Composer',
            'song_key'       => 'C',
            'tempo'          => 120,
            'time_signature' => '4/4',
            'bars_per_chord' => 1,
            'source_type'    => 'free',
            'sequence_text'  => 'Am7 Dm7 G7 Cmaj7',
            'build_voicings' => 1,
        ]);

        $response->assertStatus(302);
        
        $leadsheet = Leadsheet::where('title', 'Test Progression Leadsheet')->first();
        $this->assertNotNull($leadsheet);
        $this->assertEquals('C', $leadsheet->song_key);
        
        $jsonData = json_decode($leadsheet->json_data, true);
        $this->assertIsArray($jsonData);
        $this->assertNotEmpty($jsonData['sections']);
    }

    public function test_can_resolve_numerals_ajax()
    {
        $user = User::first() ?? User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/admin/progressions/resolve-numerals', [
            'key'      => 'C',
            'sequence' => 'IIm7 V7 Imaj7',
        ]);

        $response->assertStatus(200);
        $data = $response->json();
        
        $this->assertTrue($data['success']);
        $this->assertEquals(['Dm7', 'G7', 'Cmaj7'], $data['chords']);
    }

    public function test_can_create_leadsheet_with_rhythm_pattern()

    {
        $user = User::first() ?? User::factory()->create();

        // Ensure a rhythm pattern exists
        $rhythm = \App\Models\RhythmPattern::firstOrCreate(
            ['slug' => 'test-bossa'],
            [
                'name' => 'Test Bossa',
                'time_signature' => '4/4',
                'grid_type' => 'eighth',
                'rhythm_pattern' => 'X.x.X.x.',
                'thumb_pattern' => 'X.......',
            ]
        );

        $response = $this->actingAs($user)->post('/admin/leadsheets/create-from-sequence', [
            'title'          => 'Test Progression Leadsheet',
            'composer'       => 'Test Composer',
            'song_key'       => 'C',
            'tempo'          => 120,
            'time_signature' => '4/4',
            'bars_per_chord' => 1,
            'source_type'    => 'free',
            'sequence_text'  => 'Am7 Dm7 G7 Cmaj7',
            'build_voicings' => 1,
            'rhythm'         => 'test-bossa',
        ]);

        $response->assertStatus(302);
        
        $leadsheet = Leadsheet::where('title', 'Test Progression Leadsheet')->first();
        $this->assertNotNull($leadsheet);
        $this->assertEquals('test-bossa', $leadsheet->rhythm);

        $jsonData = json_decode($leadsheet->json_data, true);
        $this->assertNotEmpty($jsonData['melody']);
        $this->assertEquals('Test Bossa', $jsonData['rhythmPattern']['name'] ?? '');
    }

    public function test_can_create_leadsheet_from_saved_progression()
    {
        $user = User::first() ?? User::factory()->create();

        \Illuminate\Support\Facades\DB::table('sbn_chord_progressions')->updateOrInsert(
            ['name' => 'Test Jazz Progression'],
            [
                'slug' => 'test-jazz-progression',
                'category' => 'jazz',
                'numerals' => 'IIm7, V7, Imaj7',
                'tonality' => 'major',
            ]
        );
        $progression = \App\Models\ChordProgression::where('name', 'Test Jazz Progression')->first();




        $response = $this->actingAs($user)->post('/admin/leadsheets/create-from-sequence', [
            'title'          => 'Test Progression Leadsheet',
            'composer'       => 'Test Composer',
            'song_key'       => 'Bb',
            'tempo'          => 120,
            'time_signature' => '4/4',
            'bars_per_chord' => 1,
            'source_type'    => 'progression',
            'progression_id' => $progression->id,
            'build_voicings' => 1,
        ]);

        $response->assertStatus(302);

        $leadsheet = Leadsheet::where('title', 'Test Progression Leadsheet')->first();
        $this->assertNotNull($leadsheet);
        
        $jsonData = json_decode($leadsheet->json_data, true);
        $this->assertEquals('Cm7', $jsonData['sections'][0]['measures'][0]['chords'][0]['name']);
        $this->assertEquals('F7', $jsonData['sections'][0]['measures'][1]['chords'][0]['name']);
        $this->assertEquals('Bbmaj7', $jsonData['sections'][0]['measures'][2]['chords'][0]['name']);
    }
}




