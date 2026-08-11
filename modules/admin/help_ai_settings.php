<?php
/**
 * modules/admin/help_ai_settings.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7H · Help Centre AI Assistant — settings screen.
 *
 * The gear icon next to "Manage articles" on /modules/help/index.php opens
 * here. Two provider blocks — DeepSeek (answers) and the embedding provider
 * (search index) — each with a base URL, a model name and an API key.
 *
 * THE KEY FIELD NEVER SHOWS A REAL VALUE. Once a key is saved, this page only
 * ever renders its masked tail (AiSettings::getMasked()) plus a "configured"
 * badge. The input is left blank; typing something in it and saving replaces
 * the stored key, leaving it blank and saving keeps the old one. See
 * AiSettings::save() for why that split makes it safe to save the form just
 * to change a model name without accidentally overwriting the key with
 * bullet characters.
 *
 * Gated on help.manage — same permission as the article editor. See
 * migration 098 for why this doesn't get its own permission.
 *
 * Shell contract: README §5.5.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/functions/help.php';
require_once __DIR__ . '/../../includes/classes/AiSettings.php';
require_once __DIR__ . '/../../includes/classes/AiRoleLimits.php';

Rbac::requirePermission('help.manage');

$lang = lpc_help_lang($_GET['lang'] ?? null);
$en   = $lang === 'en';

$settings   = AiSettings::getMasked();
$roleLimits = AiRoleLimits::allWithRoles();

$pageTitle    = $en ? 'Praxis settings' : 'Paramètres de Praxis';
$pageSubtitle = $en ? 'Help centre AI assistant · configuration' : "Assistant IA du centre d'aide · configuration";
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> | LPC ERP</title>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
?>

<div id="lpc-shell-main">

    <div class="lpc-toolbar">
        <a href="/modules/help/index.php?lang=<?= $lang ?>"
           class="text-sm font-bold text-gray-500 hover:text-lpc-dark no-underline">
            ← <?= $en ? 'Back to the help centre' : "Retour au centre d'aide" ?>
        </a>
    </div>

    <main role="main" id="main" class="lpc-page">

        <section class="mb-6">
            <h1 class="text-2xl font-black text-gray-900 mb-1"><?= htmlspecialchars($pageTitle) ?></h1>
            <p class="text-sm text-gray-500 max-w-2xl">
                <?= $en
                    ? 'These settings are read from the database on every request — changing a key, a model or a URL here takes effect immediately, with no deploy.'
                    : "Ces paramètres sont lus en base à chaque requête — changer une clé, un modèle ou une URL ici prend effet immédiatement, sans déploiement." ?>
            </p>
        </section>

        <div id="ai-settings-banner" class="hidden mb-5 rounded-xl px-4 py-3 text-sm font-bold"></div>

        <form id="ai-settings-form" class="space-y-6">
            <?= Csrf::field() ?>

            <!-- ============================ DEEPSEEK ============================ -->
            <fieldset class="bg-white rounded-2xl border border-lpc-border shadow-sm p-5">
                <legend class="px-1">
                    <span class="text-base font-black text-gray-900"><?= $en ? 'DeepSeek — answers' : 'DeepSeek — réponses' ?></span>
                    <span class="lpc-help-badge <?= $settings['has_deepseek_key'] ? 'lpc-help-badge--on' : 'lpc-help-badge--off' ?> ml-2">
                        <?= $settings['has_deepseek_key'] ? ($en ? 'Configured' : 'Configuré') : ($en ? 'Not configured' : 'Non configuré') ?>
                    </span>
                </legend>
                <p class="text-[12px] text-gray-500 mb-4">
                    <?= $en
                        ? 'The chat model that writes the answer the visitor reads, grounded in the retrieved help articles.'
                        : "Le modèle de dialogue qui rédige la réponse lue par le visiteur, à partir des articles d'aide récupérés." ?>
                </p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <label class="block">
                        <span class="block text-[11px] font-extrabold uppercase tracking-wider text-gray-500 mb-1">Base URL</span>
                        <input type="text" name="deepseek_base_url" placeholder="https://api.deepseek.com"
                               value="<?= htmlspecialchars($settings['deepseek_base_url']) ?>"
                               class="w-full border border-lpc-border rounded-xl px-3 py-2 text-sm font-mono">
                    </label>
                    <label class="block">
                        <span class="block text-[11px] font-extrabold uppercase tracking-wider text-gray-500 mb-1"><?= $en ? 'Model' : 'Modèle' ?></span>
                        <input type="text" name="deepseek_model" placeholder="deepseek-v4-flash"
                               value="<?= htmlspecialchars($settings['deepseek_model']) ?>"
                               class="w-full border border-lpc-border rounded-xl px-3 py-2 text-sm font-mono">
                    </label>
                    <label class="block md:col-span-2">
                        <span class="block text-[11px] font-extrabold uppercase tracking-wider text-gray-500 mb-1"><?= $en ? 'API key' : 'Clé API' ?></span>
                        <input type="password" name="deepseek_api_key" autocomplete="off"
                               placeholder="<?= $settings['has_deepseek_key'] ? htmlspecialchars($settings['deepseek_key_masked']) : ($en ? '(none saved)' : '(aucune enregistrée)') ?>"
                               class="w-full border border-lpc-border rounded-xl px-3 py-2 text-sm font-mono">
                        <span class="block text-[11px] text-gray-400 mt-1">
                            <?= $en
                                ? 'Leave blank to keep the current key. Never re-displayed once saved.'
                                : "Laisser vide pour conserver la clé actuelle. Jamais réaffichée une fois enregistrée." ?>
                        </span>
                    </label>
                </div>

                <button type="button" class="lpc-ai-test-btn mt-4 text-sm font-bold text-lpc-dark hover:underline" data-target="deepseek">
                    <?= $en ? 'Test this connection' : 'Tester cette connexion' ?>
                </button>
                <span class="lpc-ai-test-result ml-2 text-sm" data-target-result="deepseek"></span>
            </fieldset>

            <!-- =========================== EMBEDDINGS =========================== -->
            <fieldset class="bg-white rounded-2xl border border-lpc-border shadow-sm p-5">
                <legend class="px-1">
                    <span class="text-base font-black text-gray-900"><?= $en ? 'Embeddings — search index' : 'Embeddings — index de recherche' ?></span>
                    <span class="lpc-help-badge <?= $settings['has_embedding_key'] ? 'lpc-help-badge--on' : 'lpc-help-badge--off' ?> ml-2">
                        <?= $settings['has_embedding_key'] ? ($en ? 'Configured' : 'Configuré') : ($en ? 'Not configured' : 'Non configuré') ?>
                    </span>
                </legend>
                <p class="text-[12px] text-gray-500 mb-4">
                    <?= $en
                        ? 'DeepSeek has no embeddings endpoint, so this is a separate provider (e.g. OpenAI text-embedding-3-small). Only used by the nightly reindex job — never on the read path.'
                        : "DeepSeek n'a pas de point de terminaison d'embeddings — il s'agit donc d'un fournisseur distinct (ex. OpenAI text-embedding-3-small). Utilisé uniquement par la tâche de réindexation nocturne — jamais lors d'une lecture." ?>
                </p>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <label class="block">
                        <span class="block text-[11px] font-extrabold uppercase tracking-wider text-gray-500 mb-1">Base URL</span>
                        <input type="text" name="embedding_base_url" placeholder="https://api.openai.com/v1"
                               value="<?= htmlspecialchars($settings['embedding_base_url']) ?>"
                               class="w-full border border-lpc-border rounded-xl px-3 py-2 text-sm font-mono">
                    </label>
                    <label class="block">
                        <span class="block text-[11px] font-extrabold uppercase tracking-wider text-gray-500 mb-1"><?= $en ? 'Model' : 'Modèle' ?></span>
                        <input type="text" name="embedding_model" placeholder="text-embedding-3-small"
                               value="<?= htmlspecialchars($settings['embedding_model']) ?>"
                               class="w-full border border-lpc-border rounded-xl px-3 py-2 text-sm font-mono">
                    </label>
                    <label class="block md:col-span-2">
                        <span class="block text-[11px] font-extrabold uppercase tracking-wider text-gray-500 mb-1"><?= $en ? 'API key' : 'Clé API' ?></span>
                        <input type="password" name="embedding_api_key" autocomplete="off"
                               placeholder="<?= $settings['has_embedding_key'] ? htmlspecialchars($settings['embedding_key_masked']) : ($en ? '(none saved)' : '(aucune enregistrée)') ?>"
                               class="w-full border border-lpc-border rounded-xl px-3 py-2 text-sm font-mono">
                        <span class="block text-[11px] text-gray-400 mt-1">
                            <?= $en ? 'Leave blank to keep the current key.' : 'Laisser vide pour conserver la clé actuelle.' ?>
                        </span>
                    </label>
                </div>

                <button type="button" class="lpc-ai-test-btn mt-4 text-sm font-bold text-lpc-dark hover:underline" data-target="embedding">
                    <?= $en ? 'Test this connection' : 'Tester cette connexion' ?>
                </button>
                <span class="lpc-ai-test-result ml-2 text-sm" data-target-result="embedding"></span>
            </fieldset>

            <div class="flex items-center gap-3">
                <button type="submit"
                        class="bg-lpc-dark hover:bg-[#004722] text-white px-5 py-2.5 rounded-xl font-bold shadow-md transition-all">
                    <?= $en ? 'Save settings' : 'Enregistrer' ?>
                </button>
                <?php if ($settings['updated_at']): ?>
                    <span class="text-[11px] text-gray-400">
                        <?= $en ? 'Last updated' : 'Dernière modification' ?> : <?= htmlspecialchars($settings['updated_at']) ?>
                    </span>
                <?php endif; ?>
            </div>
        </form>

        <!-- ========================= DAILY USAGE LIMITS ========================= -->
        <section class="mt-8">
            <h2 class="text-base font-black text-gray-900 mb-1"><?= $en ? 'Daily usage limits' : "Limites d'utilisation quotidienne" ?></h2>
            <p class="text-[12px] text-gray-500 mb-4 max-w-2xl">
                <?= $en
                    ? 'How many questions each role may ask Praxis per calendar day. Only questions that get an actual answer count — a "nothing found" reply is free. Leave a field blank for unlimited.'
                    : "Combien de questions chaque rôle peut poser à Praxis par jour civil. Seules les questions ayant obtenu une réponse comptent — une réponse « rien trouvé » est gratuite. Laisser un champ vide pour illimité." ?>
            </p>

            <div id="ai-role-limits-banner" class="hidden mb-4 rounded-xl px-4 py-3 text-sm font-bold"></div>

            <form id="ai-role-limits-form" class="bg-white rounded-2xl border border-lpc-border shadow-sm overflow-hidden">
                <?= Csrf::field() ?>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-50 text-left text-[11px] uppercase tracking-wider text-gray-500">
                            <th class="px-4 py-3 font-extrabold"><?= $en ? 'Role' : 'Rôle' ?></th>
                            <th class="px-4 py-3 font-extrabold w-48"><?= $en ? 'Daily limit' : 'Limite quotidienne' ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($roleLimits as $rl): ?>
                        <tr class="border-t border-lpc-border">
                            <td class="px-4 py-3 font-bold text-gray-900"><?= htmlspecialchars($rl['role_name']) ?></td>
                            <td class="px-4 py-2">
                                <input type="number" min="0" step="1"
                                       name="limits[<?= (int) $rl['role_id'] ?>]"
                                       value="<?= $rl['daily_limit'] !== null ? (int) $rl['daily_limit'] : '' ?>"
                                       placeholder="<?= $en ? 'unlimited' : 'illimité' ?>"
                                       class="w-32 border border-lpc-border rounded-xl px-3 py-1.5 text-sm font-mono">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="p-4 border-t border-lpc-border">
                    <button type="submit"
                            class="bg-lpc-dark hover:bg-[#004722] text-white px-5 py-2.5 rounded-xl font-bold shadow-md transition-all">
                        <?= $en ? 'Save limits' : 'Enregistrer les limites' ?>
                    </button>
                </div>
            </form>
        </section>

    </main>
</div>

<script src="<?= lpc_asset('/assets/js/modules/help-ai-settings.js') ?>" defer></script>
</body>
</html>
