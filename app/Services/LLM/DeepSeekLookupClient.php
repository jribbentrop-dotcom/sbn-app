<?php

namespace App\Services\LLM;

use OpenAI;

class DeepSeekLookupClient implements LookupClient
{
    protected \OpenAI\Contracts\ClientContract $client;

    public function __construct(
        protected string $apiKey,
        protected string $model = 'deepseek-chat',
        protected string $baseUrl = 'https://api.deepseek.com/v1',
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
            $message = $e->getMessage();
            // If it's an OpenAI API exception, it might have more details in the message
            throw new LookupClientException('DeepSeek API Error: ' . $message);
        }

        $text = $response->choices[0]->message->content ?? '{}';

        // Strip markdown backticks if the model wraps the JSON
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $text, $matches)) {
            $text = $matches[1];
        }

        $parsed = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new LookupClientException('Failed to parse JSON response from DeepSeek');
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
