<?php

namespace App\Services\LLM;

use Illuminate\Support\Facades\Http;

class GeminiLookupClient implements LookupClient
{
    public function __construct(
        protected string $apiKey,
        protected string $model = 'gemini-1.5-flash'
    ) {}

    public function complete(string $systemPrompt, string $userPrompt, array $jsonSchema, array $opts = []): array
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";

        $payload = [
            'system_instruction' => [
                'parts' => [['text' => $systemPrompt]]
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [['text' => $userPrompt]]
                ]
            ],
            'generationConfig' => [
                'response_mime_type' => 'application/json',
                'response_schema' => $jsonSchema,
            ]
        ];

        $originalSystemPrompt = $systemPrompt;

        if (!empty($opts['useWebSearch'])) {
            $payload['tools'] = [
                ['google_search' => new \stdClass()]
            ];
            // Gemini does not support response_schema with tools, but we should still
            // request application/json mime type if possible.
            unset($payload['generationConfig']['response_schema']);
            $payload['generationConfig']['response_mime_type'] = 'application/json';
            
            $systemPrompt .= "\n\nIMPORTANT: You must return the output STRICTLY as a raw JSON object matching this schema. Do not wrap in markdown:\n" . json_encode($jsonSchema);
            $payload['system_instruction']['parts'][0]['text'] = $systemPrompt;
        }

        $timeout = $opts['timeoutSeconds'] ?? 30;
        $maxAttempts = 5;
        $attempt = 0;
        $parsed = null;
        $data = null;

        while ($attempt < $maxAttempts) {
            $attempt++;
            
            $response = Http::timeout($timeout)->post($url, $payload);

            if (!$response->successful()) {
                if (in_array($response->status(), [429, 503]) && $attempt < $maxAttempts) {
                    // Exponential backoff with jitter
                    $delay = pow(2, $attempt) + rand(0, 1000) / 1000;
                    \Log::warning("[GeminiLookupClient] Rate limited or overloaded ({$response->status()}). Retrying in {$delay}s... (Attempt {$attempt})");
                    sleep((int)$delay);
                    continue;
                }
                
                if (in_array($response->status(), [429, 503])) {
                    throw new LookupClientException('Gemini API is currently overloaded or rate-limited. Please try again in a few moments.');
                }
                
                throw new LookupClientException('Gemini API Error: ' . $response->body());
            }

            $data = $response->json();
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
            
            \Log::info('[GeminiLookupClient] response keys', [
                'mode' => !empty($opts['useWebSearch']) ? 'assistant' : 'quick',
                'has_grounding' => isset($data['candidates'][0]['groundingMetadata']),
                'citation_count' => isset($data['candidates'][0]['citationMetadata']['citationSources']) 
                    ? count($data['candidates'][0]['citationMetadata']['citationSources']) 
                    : 0,
            ]);

            if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $text, $matches)) {
                $text = $matches[1];
            }

            $parsed = json_decode($text, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                break; // Success
            }

            // If JSON parsing fails and we were using web search, fallback to ungrounded strict schema on retry
            if (!empty($opts['useWebSearch']) && $attempt < $maxAttempts) {
                unset($payload['tools']);
                $payload['generationConfig'] = [
                    'response_mime_type' => 'application/json',
                    'response_schema' => $jsonSchema,
                ];
                $payload['system_instruction']['parts'][0]['text'] = $originalSystemPrompt;
                continue;
            }

            if ($attempt >= $maxAttempts) {
                throw new LookupClientException('Failed to parse JSON response from Gemini. Raw text: ' . substr($text, 0, 100) . '...');
            }
        }

        $citations = [];
        if (!empty($data['candidates'][0]['groundingMetadata']['groundingChunks'])) {
            foreach ($data['candidates'][0]['groundingMetadata']['groundingChunks'] as $chunk) {
                if (isset($chunk['web']['uri']) && isset($chunk['web']['title'])) {
                    $citations[] = [
                        'title' => $chunk['web']['title'],
                        'url' => $chunk['web']['uri'],
                        'snippet' => '',
                    ];
                }
            }
        }

        $usage = [
            'input_tokens' => $data['usageMetadata']['promptTokenCount'] ?? 0,
            'output_tokens' => $data['usageMetadata']['candidatesTokenCount'] ?? 0,
            'search_count' => !empty($opts['useWebSearch']) ? 1 : 0,
        ];

        return [
            'data' => $parsed,
            'citations' => $citations,
            'usage' => $usage,
            'model' => $this->model,
        ];
    }
}
