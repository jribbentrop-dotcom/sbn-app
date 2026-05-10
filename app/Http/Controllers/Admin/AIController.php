<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\LLM\LookupClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AIController extends Controller
{
    public function __construct(
        protected LookupClient $llm
    ) {}

    public function process(Request $request)
    {
        set_time_limit(120);
        ini_set('max_execution_time', 120);

        $request->validate([
            'action' => 'required|string|in:proofread,autocomplete,generate',
            'content' => 'required|string',
            'context' => 'nullable|string',
        ]);

        $action = $request->input('action');
        $content = $request->input('content');
        $context = $request->input('context');

        try {
            switch ($action) {
                case 'proofread':
                    return $this->proofread($content, $context);
                case 'autocomplete':
                    return $this->autocomplete($content, $context);
                case 'generate':
                    return $this->generate($content, $context);
            }
        } catch (\Exception $e) {
            Log::error("[AIController] Process failed: " . $e->getMessage());
            return response()->json(['error' => 'AI processing failed. Please try again.'], 500);
        }
    }

    protected function proofread(string $content, ?string $context)
    {
        $systemPrompt = "You are a professional music educator and editor for 'SoulBossaNova' (SBN). 
        Your task is to proofread the provided text for grammar, tone, and clarity. 
        Keep the tone encouraging, professional, and consistent with a music school.
        Maintain any existing special tags like <sbn-chord>, <sbn-rhythm>, etc. exactly as they are. 
        Do NOT add new tags.";

        $userPrompt = "Please proofread this content:\n\n{$content}";
        if ($context) {
            $userPrompt .= "\n\nContext about the lesson:\n{$context}";
        }

        $schema = [
            'type' => 'object',
            'properties' => [
                'improved_text' => ['type' => 'string'],
                'changes_made' => ['type' => 'string', 'description' => 'Brief summary of changes'],
            ],
            'required' => ['improved_text'],
        ];

        $response = $this->llm->complete($systemPrompt, $userPrompt, $schema);

        return response()->json($response['data']);
    }

    protected function autocomplete(string $content, ?string $context)
    {
        $systemPrompt = "You are an expert assistant for the music education platform 'SoulBossaNova'. 
        Provide a natural continuation for the provided text. 
        Keep it concise, relevant, and text-focused. Do NOT insert new <sbn-...> tags.
        Maintain the style of the existing content.";

        $userPrompt = "Context of the lesson so far:\n{$context}\n\nContinue this sentence or paragraph:\n{$content}";

        $schema = [
            'type' => 'object',
            'properties' => [
                'suggestion' => ['type' => 'string'],
            ],
            'required' => ['suggestion'],
        ];

        $response = $this->llm->complete($systemPrompt, $userPrompt, $schema);

        return response()->json($response['data']);
    }

    protected function generate(string $prompt, ?string $context)
    {
        $systemPrompt = "You are a professional music content creator for 'SoulBossaNova'.
        Generate high-quality educational content based on the user's prompt.
        Focus on providing clear, engaging text. 
        Do NOT insert component tags like <sbn-chord> or <sbn-rhythm>. The user will add these manually.
        Return the content in clean HTML format.";

        $userPrompt = "Generate content for:\n{$prompt}";
        if ($context) {
            $userPrompt .= "\n\nContext:\n{$context}";
        }

        $schema = [
            'type' => 'object',
            'properties' => [
                'generated_html' => ['type' => 'string'],
            ],
            'required' => ['generated_html'],
        ];

        $response = $this->llm->complete($systemPrompt, $userPrompt, $schema);

        return response()->json($response['data']);
    }
}
