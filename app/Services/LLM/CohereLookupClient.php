<?php

namespace App\Services\LLM;

use Illuminate\Support\Facades\Http;

class CohereLookupClient implements LookupClient
{
    public function __construct(
        protected string $apiKey,
        protected string $model = 'command-r-plus-08-2024' // Default to their best RAG model
    ) {}

    public function complete(string $systemPrompt, string $userPrompt, array $jsonSchema, array $opts = []): array
    {
        $url = 'https://api.cohere.com/v1/chat';

        $payload = [
            'model' => $this->model,
            'message' => $userPrompt,
            'preamble' => $systemPrompt,
            'response_format' => [
                'type' => 'json_object',
                'schema' => $jsonSchema,
            ]
        ];

        if (!empty($opts['useWebSearch'])) {
            $payload['connectors'] = [
                ['id' => 'web-search']
            ];
            
            // Re-inject the schema instruction into the preamble just to be safe
            $payload['preamble'] .= "\n\nIMPORTANT: You must return the output STRICTLY as a raw JSON object matching the provided schema. Do not wrap in markdown.";
        }

        $timeout = $opts['timeoutSeconds'] ?? 60;
        $maxAttempts = 3;
        $attempt = 0;
        $parsed = null;
        $data = null;

        while ($attempt < $maxAttempts) {
            $attempt++;
            
            $response = Http::withToken($this->apiKey)
                ->acceptJson()
                ->timeout($timeout)
                ->post($url, $payload);

            if (!$response->successful()) {
                if (in_array($response->status(), [429, 503]) && $attempt < $maxAttempts) {
                    $delay = pow(2, $attempt) + rand(0, 1000) / 1000;
                    \Log::warning("[CohereLookupClient] Rate limited or overloaded ({$response->status()}). Retrying in {$delay}s...");
                    sleep((int)$delay);
                    continue;
                }
                
                if (in_array($response->status(), [429, 503])) {
                    throw new LookupClientException('Cohere API is currently overloaded or rate-limited. Please try again in a few moments.');
                }
                
                throw new LookupClientException('Cohere API Error: ' . $response->body());
            }

            $data = $response->json();
            $text = $data['text'] ?? '{}';
            
            \Log::info('[CohereLookupClient] response keys', [
                'mode' => !empty($opts['useWebSearch']) ? 'assistant' : 'quick',
                'has_documents' => !empty($data['documents']),
                'citation_count' => isset($data['citations']) ? count($data['citations']) : 0,
            ]);

            if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $text, $matches)) {
                $text = $matches[1];
            }

            $parsed = json_decode($text, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                break; // Success
            }

            if ($attempt >= $maxAttempts) {
                throw new LookupClientException('Failed to parse JSON response from Cohere. Raw text: ' . substr($text, 0, 100) . '...');
            }
        }

        $formattedCitations = [];
        if (!empty($data['documents'])) {
            // Cohere returns documents that were searched and cited
            foreach ($data['documents'] as $doc) {
                $formattedCitations[] = [
                    'uri' => $doc['url'] ?? '',
                    'title' => $doc['title'] ?? '',
                ];
            }
        }

        return [
            'data' => $parsed,
            'citations' => $formattedCitations,
            'usage' => [
                'input_tokens' => $data['meta']['billed_units']['input_tokens'] ?? 0,
                'output_tokens' => $data['meta']['billed_units']['output_tokens'] ?? 0,
                'search_count' => $data['meta']['billed_units']['search_units'] ?? 0,
            ],
            'model' => $this->model,
        ];
    }
}
