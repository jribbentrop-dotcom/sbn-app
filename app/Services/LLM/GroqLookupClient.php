<?php

namespace App\Services\LLM;

use OpenAI;

class GroqLookupClient implements LookupClient
{
    protected \OpenAI\Contracts\ClientContract $client;

    public function __construct(
        protected string $apiKey,
        protected string $model = 'llama-3.3-70b-versatile',
        protected string $baseUrl = 'https://api.groq.com/openai/v1',
        ?\OpenAI\Contracts\ClientContract $client = null
    ) {
        $this->client = $client ?? OpenAI::factory()
            ->withBaseUri($this->baseUrl)
            ->withApiKey($this->apiKey)
            ->make();
    }

    public function complete(string $systemPrompt, string $userPrompt, array $jsonSchema, array $opts = []): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'response_format' => ['type' => 'json_object'],
        ];

        $payload['messages'][0]['content'] .= "\n\nYou MUST return JSON matching this schema: " . json_encode($jsonSchema);

        try {
            $response = $this->client->chat()->create($payload);
        } catch (\Exception $e) {
            throw new LookupClientException('Groq API Error: ' . $e->getMessage());
        }

        $text = $response->choices[0]->message->content ?? '{}';
        
        // Strip markdown backticks if the model wraps the JSON
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $text, $matches)) {
            $text = $matches[1];
        }

        $parsed = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new LookupClientException('Failed to parse JSON response from Groq');
        }

        $usage = [
            'input_tokens' => $response->usage->promptTokens ?? 0,
            'output_tokens' => $response->usage->completionTokens ?? 0,
            'search_count' => 0,
        ];

        return [
            'data' => $parsed,
            'citations' => [],
            'usage' => $usage,
            'model' => $this->model,
        ];
    }
}
