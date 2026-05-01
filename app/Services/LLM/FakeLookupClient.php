<?php

namespace App\Services\LLM;

class FakeLookupClient implements LookupClient
{
    public array $responses = [];
    public array $lastRequest = [];

    public function complete(string $systemPrompt, string $userPrompt, array $jsonSchema, array $opts = []): array
    {
        $this->lastRequest = [
            'system' => $systemPrompt,
            'user' => $userPrompt,
            'schema' => $jsonSchema,
            'opts' => $opts,
        ];

        if (empty($this->responses)) {
            throw new LookupClientException('No fake responses configured.');
        }

        $response = array_shift($this->responses);

        if ($response instanceof \Exception) {
            throw $response;
        }

        return $response;
    }
}
