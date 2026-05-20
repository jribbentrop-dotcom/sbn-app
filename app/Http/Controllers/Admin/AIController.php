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
            'action' => 'required|string|in:proofread,autocomplete,generate,chat',
            'content' => 'required|string',
            'context' => 'nullable|string',
            // chat: prior turns as [{role, text}, ...]
            'history' => 'nullable|array',
            'history.*.role' => 'required_with:history|string|in:user,assistant',
            'history.*.text' => 'required_with:history|string',
            // chat: text the editor currently has selected (may be empty)
            'selection' => 'nullable|string',
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
                case 'chat':
                    return $this->chat(
                        $content,
                        $context,
                        $request->input('history', []),
                        $request->input('selection', '')
                    );
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

    /**
     * Conversational assistant for the lesson editor's AI panel.
     *
     * Unlike proofread/generate this never decides where content goes —
     * it just answers. The editor decides whether to insert the reply.
     *
     * @param  string  $content    The admin's latest message.
     * @param  ?string $context    Plain-text excerpt of the lesson so far.
     * @param  array   $history    Prior turns: [['role' => ..., 'text' => ...], ...].
     * @param  string  $selection  Text currently selected in the editor (may be empty).
     */
    protected function chat(string $content, ?string $context, array $history, string $selection)
    {
        $systemPrompt = "You are a writing assistant for the music education platform 'SoulBossaNova' (SBN).
        You help an admin draft and refine lesson content through conversation.
        Keep the tone encouraging, professional, and consistent with a music school.
        When the admin asks for text to put in the lesson, return it as clean, simple HTML
        (paragraphs, headings, lists) in the `html` field. Do NOT invent <sbn-chord>,
        <sbn-rhythm> or other component tags — the admin adds those manually.
        Always also give a short conversational `reply` describing what you did or answering the question.
        If the admin only asks a question and no insertable text is needed, leave `html` empty.";

        // complete() takes a single user prompt, so the running conversation
        // is flattened into the prompt text rather than passed as message turns.
        $userPrompt = '';
        if ($context) {
            $userPrompt .= "Lesson content so far (plain text excerpt):\n{$context}\n\n";
        }
        if ($selection !== '') {
            $userPrompt .= "The admin currently has this text selected in the editor:\n\"{$selection}\"\n\n";
        }
        foreach ($history as $turn) {
            $who = ($turn['role'] ?? 'user') === 'assistant' ? 'Assistant' : 'Admin';
            $userPrompt .= "{$who}: {$turn['text']}\n";
        }
        $userPrompt .= "Admin: {$content}";

        $schema = [
            'type' => 'object',
            'properties' => [
                'reply' => ['type' => 'string', 'description' => 'Conversational answer to show in the chat'],
                'html'  => ['type' => 'string', 'description' => 'Insertable lesson HTML, or empty string if none'],
            ],
            'required' => ['reply'],
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
