<?php
/**
 * api/v1/help_chat_controller.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7H · Help Centre AI Assistant — chat backend.
 *
 * Backs the floating chat widget (includes/components/help_chat_widget.php).
 * One action:
 *
 *   ask   question + lang + page_path -> grounded answer + source links
 *
 * THE PIPELINE, AND WHY IT'S ORDERED THIS WAY
 *   1. Auth + rate limit — before anything that costs money or DB writes.
 *   2. HelpRetrieval::search() — embeds the question, cosine-ranks chunks,
 *      and filters every candidate through lpc_help_visible() BEFORE this
 *      controller ever sees a chunk's text. By the time $matches exists, it
 *      is already exactly what this user is allowed to read — nothing here
 *      re-checks or could accidentally skip that check, because it already
 *      happened one layer down.
 *   3. Zero matches -> a canned "not found" answer, no DeepSeek call. Calling
 *      the model with no grounding material would only invite it to guess.
 *   4. DeepSeek answers using ONLY the retrieved excerpts (system prompt).
 *   5. Sources shown to the user are built from $matches — the slugs/titles/
 *      URLs this controller already knows and already RBAC-checked — never
 *      parsed out of the model's own text. The model is explicitly told not
 *      to write links; asking an LLM not to hallucinate a URL is a request,
 *      not a guarantee, so the citation list is never sourced from its output.
 *   6. Every ask is logged (help_chat_log, migration 101) — the raw material
 *      for a future "what can't the assistant answer" report.
 *
 * "NOT CONFIGURED" AND "NOTHING FOUND" ARE NOT ERRORS. Both return HTTP 200
 * with status=success and a message the widget renders inline — the same
 * "degrade, don't fatal" contract as the rest of the help centre.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/functions/help.php';
require_once __DIR__ . '/../../includes/classes/AiSettings.php';
require_once __DIR__ . '/../../includes/classes/AiRoleLimits.php';
require_once __DIR__ . '/../../includes/classes/HelpRetrieval.php';
require_once __DIR__ . '/../../includes/classes/DeepSeekClient.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Any authenticated user — same audience as the help centre itself. No
// help.manage gate here; asking a question isn't authoring content.
Rbac::requireAuth();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

/** Uniform JSON exit. */
function chat_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Uniform failure. */
function chat_fail(string $msg, int $code = 400): void
{
    chat_out(['status' => 'error', 'message' => $msg], $code);
}

/**
 * Best-effort log — a logging failure must never take down the actual
 * answer the visitor is about to receive.
 *
 * $billable: did this question consume one of the user's daily quota slots?
 * Only ever true on a successful DeepSeek answer — see migration 101's
 * column comment and AiRoleLimits::todayUsageFor(), which counts exactly
 * this column.
 *
 * @param array<int,array{slug:string}> $matches
 */
function chat_log(string $question, string $lang, string $pagePath, array $matches,
                   bool $hadGoodMatch, bool $billable, ?string $answer, string $errorMessage): void
{
    try {
        $slugs = array_values(array_unique(array_column($matches, 'slug')));
        Database::getInstance()->getConnection()->prepare(
            "INSERT INTO help_chat_log
                (user_id, lang, page_path, question, matched_article_slugs,
                 had_good_match, billable, answer_text, error_message)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $_SESSION['user_id'] ?? null, $lang, $pagePath, $question,
            implode(',', $slugs), $hadGoodMatch ? 1 : 0, $billable ? 1 : 0, $answer, $errorMessage,
        ]);
    } catch (Throwable $e) {
        error_log('help_chat_controller log: ' . $e->getMessage());
    }
}

switch ($action) {

    case 'ask': {
        Csrf::requireValid();

        $question = trim((string) ($_POST['question'] ?? ''));
        $lang     = lpc_help_lang($_POST['lang'] ?? null);
        $en       = $lang === 'en';
        $pagePath = mb_substr(trim((string) ($_POST['page_path'] ?? '')), 0, 190);

        if ($question === '')            { chat_fail($en ? 'Question missing.' : 'Question manquante.'); }
        if (mb_strlen($question) > 800)  { chat_fail($en ? 'Question too long (800 characters max).' : 'Question trop longue (800 caractères maximum).'); }

        // Cost/abuse guard. 20 questions/hour/user is a burst-protection safety
        // net, independent of (and stacked with) the per-role DAILY quota
        // below — this one exists to stop a script hammering the endpoint,
        // not to enforce business policy.
        RateLimiter::guard('help_ai_ask', 'user:' . ($_SESSION['user_id'] ?? 'anon'), 20, 60);

        // Shared hosting's default max_execution_time (commonly 30s) is
        // tighter than an embedding call + a DeepSeek chat completion can
        // reliably finish inside — see DeepSeekClient::TIMEOUT_S /
        // EmbeddingClient::TIMEOUT_S for the budget this leaves headroom for.
        // A clean "provider timed out" JSON error beats PHP hard-killing the
        // request mid-response with no output at all. @-silenced because some
        // hosts disable this ini setting entirely; that's fine, it just means
        // the host's own (longer, presumably) limit applies instead.
        @set_time_limit(60);

        if (!AiSettings::isDeepSeekConfigured() || !AiSettings::isEmbeddingConfigured()) {
            chat_out([
                'status'      => 'success',
                'configured'  => false,
                'had_match'   => false,
                'answer_html' => '',
                'sources'     => [],
                'message'     => $en
                    ? "The AI assistant isn't configured yet. Ask an administrator to set it up from the gear icon."
                    : "L'assistant IA n'est pas encore configuré. Demandez à un administrateur de le configurer depuis l'icône en forme d'engrenage.",
            ]);
        }

        // Per-role DAILY quota (business policy, distinct from the hourly
        // burst guard above) — see migration 104 / AiRoleLimits.php. Checked
        // before retrieval so a user who is already over quota doesn't even
        // cost an embedding call.
        $roleId    = Rbac::currentRoleId();
        $dailyCap  = AiRoleLimits::dailyLimitFor($roleId);
        if ($dailyCap !== null) {
            $usedToday = AiRoleLimits::todayUsageFor((int) ($_SESSION['user_id'] ?? 0));
            if ($usedToday >= $dailyCap) {
                chat_log($question, $lang, $pagePath, [], false, false, null, 'quota_exceeded');
                chat_out([
                    'status'         => 'success',
                    'configured'     => true,
                    'had_match'      => false,
                    'quota_exceeded' => true,
                    'answer_html'    => '',
                    'sources'        => [],
                    'message'        => $en
                        ? "You've reached today's limit of {$dailyCap} questions for the assistant. It resets at midnight."
                        : "Vous avez atteint la limite quotidienne de {$dailyCap} questions pour l'assistant. Elle se réinitialise à minuit.",
                ]);
            }
        }

        $matches = HelpRetrieval::search($question, $lang);

        if (!$matches) {
            chat_log($question, $lang, $pagePath, [], false, false, null, '');
            chat_out([
                'status'      => 'success',
                'configured'  => true,
                'had_match'   => false,
                'answer_html' => lpc_help_markdown($en
                    ? "I couldn't find anything in the help centre about that. Try rephrasing, or browse by module below."
                    : "Je n'ai rien trouvé dans le centre d'aide à ce sujet. Essayez de reformuler, ou parcourez par module ci-dessous."),
                'sources'     => [],
            ]);
        }

        // Grounding context: each retrieved chunk, labelled. The model is
        // told to draw only from these and never write a link itself — the
        // source list the user sees is built from $matches below, not from
        // anything the model writes.
        $context = '';
        foreach ($matches as $i => $m) {
            $n = $i + 1;
            $context .= "[Source {$n}: {$m['title']}]\n{$m['chunk_text']}\n\n";
        }

        $langName = $en ? 'English' : 'French';
        $system = <<<SYS
You are the help assistant embedded in the Bureau LPC ERP's help centre. Answer the user's question STRICTLY using the numbered source excerpts below — never use outside knowledge, and never invent a feature, field, button, permission or URL that is not in them.

Rules:
- Answer in {$langName}.
- Be concise and practical: a couple of short paragraphs or a short list, not an essay.
- If the sources only partially cover the question, answer the part they cover and say plainly what they don't.
- If the sources don't answer the question at all, say so plainly instead of guessing.
- Do not refer to "Source 1" / "Source 2" by number in your answer — write naturally. The sources are shown to the user separately, underneath your answer.
- Do not write any links or URLs yourself.

Sources:
{$context}
SYS;

        try {
            $answer = DeepSeekClient::chat([
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $question],
            ]);
        } catch (Throwable $e) {
            error_log('help_chat_controller ask: ' . $e->getMessage());
            chat_log($question, $lang, $pagePath, $matches, false, false, null, $e->getMessage());
            chat_fail(
                $en ? 'The assistant is temporarily unavailable. Try again shortly.'
                    : "L'assistant est momentanément indisponible. Réessayez dans un instant.",
                502
            );
        }

        // De-dupe the source list by article — retrieval can return two
        // chunks from the same article.
        $sources = [];
        $seen    = [];
        foreach ($matches as $m) {
            if (isset($seen[$m['slug']])) { continue; }
            $seen[$m['slug']] = true;
            $sources[] = ['title' => $m['title'], 'url' => $m['url'], 'category' => $m['category']];
        }

        chat_log($question, $lang, $pagePath, $matches, true, true, $answer, '');

        chat_out([
            'status'      => 'success',
            'configured'  => true,
            'had_match'   => true,
            // Reusing lpc_help_markdown() is deliberate and safe regardless of
            // the answer's origin: it escapes the ENTIRE input first and only
            // then whitelists a small tag set back in — see its own header in
            // includes/functions/help.php. An LLM's freeform text gets exactly
            // the same treatment as an author's Markdown; neither can inject
            // a raw tag.
            'answer_html' => lpc_help_markdown($answer),
            'sources'     => $sources,
        ]);
    }

    default:
        chat_fail('Unknown action.', 400);
}
