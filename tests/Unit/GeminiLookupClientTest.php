<?php

namespace Tests\Unit;

use App\Services\LLM\GeminiLookupClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiLookupClientTest extends TestCase
{
    public function test_it_formats_request_and_parses_response_correctly()
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => '{"title":"Test"}']
                            ]
                        ],
                        'groundingMetadata' => [
                            'groundingChunks' => [
                                [
                                    'web' => [
                                        'uri' => 'https://example.com',
                                        'title' => 'Example',
                                    ]
                                ]
                            ]
                        ]
                    ]
                ],
                'usageMetadata' => [
                    'promptTokenCount' => 10,
                    'candidatesTokenCount' => 5,
                ]
            ], 200)
        ]);

        $client = new GeminiLookupClient('fake-key');
        
        $schema = ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]];
        $result = $client->complete('sys prompt', 'user prompt', $schema, ['useWebSearch' => true]);

        $this->assertEquals(['title' => 'Test'], $result['data']);
        $this->assertCount(1, $result['citations']);
        $this->assertEquals('Example', $result['citations'][0]['title']);
        $this->assertEquals('https://example.com', $result['citations'][0]['url']);
        $this->assertEquals(10, $result['usage']['input_tokens']);
        $this->assertEquals(1, $result['usage']['search_count']);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            $data = $request->data();
            return isset($data['tools'][0]['google_search']) &&
                   str_starts_with($data['system_instruction']['parts'][0]['text'], 'sys prompt') &&
                   $data['contents'][0]['parts'][0]['text'] === 'user prompt';
        });
    }
}
