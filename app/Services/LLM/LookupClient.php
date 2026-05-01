<?php

namespace App\Services\LLM;

interface LookupClient
{
    /**
     * Send a structured lookup request to the LLM.
     *
     * @param string $systemPrompt
     * @param string $userPrompt
     * @param array  $jsonSchema   Schema for the expected response shape (provider-agnostic;
     *                              adapter translates to provider's response_format / tool_use mechanism)
     * @param array  $opts {
     *   useWebSearch:    bool,    // request grounding/search tool if the provider supports it
     *   maxSearchUses:   int,     // safety cap, default 3
     *   timeoutSeconds:  int,     // default 30
     * }
     * @return array {
     *   data:        array,        // parsed JSON response matching $jsonSchema
     *   citations:   array,        // [{title, url, snippet}, ...] if grounding was used; else []
     *   usage:       array,        // {input_tokens, output_tokens, search_count}
     *   model:       string,       // identifier of model that answered
     * }
     * @throws LookupClientException
     */
    public function complete(string $systemPrompt, string $userPrompt, array $jsonSchema, array $opts = []): array;
}
