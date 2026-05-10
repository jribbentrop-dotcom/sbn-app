<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\LLM\LookupClient;
use App\Services\LLM\GeminiLookupClient;
use App\Services\LLM\DeepSeekLookupClient;
use App\Services\LLM\FakeLookupClient;
use App\Services\LLM\OllamaLookupClient;

class LLMServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(LookupClient::class, function ($app) {
            
            if ($app->environment('testing')) {
                return new FakeLookupClient();
            }

            $provider = config('services.llm.provider', 'gemini');

            return match ($provider) {
                'gemini' => new GeminiLookupClient(
                    apiKey: config('services.llm.gemini.key', ''),
                    model: config('services.llm.gemini.model', 'gemini-1.5-flash')
                ),
                'deepseek' => new DeepSeekLookupClient(
                    apiKey: config('services.llm.deepseek.key', ''),
                    model: config('services.llm.deepseek.model', 'deepseek-chat'),
                    baseUrl: config('services.llm.deepseek.base', 'https://api.deepseek.com/v1')
                ),
                'groq' => new \App\Services\LLM\GroqLookupClient(
                    apiKey: config('services.llm.groq.key', ''),
                    model: config('services.llm.groq.model', 'llama-3.3-70b-versatile'),
                    baseUrl: config('services.llm.groq.base', 'https://api.groq.com/openai/v1')
                ),
                'cohere' => new \App\Services\LLM\CohereLookupClient(
                    apiKey: config('services.llm.cohere.key', ''),
                    model: config('services.llm.cohere.model', 'command-r-plus-08-2024')
                ),
                'ollama' => new OllamaLookupClient(
                    model: config('services.llm.ollama.model', 'llama3'),
                    baseUrl: config('services.llm.ollama.base', 'http://localhost:11434/v1')
                ),
                'fake' => new FakeLookupClient(),
                default => throw new \RuntimeException("Unknown LLM provider: {$provider}"),
            };
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
