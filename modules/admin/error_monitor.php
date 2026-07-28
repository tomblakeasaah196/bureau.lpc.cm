<?php
/**
 * modules/admin/error_monitor.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 5 · file-tail error monitor UI.
 *
 * Reads the last chunk of ERROR_LOG_PATH via ErrorMonitor, aggregates by
 * normalized signature, and renders:
 *   · 24h hourly bar chart (total errors per hour)
 *   · Filter by level
 *   · Grouped error list with count, level, message, file:line, first/last seen
 *   · "Télécharger le journal brut" download button
 *
 * Requires: admin.errors.view (granted only to admin by default, per
 * migration 018).
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/classes/ErrorMonitor.php';
Rbac::requirePermission('admin.errors.view');

$lang = lpc_i18n_current_lang();

// -----------------------------------------------------------------------------
// Sub-actions: download raw tail, invoked as ?do=download.
// -----------------------------------------------------------------------------
if (($_GET['do'] ?? '') === 'download') {
    $path = ErrorMonitor::logPath();
    if (!$path || !is_readable($path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Log file not found or unreadable.";
        exit;
    }
    // Cap at 4 MB — enough for a triage, small enough not to freeze the browser.
    $bytes = (int) ($_GET['bytes'] ?? ErrorMonitor::MAX_TAIL_BYTES);
    if ($bytes < 1024) $bytes = ErrorMonitor::DEFAULT_TAIL_BYTES;
    if ($bytes > ErrorMonitor::MAX_TAIL_BYTES) $bytes = ErrorMonitor::MAX_TAIL_BYTES;
    $size = filesize($path) ?: 0;
    $off  = max(0, $size - $bytes);
    $fh = fopen($path, 'rb');
    if ($fh) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="lpc-error-tail-' . date('Ymd-His') . '.log"');
        fseek($fh, $off);
        fpassthru($fh);
        fclose($fh);
    }
    exit;
}

// -----------------------------------------------------------------------------
// Load & aggregate.
// -----------------------------------------------------------------------------
$bytes    = (int) ($_GET['bytes'] ?? ErrorMonitor::DEFAULT_TAIL_BYTES);
if ($bytes < 4096) $bytes = ErrorMonitor::DEFAULT_TAIL_BYTES;
if ($bytes > ErrorMonitor::MAX_TAIL_BYTES) $bytes = ErrorMonitor::MAX_TAIL_BYTES;

$logPath  = ErrorMonitor::logPath();
$logExists= $logPath && is_file($logPath) && is_readable($logPath);
$logSize  = $logExists ? filesize($logPath) : 0;

$entries  = $logExists ? ErrorMonitor::tail($bytes) : [];
$agg      = ErrorMonitor::aggregate($entries);
$hourly   = ErrorMonitor::hourlyBuckets($entries);
$total24h = 0; foreach ($hourly as $h) { $total24h += (int) $h['count']; }

// Extract available levels for the filter.
$levels = [];
foreach ($agg as $row) { if (!in_array($row['level'], $levels, true)) $levels[] = $row['level']; }
sort($levels);

$hourlyMax = 1;
foreach ($hourly as $h) { if ($h['count'] > $hourlyMax) $hourlyMax = $h['count']; }
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= __t('ui.journal_d_erreurs') ?> | Bureau LPC</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
.glass{background:#fff;border:1px solid #E5E7EB;box-shadow:0 1px 2px rgba(16,24,40,.04)}
.chip{background:#F3F4F6;border:1px solid #E5E7EB;padding:.15rem .5rem;border-radius:9999px;font-size:.7rem;color:#374151}
.bar{display:flex;align-items:flex-end;height:120px;gap:2px;padding:.5rem}
.bar > div{flex:1 1 0;background:linear-gradient(180deg,#8CC63F,#005A2B);border-radius:2px 2px 0 0;min-height:2px;position:relative;transition:opacity .15s}
.bar > div:hover{opacity:.8}
.bar > div span{position:absolute;bottom:100%;left:50%;transform:translateX(-50%);white-space:nowrap;font-size:.65rem;color:#fff;background:#000a;padding:2px 4px;border-radius:3px;pointer-events:none;opacity:0;transition:opacity .1s}
.bar > div:hover span{opacity:1}
.lvl{padding:.1rem .5rem;border-radius:.25rem;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em}
.lvl-fatal,.lvl-parse,.lvl-error{background:#FEE2E2;color:#991B1B}
.lvl-warning{background:#FEF3C7;color:#92400E}
.lvl-notice,.lvl-deprecated,.lvl-strict{background:#CFFAFE;color:#155E75}
.lvl-info{background:#F1F5F9;color:#334155}
.btn{padding:.4rem .9rem;border-radius:.5rem;font-weight:600;font-size:.8rem;cursor:pointer;transition:all .15s;display:inline-flex;align-items:center;gap:.4rem}
.btn-primary{background:linear-gradient(90deg,#005A2B,#8CC63F);color:#fff}
.btn-primary:hover{opacity:.92}
.btn-secondary{background:#fff;color:#374151;border:1px solid #D1D5DB}
.btn-secondary:hover{background:#F9FAFB}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.75rem}
details > summary{cursor:pointer;list-style:none}
details > summary::-webkit-details-marker{display:none}
</style>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


<?php
require __DIR__ . '/../../includes/components/sidebar.php';
require __DIR__ . '/../../includes/components/topbar.php';
?>

<div id="lpc-shell-main">

    <div class="lpc-toolbar">
        <p class="lpc-toolbar-lead text-xs text-gray-500 max-w-2xl truncate"><?= __t('ui.error_monitor.intro') ?></p>
        <form method="get" class="lpc-field">
            <label for="em-bytes" class="lpc-field-label"><?= __t('ui.fen_tre') ?></label>
            <select id="em-bytes" name="bytes" onchange="this.form.submit()">
                <?php foreach ([16384, 65536, 262144, 1048576, 4194304] as $b): ?>
                    <option value="<?= $b ?>" <?= $b === $bytes ? 'selected' : '' ?>>
                        <?= $b >= 1048576 ? ($b / 1048576) . ' MB' : ($b / 1024) . ' KB' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <a href="?do=download&amp;bytes=<?= (int) $bytes ?>" class="btn btn-secondary">
            ⬇ <?= __t('ui.t_l_charger') ?>
        </a>
        <button onclick="location.reload()" class="btn btn-primary">
            ⟲ <?= __t('ui.actualiser') ?>
        </button>
    </div>

<main role="main" id="main" class="lpc-page">

<?php if (!$logExists): ?>
    <section class="glass rounded-xl p-6 mb-6 text-center">
        <p class="text-amber-700 font-semibold">
            <?= __t('ui.error_monitor.no_log') ?>
        </p>
        <p class="text-gray-500 text-xs mt-2">
            <?= __t('ui.v_rifiez_la_variable') ?>
            <code class="mono">ERROR_LOG_PATH</code>
            <?= __t('ui.dans_le_fichier_env_de_production') ?>
        </p>
    </section>
<?php else: ?>

<!-- KPI row -->
<section class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="glass rounded-xl p-4">
        <p class="text-gray-500 text-[10px] font-bold uppercase tracking-widest"><?= __t('ui.24h_total') ?></p>
        <p class="text-2xl font-bold text-gray-900 mt-1"><?= number_format($total24h, 0, ',', ' ') ?></p>
    </div>
    <div class="glass rounded-xl p-4">
        <p class="text-gray-500 text-[10px] font-bold uppercase tracking-widest"><?= __t('ui.signatures_uniques') ?></p>
        <p class="text-2xl font-bold text-gray-900 mt-1"><?= count($agg) ?></p>
    </div>
    <div class="glass rounded-xl p-4">
        <p class="text-gray-500 text-[10px] font-bold uppercase tracking-widest"><?= __t('ui.fen_tre_lue') ?></p>
        <p class="text-2xl font-bold text-gray-900 mt-1"><?= number_format($bytes / 1024, 0, ',', ' ') ?> KB</p>
    </div>
    <div class="glass rounded-xl p-4">
        <p class="text-gray-500 text-[10px] font-bold uppercase tracking-widest"><?= __t('ui.taille_du_log') ?></p>
        <p class="text-2xl font-bold text-gray-900 mt-1"><?= $logSize >= 1048576 ? number_format($logSize / 1048576, 1, ',', ' ') . ' MB' : number_format($logSize / 1024, 0, ',', ' ') . ' KB' ?></p>
    </div>
</section>

<!-- Hourly bar chart -->
<section class="glass rounded-xl p-4 mb-6">
    <div class="flex items-center justify-between mb-2">
        <h2 class="text-sm font-semibold uppercase tracking-wider text-gray-500">
            <?= __t('ui.erreurs_par_heure_24h') ?>
        </h2>
        <span class="chip"><?= count($hourly) ?> h</span>
    </div>
    <div class="bar">
        <?php foreach ($hourly as $h):
            $height = $hourlyMax > 0 ? max(2, (int) round(($h['count'] / $hourlyMax) * 100)) : 2; ?>
            <div style="height:<?= $height ?>%">
                <span><?= htmlspecialchars($h['hour'], ENT_QUOTES, 'UTF-8') ?>&nbsp;·&nbsp;<?= (int) $h['count'] ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- Grouped errors -->
<section class="glass rounded-xl p-4">
    <div class="flex items-center justify-between mb-3 gap-3 flex-wrap">
        <h2 class="text-sm font-semibold uppercase tracking-wider text-gray-500">
            <?= __t('ui.erreurs_regroup_es') ?>
        </h2>
        <div class="flex items-center gap-2">
            <input id="err-search" type="text"
                   placeholder="<?= __t('ui.filtrer') ?>"
                   class="bg-gray-50 border border-gray-200 rounded px-3 py-1 text-xs text-gray-900 outline-none focus:ring-2 focus:ring-lpc-light w-56">
            <select id="err-level" class="bg-gray-50 border border-gray-200 rounded px-2 py-1 text-xs">
                <option value=""><?= __t('ui.tous_niveaux') ?></option>
                <?php foreach ($levels as $lv): ?>
                    <option value="<?= htmlspecialchars($lv, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($lv, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <?php if (empty($agg)): ?>
        <p class="text-gray-500 text-sm py-8 text-center italic">
            <?= __t('ui.error_monitor.no_errors_in_window') ?>
        </p>
    <?php else: ?>
    <div class="space-y-2" id="err-list">
        <?php foreach ($agg as $row):
            $cls = 'lvl lvl-' . strtolower(preg_replace('/[^a-z]/i', '', $row['level'])); ?>
            <details class="err-item bg-white border border-gray-200 rounded-lg overflow-hidden"
                     data-level="<?= htmlspecialchars($row['level'], ENT_QUOTES, 'UTF-8') ?>"
                     data-text="<?= htmlspecialchars(mb_strtolower(($row['message'] ?? '') . ' ' . ($row['file'] ?? '')), ENT_QUOTES, 'UTF-8') ?>">
                <summary class="p-3 flex items-start justify-between gap-3 hover:bg-gray-50">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="<?= $cls ?>"><?= htmlspecialchars($row['level'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="chip"><?= (int) $row['count'] ?>&times;</span>
                            <?php if ($row['file']): ?>
                                <span class="mono text-gray-500 truncate"><?= htmlspecialchars(basename($row['file']) . ($row['line'] ? ":{$row['line']}" : ''), ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                        </div>
                        <p class="text-sm text-gray-900/90 truncate"><?= htmlspecialchars(explode("\n", $row['message'])[0] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="text-[10px] text-gray-500 mt-1">
                            <?= __t('ui.de') ?>
                            <span class="mono"><?= htmlspecialchars($row['first_seen'], ENT_QUOTES, 'UTF-8') ?></span>
                            &nbsp;→&nbsp;
                            <span class="mono"><?= htmlspecialchars($row['last_seen'], ENT_QUOTES, 'UTF-8') ?></span>
                        </p>
                    </div>
                    <button type="button" onclick="event.preventDefault();event.stopPropagation();this.closest('.err-item').remove();"
                            class="text-gray-500 hover:text-red-300 text-xs px-2 py-1 rounded border border-gray-200">
                        <?= __t('ui.cacher') ?>
                    </button>
                </summary>
                <div class="p-3 border-t border-gray-200 bg-gray-50">
                    <?php if ($row['file']): ?>
                        <p class="text-xs text-gray-500 mono mb-2"><?= htmlspecialchars($row['file'] . ($row['line'] ? ":{$row['line']}" : ''), ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                    <?php foreach ($row['samples'] as $s): ?>
                        <pre class="text-[11px] text-gray-500 whitespace-pre-wrap break-words mono mt-2 p-2 bg-black/30 rounded"><?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?></pre>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<?php endif; ?>

</main>
</div>

<script src="<?= lpc_asset('/assets/js/modules/admin-error_monitor.js') ?>" defer></script>

<?= Rbac::jsBootstrap() ?>
<script src="<?= lpc_asset('/assets/js/lpc-rbac.js') ?>" defer></script>
</body>
</html>
