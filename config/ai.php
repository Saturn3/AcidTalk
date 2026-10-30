<?php
return [
    'default' => env('AI_DEFAULT_PROVIDER', 'ollama'),
    'providers' => [
        'ollama' => [
            'base_url' => env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434'),
            'model'    => env('OLLAMA_MODEL', 'llama3.1'),
        ],
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model'   => env('OPENAI_MODEL', 'gpt-4o-mini'),
        ],
        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model'   => env('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
        ],
    ],
    // System prompt del bot AI nei canali
    'system_prompt' => "Sei l'assistente AI di AcidTalk. Rispondi in modo utile e conciso usando Markdown.",
];
