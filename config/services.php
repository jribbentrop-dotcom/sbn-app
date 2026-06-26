<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'llm' => [
        'provider' => env('LLM_PROVIDER', 'gemini'),  // 'gemini' | 'deepseek'
        'gemini' => [
            'key'   => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL', 'gemini-1.5-flash'),
        ],
        'deepseek' => [
            'key'   => env('DEEPSEEK_API_KEY'),
            'model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
            'base'  => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com/v1'),
        ],
        'groq' => [
            'key'   => env('GROQ_API_KEY'),
            'model' => env('GROQ_MODEL', 'llama-3.3-70b-versatile'),
            'base'  => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
        ],
        'cohere' => [
            'key'   => env('COHERE_API_KEY'),
            'model' => env('COHERE_MODEL', 'command-r-plus-08-2024'),
        ],
        'ollama' => [
            'model' => env('OLLAMA_MODEL', 'llama3'),
            'base'  => env('OLLAMA_BASE_URL', 'http://localhost:11434/v1'),
        ],
    ],

    // MuseScore CLI — converts uploaded .mscz/.mscx to MusicXML on the local
    // (Windows) dev machine. Override with MUSESCORE_BIN in .env if installed
    // elsewhere. Used by LeadsheetController::convertMscz().
    'musescore' => [
        'bin' => env('MUSESCORE_BIN', 'C:\\Program Files\\MuseScore 4\\bin\\MuseScore4.exe'),
    ],

];

