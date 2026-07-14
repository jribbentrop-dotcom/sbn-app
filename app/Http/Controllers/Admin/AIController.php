<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AIProcessRequest;
use App\Services\LLM\LookupClient;
use Illuminate\Support\Facades\Log;

class AIController extends Controller
{
    public function __construct(
        protected LookupClient $llm
    ) {}

    public function process(AIProcessRequest $request)
    {
        set_time_limit(120);
        ini_set('max_execution_time', 120);

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
                        $request->input('selection') ?? '',
                        $request->input('lessonMeta', [])
                    );
                case 'describe':
                    return $this->describe(
                        $content,
                        $request->input('entityType') ?? '',
                        $request->input('entityMeta', []),
                        $request->input('history', [])
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
    protected function chat(string $content, ?string $context, array $history, string $selection, array $lessonMeta = [])
    {
        $systemPrompt = "You are an expert music educator and writing assistant for 'SoulBossaNova' (SBN), a guitar education platform specialising in bossa nova, jazz, Brazilian music, and classical guitar.

You help an admin draft and refine lesson content through conversation. Be specific and musically rich — name composers, artists, techniques, chord qualities, rhythmic feels. The platform's students are serious learners who appreciate depth and accuracy.

When the admin asks for text to put in the lesson, return it as clean HTML (paragraphs, headings, lists) in the `html` field. Do NOT invent <sbn-chord>, <sbn-rhythm> or other component tags — the admin adds those manually.
Always give a short conversational `reply` (1–2 sentences) describing what you did or answering the question.
If the admin only asks a question and no insertable text is needed, leave `html` empty.";

        $userPrompt = '';

        // Inject lesson + course context at the top
        $metaLines = array_filter([
            !empty($lessonMeta['courseTitle'])  ? "Course: {$lessonMeta['courseTitle']}"   : null,
            !empty($lessonMeta['courseGenre'])  ? "Genre/category: {$lessonMeta['courseGenre']}" : null,
            !empty($lessonMeta['lessonTitle'])  ? "Lesson: {$lessonMeta['lessonTitle']}"   : null,
            !empty($lessonMeta['sectionTitle']) ? "Section: {$lessonMeta['sectionTitle']}" : null,
        ]);
        if ($metaLines) {
            $userPrompt .= implode("\n", $metaLines) . "\n\n";
        }

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

    protected function describe(string $message, ?string $entityType, array $meta, array $history)
    {
        $typeLabels = [
            'rhythm'      => 'guitar rhythm pattern',
            'progression' => 'chord progression',
            'chord'       => 'guitar chord voicing',
            'leadsheet'   => 'song',
            'course'      => 'guitar course',
        ];
        $typeLabel = $typeLabels[$entityType] ?? 'music item';

        // Build a rich metadata block from whatever the caller provides
        $metaLines = [];
        $metaMap = [
            'name'          => 'Name',
            'title'         => 'Title',
            'composer'      => 'Composer',
            'artist'        => 'Artist',
            'genre'         => 'Genre',
            'category'      => 'Category',
            'style'         => 'Style',
            'numerals'      => 'Chord numerals',
            'key'           => 'Key',
            'tempo'         => 'Tempo (BPM)',
            'timeSignature' => 'Time signature',
            'difficulty'    => 'Difficulty',
            'tags'          => 'Tags',
            'quality'       => 'Chord quality',
            'voicing'       => 'Voicing type',
            'excerpt'       => 'Current excerpt',
        ];
        foreach ($metaMap as $key => $label) {
            if (!empty($meta[$key])) {
                $value = is_array($meta[$key]) ? implode(', ', $meta[$key]) : $meta[$key];
                $metaLines[] = "{$label}: {$value}";
            }
        }
        $metaBlock = implode("\n", $metaLines);

        $systemPrompt = "You are an expert music educator and writer for 'SoulBossaNova' (SBN), a guitar education platform covering bossa nova, jazz, Brazilian music, classical guitar, and beyond.

You write public-facing library descriptions that are engaging, informative, and rich — the kind of text a student reads before deciding to study a piece, a chord, or a technique. Think of the best music encyclopaedia entries: they give historical context, musical character, performance tips, and why the item matters. Aim for 2–4 paragraphs unless the user asks otherwise.

CRITICAL: Always write about the SPECIFIC item described in the metadata. Do not default to bossa nova or jazz if the item is from a different genre or era. A Pachelbel canon gets baroque context; a blues shuffle gets blues history; a flamenco chord gets flamenco context.

Guidelines:
- Write in flowing prose, not bullet points (unless specifically asked).
- Be specific: name composers, artists, albums, techniques, musical eras.
- Connect the item to the broader musical world — where it comes from, where you hear it, how it feels to play.
- Keep the tone warm and educational, not dry or academic.
- Return clean HTML: use <p> for paragraphs, <strong> for emphasis, <h3> for any sub-headings if needed.
- Do NOT invent chord tag syntax or SBN component tags.
- Always also give a short conversational `reply` (1–2 sentences) summarising what you wrote.";

        // Lead with the most identifying information so the model anchors on it
        $nameHint = !empty($meta['title']) ? $meta['title']
            : (!empty($meta['name']) ? $meta['name'] : null);
        $composerHint = !empty($meta['composer']) ? $meta['composer'] : null;

        $userPrompt = "Write a description for the following {$typeLabel}:\n";
        if ($nameHint)    $userPrompt .= "Title/Name: {$nameHint}\n";
        if ($composerHint) $userPrompt .= "Composer/Artist: {$composerHint}\n";
        if ($metaBlock)   $userPrompt .= "Additional metadata:\n{$metaBlock}\n";

        // Flatten chat history into the prompt
        foreach ($history as $turn) {
            $who = ($turn['role'] ?? 'user') === 'assistant' ? 'Assistant' : 'Admin';
            $userPrompt .= "\n{$who}: {$turn['text']}";
        }
        $userPrompt .= "\nAdmin: {$message}";

        $schema = [
            'type' => 'object',
            'properties' => [
                'reply' => ['type' => 'string', 'description' => 'Short conversational summary of what was written'],
                'html'  => ['type' => 'string', 'description' => 'The full description as clean HTML'],
            ],
            'required' => ['reply', 'html'],
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
