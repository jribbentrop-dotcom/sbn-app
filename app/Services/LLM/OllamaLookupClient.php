<?php

namespace App\Services\LLM;

use OpenAI;

/**
 * Ollama client using its OpenAI-compatible API.
 * Default endpoint is http://localhost:11434/v1
 */
class OllamaLookupClient implements LookupClient
{
    protected \OpenAI\Contracts\ClientContract $client;

    public function __construct(
        protected string $model = 'llama3',
        protected string $baseUrl = 'http://localhost:11434/v1',
        ?\OpenAI\Contracts\ClientContract $client = null
    ) {
        // Create a custom Guzzle client with a 120s timeout
        $httpClient = new \GuzzleHttp\Client([
            'timeout' => 120,
            'connect_timeout' => 10,
        ]);

        $this->client = $client ?? OpenAI::factory()
            ->withBaseUri($this->baseUrl)
            ->withApiKey('ollama')
            ->withHttpClient($httpClient)
            ->make();
    }

    public function complete(string $systemPrompt, string $userPrompt, array $jsonSchema, array $opts = []): array
    {
        // For local models, we often need to be more explicit about JSON
        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt . "\n\nYou MUST return a JSON object matching this schema: " . json_encode($jsonSchema)],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'response_format' => ['type' => 'json_object'],
        ];

        try {
            $response = $this->client->chat()->create($payload);
        } catch (\Exception $e) {
            throw new LookupClientException('Ollama API Error: ' . $e->getMessage());
        }

        $text = $response->choices[0]->message->content ?? '{}';

        // Strip markdown backticks if the model wraps the JSON
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $text, $matches)) {
            $text = $matches[1];
        }

        $parsed = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            \Illuminate\Support\Facades\Log::error("[OllamaLookupClient] JSON Parse Failed. Raw text: " . $text);
            // Fallback for models that might not support response_format well
            throw new LookupClientException('Failed to parse JSON response from Ollama. Check logs for full output.');
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
