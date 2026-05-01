<?php

namespace Tests\Unit;

use App\Services\LLM\DeepSeekLookupClient;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;
use Tests\TestCase;

class DeepSeekLookupClientTest extends TestCase
{
    public function test_it_formats_request_and_parses_response_correctly()
    {
        $fakeClient = new ClientFake([
            CreateResponse::fake([
                'choices' => [
                    [
                        'message' => [
                            'content' => '{"title":"Test"}',
                        ],
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 10,
                    'completion_tokens' => 5,
                ],
            ])
        ]);

        $client = new DeepSeekLookupClient('fake-key', 'deepseek-chat', 'base', $fakeClient);
        
        $schema = ['type' => 'object', 'properties' => ['title' => ['type' => 'string']]];
        $result = $client->complete('sys prompt', 'user prompt', $schema, ['useWebSearch' => true]);

        $this->assertEquals(['title' => 'Test'], $result['data']);
        $this->assertCount(0, $result['citations']);
        $this->assertEquals(10, $result['usage']['input_tokens']);
        $this->assertEquals(5, $result['usage']['output_tokens']);
        $this->assertEquals(0, $result['usage']['search_count']);

        $fakeClient->assertSent(\OpenAI\Resources\Chat::class, function ($method, $parameters) {
            return $method === 'create' &&
                   $parameters['model'] === 'deepseek-chat' &&
                   $parameters['response_format']['type'] === 'json_object' &&
                   str_contains($parameters['messages'][0]['content'], 'sys prompt') &&
                   $parameters['messages'][1]['content'] === 'user prompt';
        });
    }
}
