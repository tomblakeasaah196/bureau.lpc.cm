<?php
/**
 * includes/mail/Ai/ai_config.php
 * -----------------------------------------------------------------------------
 * Courrier, Praxis AI (PR 5; docs/MAIL.md §7.6): fixed numbers, read through
 * LPC\Mail\Ai\Settings::config('limits.ask'). The Gemini key and the model are
 * not here: the owner sets them on modules/mail/ai_settings.php (mail_settings
 * ai.gemini_key, encrypted with Crypto, and ai.model).
 * -----------------------------------------------------------------------------
 */

return [

    // Gemini only (never DeepSeek for mail: it holds personal data). The model when ai.model is not set.
    'default_model' => 'gemini-2.5-flash',
    'endpoint'      => 'https://generativelanguage.googleapis.com/v1beta/models/',
    // Seconds: the whole request, and the connection alone
    'timeout'         => 25,
    'connect_timeout' => 5,

    // Uses per person per hour (owner decision: limits per person)
    'limits' => [
        'write'   => 30,   // Écrire avec l'IA
        'summary' => 30,   // Résumer
        'ask'     => 20,   // Demander à votre courrier
        'test'    => 10,   // Tester la clé (admins)
    ],

    // What Praxis AI reads of a conversation: at most its last 30 messages, each cut to this many characters
    'context_messages'     => 30,
    'chars_per_message'    => 4000,
    // Bodies not yet copied into bureau that a summary may read from the server (the rest use their preview)
    'live_bodies'          => 5,
    // Ask: messages found by the search that go to Gemini, and the characters of each excerpt
    'ask_sources'          => 12,
    'excerpt_chars'        => 400,
    // Longest question, instruction and draft a person can send
    'max_question'         => 500,
    'max_instruction'      => 2000,
    'max_draft'            => 8000,
];
