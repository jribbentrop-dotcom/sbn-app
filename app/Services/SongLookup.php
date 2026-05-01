<?php

namespace App\Services;

use App\Models\LookupCache;
use App\Services\LLM\LookupClient;
use App\Services\LLM\LookupClientException;
use Carbon\Carbon;

class SongLookup
{
    public function __construct(
        private LookupClient $client,
        private ProgressionDetector $detector
    ) {}

    public function lookup(array $opts): array
    {
        $title = $opts['title'] ?? '';
        $artistHint = $opts['artist_hint'] ?? '';
        $preferredKey = $opts['preferred_key'] ?? '';
        $version = $opts['version'] ?? 'most_common';
        $mode = $opts['mode'] ?? 'quick'; // 'quick' or 'assistant'

        // 1. Build cache key
        // NOTE: We will reshape the cache table to be title-based in the next step.
        // For now, we still use the full hint string to avoid breaking existing dev state.
        $cacheString = strtolower($title) . '|' . strtolower($artistHint) . '|' . strtolower($preferredKey) . '|' . strtolower($version) . '|' . $mode;
        $cacheKey = hash('sha256', $cacheString);

        // 2. Check cache
        $cached = LookupCache::where('cache_key', $cacheKey)
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if ($cached) {
            return $cached->analysis;
        }

        // 3. Build prompts
        $systemPrompt = <<<PROMPT
You are a music librarian and transcription assistant. Given a song title and optional artist/version hint, return the chord structure as strict JSON matching the IntermediateAnalysis schema.

## GENERAL RULES:
- Return the most commonly taught version unless the user specifies otherwise via `version`.
- Prefer Real Book / iReal Pro / well-known fake-book changes for jazz standards.
- Prefer top-rated Ultimate Guitar tabs for pop/rock.
- Use canonical chord notation: 'Cmaj7' not 'CΔ7', 'Dm7b5' not 'Dø7', 'G7' not 'G7dom'.
- If multiple valid versions exist, return the most-taught in `sections` and put the others in `alternatives` (max 2).
- Section names: use the song's actual form labels (A/B for AABA, Verse/Chorus for pop, etc.).
- `melody` must be null for v1.
- `rhythm_hint` should be one short phrase (e.g. "bossa nova", "shuffle", "ballad rubato").

PROMPT;

        if ($mode === 'assistant') {
            $systemPrompt .= <<<ASSISTANT

## ASSISTANT MODE RULES:
- You are acting as a Research Assistant. You MUST use your search tools to find the EXACT arrangement details.
- **CHORD ACCURACY**: Do NOT return generic, simplified, or "vanilla" chord progressions (e.g., C-Am-Dm-G) unless that is the literal arrangement. You are expected to provide the full complexity of the specific artist's version (e.g. extensions like 9, 11, 13, altered chords, specific bass notes).
- **RESEARCH BLOCK**: Provide a detailed `research` block based on your findings.
- **YOUTUBE VERIFICATION**: Suggest 2–4 YouTube URLs in `suggested_videos`. Cross-reference the title and channel from your search results to ensure the URL is correct. Do NOT hallucinate video IDs. Use FULL URLs.
- Mark each video with `recording_match`: 'exact' (the requested recording), 'similar' (same artist), or 'tutorial'.
- `voicing_hints` should focus on characteristic "must-have" voicings. Provide a 6-character fret string (e.g., 'x5656x').
- If no specific research is found, do not hallucinate; provide general stylistic context in `canonical_changes_source`.

ASSISTANT;
        } else {
            $systemPrompt .= "\n- QUICK MODE: Keep the response concise. The `research` property MUST be null.\n";
        }

        $systemPrompt .= "\nReturn ONLY valid JSON matching the schema. No markdown, no prose.\n";

        $userPrompt = "Title: {$title}\n";
        if ($artistHint) {
            $userPrompt .= "Artist/Hint: {$artistHint}\n";
        }
        if ($preferredKey && $preferredKey !== 'canonical') {
            $userPrompt .= "Preferred Key: {$preferredKey} (please transpose if needed)\n";
        }
        $userPrompt .= "Version preference: {$version}\n";
        $userPrompt .= "Mode: " . strtoupper($mode) . "\n";

        $jsonSchema = [
            'type' => 'OBJECT',
            'properties' => [
                'title' => ['type' => 'STRING'],
                'composer' => ['type' => 'STRING', 'nullable' => true],
                'key' => ['type' => 'STRING'],
                'tempo' => ['type' => 'INTEGER', 'nullable' => true],
                'timeSignature' => ['type' => 'STRING'],
                'sections' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'name' => ['type' => 'STRING'],
                            'bars' => [
                                'type' => 'ARRAY',
                                'items' => [
                                    'type' => 'OBJECT',
                                    'properties' => [
                                        'chords' => [
                                            'type' => 'ARRAY',
                                            'items' => [
                                                'type' => 'OBJECT',
                                                'properties' => [
                                                    'label' => ['type' => 'STRING'],
                                                    'beats' => ['type' => 'INTEGER'],
                                                    'confidence' => ['type' => 'NUMBER', 'nullable' => true]
                                                ],
                                                'required' => ['label', 'beats']
                                            ]
                                        ]
                                    ],
                                    'required' => ['chords']
                                ]
                            ]
                        ],
                        'required' => ['name', 'bars']
                    ]
                ],
                'melody' => ['type' => 'ARRAY', 'nullable' => true, 'items' => ['type' => 'OBJECT']],
                'rhythm_hint' => ['type' => 'STRING', 'nullable' => true],
                'source_note' => ['type' => 'STRING'],
                'confidence' => ['type' => 'STRING', 'enum' => ['high', 'medium', 'low']],
                'alternatives' => [
                    'type' => 'ARRAY',
                    'nullable' => true,
                    'items' => ['type' => 'OBJECT']
                ],
                'research' => [
                    'type' => 'OBJECT',
                    'nullable' => true,
                    'properties' => [
                        'mode' => ['type' => 'STRING'],
                        'canonical_changes_source' => ['type' => 'STRING'],
                        'notable_versions' => [
                            'type' => 'ARRAY',
                            'items' => [
                                'type' => 'OBJECT',
                                'properties' => [
                                    'artist' => ['type' => 'STRING'],
                                    'recording' => ['type' => 'STRING'],
                                    'year' => ['type' => 'INTEGER', 'nullable' => true],
                                    'differences' => ['type' => 'STRING'],
                                    'source_url' => ['type' => 'STRING', 'nullable' => true],
                                    'source_type' => ['type' => 'STRING', 'enum' => ['transcription', 'analysis', 'forum', 'general_knowledge']]
                                ]
                            ]
                        ],
                        'voicing_hints' => [
                            'type' => 'ARRAY',
                            'items' => [
                                'type' => 'OBJECT',
                                'properties' => [
                                    'chord' => ['type' => 'STRING'],
                                    'suggestion' => ['type' => 'STRING', 'nullable' => true],
                                    'description' => ['type' => 'STRING'],
                                    'attribution' => ['type' => 'STRING'],
                                    'source_url' => ['type' => 'STRING', 'nullable' => true]
                                ]
                            ]
                        ],
                        'transcription_notes' => ['type' => 'STRING', 'nullable' => true],
                        'suggested_videos' => [
                            'type' => 'ARRAY',
                            'items' => [
                                'type' => 'OBJECT',
                                'properties' => [
                                    'url' => ['type' => 'STRING'],
                                    'title' => ['type' => 'STRING'],
                                    'channel' => ['type' => 'STRING'],
                                    'rationale' => ['type' => 'STRING'],
                                    'recording_match' => ['type' => 'STRING', 'enum' => ['exact', 'similar', 'tutorial', 'unrelated']]
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            'required' => ['title', 'key', 'timeSignature', 'sections', 'source_note', 'confidence']
        ];

        \Log::info('[SongLookup] starting lookup', [
            'title' => $title,
            'mode' => $mode,
            'useWebSearch' => ($mode === 'assistant')
        ]);

        try {
            $response = $this->client->complete($systemPrompt, $userPrompt, $jsonSchema, [
                'useWebSearch' => ($mode === 'assistant'), // Grounded for assistant mode
                'maxSearchUses' => 5,
                'timeoutSeconds' => 60,
            ]);
        } catch (LookupClientException $e) {
            throw new SongLookupException("LLM API failed: " . $e->getMessage(), 0, $e);
        }

        $analysis = $response['data'];
        
        // Ensure research key exists for consistency (nullable)
        if (!isset($analysis['research'])) {
            $analysis['research'] = null;
        }

        // 4. Validate and fix up
        $analysis = $this->validateAndFix($analysis, $response['citations']);

        // 5. Store in cache
        LookupCache::updateOrCreate(
            ['cache_key' => $cacheKey],
            [
                'title' => $title,
                'mode' => $mode,
                'analysis' => $analysis,
                'expires_at' => Carbon::now()->addDays(30),
            ]
        );

        return $analysis;
    }

    protected function validateAndFix(array $analysis, array $citations): array
    {
        if (!preg_match('/^[A-G][#b]?m?$/', $analysis['key'] ?? '')) {
            $analysis['confidence'] = 'low';
            $analysis['source_note'] = ($analysis['source_note'] ?? '') . " (Invalid key: " . ($analysis['key'] ?? 'null') . ")";
            $analysis['key'] = 'C';
        }

        if (!preg_match('/^\d+\/\d+$/', $analysis['timeSignature'] ?? '')) {
            $analysis['source_note'] = ($analysis['source_note'] ?? '') . " (time-sig fallback)";
            $analysis['timeSignature'] = '4/4';
        }

        $timeParts = explode('/', $analysis['timeSignature']);
        $displayBeats = (int) ($timeParts[0] ?? 4);

        if (isset($analysis['tempo'])) {
            $analysis['tempo'] = max(20, min(300, (int)$analysis['tempo']));
        }

        foreach (($analysis['sections'] ?? []) as &$section) {
            foreach (($section['bars'] ?? []) as &$bar) {
                $barBeats = 0;
                foreach (($bar['chords'] ?? []) as &$chord) {
                    $barBeats += (int) ($chord['beats'] ?? 0);
                    
                    $label = $chord['label'] ?? '?';
                    $parsedRes = $this->detector->parseChordName($label);
                    
                    if (!$parsedRes || empty($parsedRes['root'])) {
                        $chord['label'] = $label;
                    }
                }
                
                // 4.9.20 Polish: Do not flip the entire sheet's confidence to 'low' just because a bar's beats don't sum to the time signature perfectly.
                // The chord sequence is often still correct even if the LLM's arithmetic is slightly off.
            }
        }

        if (!empty($citations) && !empty($citations[0]['title'])) {
            $topCite = $citations[0]['title'];
            if (!str_contains($analysis['source_note'] ?? '', $topCite)) {
                $analysis['source_note'] = ($analysis['source_note'] ?? '') . " [Cited: {$topCite}]";
            }
        }

        return $analysis;
    }
}
