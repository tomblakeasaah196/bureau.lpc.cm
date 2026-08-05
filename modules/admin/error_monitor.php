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
    // Download the tail of EVERY log we read, not just ERROR_LOG_PATH — the
    // download has to match what the screen shows or it is useless for triage.
    $paths = ErrorMonitor::logSources();
    if (!$paths) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Log file not found or unreadable.";
        exit;
    }
    // Cap at 4 MB — enough for a triage, small enough not to freeze the browser.
    $bytes = (int) ($_GET['bytes'] ?? ErrorMonitor::MAX_TAIL_BYTES);
    if ($bytes < 1024) $bytes = ErrorMonitor::DEFAULT_TAIL_BYTES;
    if ($bytes > ErrorMonitor::MAX_TAIL_BYTES) $bytes = ErrorMonitor::MAX_TAIL_BYTES;

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="lpc-error-tail-' . date('Ymd-His') . '.log"');
    foreach ($paths as $path) {
        echo "\n===== " . $path . " =====\n";
        $size = filesize($path) ?: 0;
        $off  = max(0, $size - $bytes);
        $fh = fopen($path, 'rb');
        if ($fh) {
            fseek($fh, $off);
            fpassthru($fh);
            fclose($fh);
        }
    }
    exit;
}

// -----------------------------------------------------------------------------
// Load & aggregate.
// -----------------------------------------------------------------------------
$bytes    = (int) ($_GET['bytes'] ?? ErrorMonitor::DEFAULT_TAIL_BYTES);
if ($bytes < 4096) $bytes = ErrorMonitor::DEFAULT_TAIL_BYTES;
if ($bytes > ErrorMonitor::MAX_TAIL_BYTES) $bytes = ErrorMonitor::MAX_TAIL_BYTES;

// Read EVERY log we can find, not just ERROR_LOG_PATH. Errors raised before
// bootstrap has run (parse errors, fatals in files that skip bootstrap) land in
// cPanel's per-directory error_log and used to be invisible on this screen.
$logPath  = ErrorMonitor::logPath();
$sources  = ErrorMonitor::logSources();
$logExists= !empty($sources);
$logSize  = 0; foreach ($sources as $s) { $logSize += (int) @filesize($s); }

$entries  = $logExists ? ErrorMonitor::tailAll($bytes) : [];
$agg      = ErrorMonitor::aggregate($entries);
$hourly   = ErrorMonitor::hourlyBuckets($entries);
$total24h = 0; foreach ($hourly as $h) { $total24h += (int) $h['count']; }
$totalWin = count($entries);
$undated  = ErrorMonitor::undatedCount($entries);

// Extract available levels for the filter. Built from the UNFILTERED set so
// the dropdown doesn't shrink to whatever is currently selected.
$levels = [];
foreach ($agg as $row) { if (!in_array($row['level'], $levels, true)) $levels[] = $row['level']; }
sort($levels);

// -----------------------------------------------------------------------------
// Filter + paginate SERVER-SIDE.
//
// The old page rendered every signature at once and let JS hide rows. With a
// large window that is thousands of <details> nodes plus a <pre> sample for
// each — enough to lock up the tab. Filtering has to happen before the slice,
// otherwise "page 2" would mean "page 2 of the unfiltered list", which is why
// this is no longer done in the browser.
// -----------------------------------------------------------------------------
const ERR_PER_PAGE = 20;

$fLevel = trim((string) ($_GET['level'] ?? ''));
$fText  = mb_strtolower(trim((string) ($_GET['q'] ?? '')));

$filtered = $agg;
if ($fLevel !== '' || $fText !== '') {
    $filtered = array_values(array_filter($agg, function ($row) use ($fLevel, $fText) {
        if ($fLevel !== '' && $row['level'] !== $fLevel) return false;
        if ($fText !== '') {
            $hay = mb_strtolower(($row['message'] ?? '') . ' ' . ($row['file'] ?? ''));
            if (mb_strpos($hay, $fText) === false) return false;
        }
        return true;
    }));
}

// -----------------------------------------------------------------------------
// Sort order. aggregate() returns count-DESC, which buries a one-off fatal
// underneath a 46x warning — the newest and most serious error on the box can
// sit below the fold indefinitely. "Most recent" is the default now, because
// the point of this screen is to see errors as they happen.
// -----------------------------------------------------------------------------
$sort = ($_GET['sort'] ?? 'recent') === 'count' ? 'count' : 'recent';
if ($sort === 'recent') {
    usort($filtered, function ($a, $b) {
        $ta = ErrorMonitor::parseTs($a['last_seen']);
        $tb = ErrorMonitor::parseTs($b['last_seen']);
        $va = $ta ? $ta->getTimestamp() : 0;
        $vb = $tb ? $tb->getTimestamp() : 0;
        if ($va !== $vb) return $vb <=> $va;
        return $b['count'] <=> $a['count'];
    });
}

$totalGroups = count($filtered);
$totalPages  = max(1, (int) ceil($totalGroups / ERR_PER_PAGE));
$page        = max(1, (int) ($_GET['p'] ?? 1));
if ($page > $totalPages) $page = $totalPages;
$pageRows    = array_slice($filtered, ($page - 1) * ERR_PER_PAGE, ERR_PER_PAGE);

/** Rebuild the current query string with one key overridden. */
$qs = function (array $over = []) use ($bytes, $fLevel, $fText, $page, $sort) {
    $p = array_merge([
        'bytes' => $bytes,
        'level' => $fLevel,
        'q'     => $fText,
        'sort'  => $sort,
        'p'     => $page,
    ], $over);
    return '?' . http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== null));
};

$hourlyMax = 1;
foreach ($hourly as $h) { if ($h['count'] > $hourlyMax) $hourlyMax = $h['count']; }

/**
 * Render a raw log timestamp in the app's timezone.
 *
 * PHP writes these in UTC while the app runs in Africa/Douala, so the list was
 * showing "09:23 UTC" for an error the user experienced at 10:23. Now that the
 * 24h chart is keyed in local time, leaving the list in UTC would put two
 * different clocks on one screen.
 */
$fmtTs = function (string $raw): string {
    $d = ErrorMonitor::parseTs($raw);
    if (!$d) return $raw !== '' ? $raw : '—';
    return $d->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('d-M-Y H:i:s');
};

/**
 * Plain-text render of one aggregated error, meant to be pasted into an AI
 * chat / bug tracker. Built server-side and stashed as JSON inside each item
 * so the JS clipboard handler only has to join blobs — no DOM scraping, and
 * the format stays consistent across single-item and multi-select copies.
 *
 * Trims samples to two lines with a total length cap because pasting a 4 MB
 * stack blob is what people are actually trying to avoid.
 */
$copyBlob = function (array $row) use ($fmtTs): string {
    $lines   = [];
    $count   = (int) $row['count'];
    $level   = strtoupper((string) $row['level']);
    $msgHead = explode("\n", (string) $row['message'])[0] ?? '';
    $lines[] = "[{$level} × {$count}] {$msgHead}";

    if (!empty($row['file'])) {
        $lines[] = 'File: ' . $row['file'] . ($row['line'] ? ':' . $row['line'] : '');
    }
    $lines[] = 'First seen: ' . $fmtTs($row['first_seen']) . ' (' . date_default_timezone_get() . ')';
    $lines[] = 'Last seen:  ' . $fmtTs($row['last_seen'])  . ' (' . date_default_timezone_get() . ')';

    if (!empty($row['locations']) && count($row['locations']) > 1) {
        $lines[] = 'Locations:';
        foreach ($row['locations'] as $loc => $n) {
            $lines[] = '  ' . $loc . ' (' . (int) $n . '×)';
        }
    }
    if (!empty($row['sources'])) {
        $lines[] = 'Sources: ' . implode(', ', array_keys($row['sources']));
    }
    if (!empty($row['explain'])) {
        $exp = __t('ui.error_monitor.explain.' . $row['explain']);
        if ($exp !== '' && $exp !== ('ui.error_monitor.explain.' . $row['explain'])) {
            $lines[] = 'What it means: ' . $exp;
        }
    }
    if (!empty($row['samples'])) {
        $lines[] = 'Samples:';
        $shown = 0;
        foreach ($row['samples'] as $s) {
            if ($shown >= 2) break;
            // Cap each sample at 800 chars — enough for a PHP trace header,
            // small enough that ten of them still fit under any AI paste limit.
            $t = trim((string) $s);
            if (mb_strlen($t) > 800) $t = mb_substr($t, 0, 800) . ' …[truncated]';
            $lines[] = '  ' . str_replace("\n", "\n  ", $t);
            $shown++;
        }
    }
    return implode("\n", $lines);
};

// -----------------------------------------------------------------------------
// Logging health. "Why are there no errors after 10 a.m.?" is not answerable
// from the entry list alone — an empty tail looks identical whether the app was
// quiet or the log simply stopped being written (full disk, unwritable
// directory, a rotation job, or ini_set() being skipped in db.php because the
// target directory was not writable at boot). Surface the facts.
// -----------------------------------------------------------------------------
$health = [];
$effective = @ini_get('error_log');
foreach ($sources as $s) {
    $mtime = @filemtime($s);
    $health[] = [
        'path'      => $s,
        'size'      => (int) @filesize($s),
        'mtime'     => $mtime ?: null,
        'writable'  => @is_writable($s),
        'effective' => ($effective && realpath($effective) === realpath($s)),
    ];
}
$configuredDir      = $logPath ? dirname($logPath) : null;
$configuredDirOk    = $configuredDir ? (is_dir($configuredDir) && is_writable($configuredDir)) : false;
$configuredIsActive = $logPath && $effective && (realpath($effective) === realpath($logPath));
$newestWrite        = 0;
foreach ($health as $h) { if ($h['mtime'] && $h['mtime'] > $newestWrite) $newestWrite = $h['mtime']; }
$staleHours         = $newestWrite ? (time() - $newestWrite) / 3600 : null;
$diskFree           = @disk_free_space($configuredDir ?: __DIR__);
$diskTotal          = @disk_total_space($configuredDir ?: __DIR__);
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
.chip{background:var(--lpc-status-neutral-bg);border:1px solid var(--lpc-border);padding:.15rem .5rem;border-radius:9999px;font-size:.7rem;color:var(--lpc-status-neutral-fg)}
.bar{display:flex;align-items:flex-end;height:120px;gap:2px;padding:.5rem}
.bar > div{flex:1 1 0;background:linear-gradient(180deg,#8CC63F,#005A2B);border-radius:2px 2px 0 0;min-height:2px;position:relative;transition:opacity .15s}
.bar > div:hover{opacity:.8}
.bar > div span{position:absolute;bottom:100%;left:50%;transform:translateX(-50%);white-space:nowrap;font-size:.65rem;color:#fff;background:#000a;padding:2px 4px;border-radius:3px;pointer-events:none;opacity:0;transition:opacity .1s}
.bar > div:hover span{opacity:1}
.lvl{padding:.1rem .5rem;border-radius:.25rem;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em}
/* Severity chips read from the shell status tokens so admin's palette
   matches every other module's warning/danger and inverts in dark mode
   without a per-rule override in lpc-theme.css. .lvl-notice keeps its own
   teal hue because "info" is already used for filed / sent elsewhere and
   the two need to be distinguishable when they appear together. */
.lvl-fatal,.lvl-parse,.lvl-error{background:var(--lpc-status-danger-bg);color:var(--lpc-status-danger-fg)}
.lvl-warning{background:var(--lpc-status-warning-bg);color:var(--lpc-status-warning-fg)}
.lvl-notice,.lvl-deprecated,.lvl-strict{background:color-mix(in srgb,#06B6D4 18%,var(--lpc-surface));color:color-mix(in srgb,#06B6D4 60%,var(--lpc-ink))}
.lvl-info{background:var(--lpc-status-neutral-bg);color:var(--lpc-status-neutral-fg)}
.btn{padding:.4rem .9rem;border-radius:.5rem;font-weight:600;font-size:.8rem;cursor:pointer;transition:all .15s;display:inline-flex;align-items:center;gap:.4rem}
.btn-primary{background:linear-gradient(90deg,#005A2B,#8CC63F);color:#fff}
.btn-primary:hover{opacity:.92}
.btn-secondary{background:#fff;color:#374151;border:1px solid #D1D5DB}
.btn-secondary:hover{background:#F9FAFB}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.75rem}
details > summary{cursor:pointer;list-style:none}
details > summary::-webkit-details-marker{display:none}

/* Per-item copy + multi-select controls. Kept in the same idiom as the
   existing .btn / .chip pair rather than pulling in a new component. */
.err-check{margin-top:.35rem;cursor:pointer;accent-color:#005A2B}
.err-copy-btn{background:#fff;color:#374151;border:1px solid #D1D5DB;
              padding:.25rem .5rem;border-radius:.375rem;font-size:.7rem;
              cursor:pointer;transition:all .15s;display:inline-flex;
              align-items:center;gap:.25rem}
/* Hover ink was #8CC63F, which is 1.96:1 on the pale #F9FAFB below — the
   brand-light green is a chrome accent, not a text colour on white. Switch
   to --lpc-accent-on-light (#005A2B, 7.9:1) for the text; keep the light
   green as the border where it functions as an outline, not as text.
   .copied used #86EFAC as border (1.28:1 when read as fg on #DCFCE7 by
   scanners); the border stays decorative but the fg now clears AA cleanly. */
.err-copy-btn:hover{background:var(--lpc-surface-sunken);color:var(--lpc-accent-on-light);border-color:var(--lpc-brand-light)}
.err-copy-btn.copied{background:var(--lpc-status-success-bg);color:var(--lpc-status-success-fg);border-color:var(--lpc-status-success-fg)}
.err-multibar{display:none;align-items:center;justify-content:space-between;
              gap:.75rem;padding:.6rem .9rem;margin-bottom:.75rem;
              background:#F0FDF4;border:1px solid #BBF7D0;border-radius:.5rem}
.err-multibar.visible{display:flex}
.err-multibar .count{font-size:.8rem;color:#166534;font-weight:600}
.err-toast{position:fixed;bottom:1.5rem;left:50%;transform:translateX(-50%);
           background:#111827;color:#fff;padding:.6rem 1rem;border-radius:.5rem;
           font-size:.8rem;box-shadow:0 8px 24px rgba(0,0,0,.15);
           opacity:0;pointer-events:none;transition:opacity .15s;z-index:60}
.err-toast.visible{opacity:1}
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
        <form method="get" class="lpc-field">
            <!-- Carry the active filter across a window change, otherwise
                 resizing the read window silently resets the user's search. -->
            <input type="hidden" name="q" value="<?= htmlspecialchars($fLevel === '' && $fText === '' ? '' : (string) ($_GET['q'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="level" value="<?= htmlspecialchars($fLevel, ENT_QUOTES, 'UTF-8') ?>">
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
        <?php
        // Page help. Anchored to 'admin.error_monitor' by migration 073.
        // The button always renders (lpc_help_link's "always on" behaviour):
        // when no articles exist yet it opens the help centre index in a
        // muted state. Global CSS in lpc-shell.css pins .lpc-help-btn to the
        // extreme right of any .lpc-toolbar via margin-left:auto, so its
        // position here in source order is arbitrary — the flexbox does the
        // work. Kept last for readability.
        echo lpc_help_link('admin.error_monitor', $lang);
        ?>
    </div>

<main role="main" id="main" class="lpc-page">

    <!-- Intro paragraph. Moved inside main so it inherits page padding -->
    <p class="lpc-page-intro text-xs text-gray-500 mt-1 mb-4">
        <?= __t('ui.error_monitor.intro') ?>
    </p>

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
        <p class="text-gray-500 text-[10px] font-bold uppercase tracking-widest"><?= __t('ui.error_monitor.in_window') ?></p>
        <p class="text-2xl font-bold text-gray-900 mt-1"><?= number_format($totalWin, 0, ',', ' ') ?></p>
    </div>
    <div class="glass rounded-xl p-4">
        <p class="text-gray-500 text-[10px] font-bold uppercase tracking-widest"><?= __t('ui.taille_du_log') ?></p>
        <p class="text-2xl font-bold text-gray-900 mt-1"><?= $logSize >= 1048576 ? number_format($logSize / 1048576, 1, ',', ' ') . ' MB' : number_format($logSize / 1024, 0, ',', ' ') . ' KB' ?></p>
        <p class="text-[10px] text-gray-500 mt-1"><?= count($sources) ?> <?= __t('ui.error_monitor.sources') ?> · <?= number_format($bytes / 1024, 0, ',', ' ') ?> KB <?= __t('ui.fen_tre_lue') ?></p>
    </div>
</section>

<?php if ($undated > 0): ?>
<!-- Entries with no parseable timestamp can never land in an hourly bucket.
     Disclose them rather than letting the 24h KPI quietly disagree with the list. -->
<section class="glass rounded-xl p-3 mb-6 border-l-4 border-amber-400">
    <p class="text-xs text-amber-800"><?= htmlspecialchars(__t('ui.error_monitor.undated', ['n' => $undated]), ENT_QUOTES, 'UTF-8') ?></p>
</section>
<?php endif; ?>

<!-- Logging health. An empty tail looks the same whether the app was quiet or
     the log stopped being written; these facts tell the two apart. -->
<section class="glass rounded-xl p-3 mb-6">
    <p class="text-[10px] font-bold uppercase tracking-widest text-gray-500 mb-2"><?= __t('ui.error_monitor.health') ?></p>

    <?php if ($staleHours !== null && $staleHours >= 2): ?>
        <p class="text-xs text-amber-800 mb-2 font-semibold">
            <?= htmlspecialchars(__t('ui.error_monitor.stale', ['h' => number_format($staleHours, 1, ',', ' ')]), ENT_QUOTES, 'UTF-8') ?>
        </p>
    <?php endif; ?>

    <?php if ($logPath && !$configuredDirOk): ?>
        <p class="text-xs text-red-700 mb-2 font-semibold">
            <?= htmlspecialchars(__t('ui.error_monitor.dir_unwritable', ['d' => (string) $configuredDir]), ENT_QUOTES, 'UTF-8') ?>
        </p>
    <?php elseif ($logPath && !$configuredIsActive): ?>
        <p class="text-xs text-red-700 mb-2 font-semibold">
            <?= htmlspecialchars(__t('ui.error_monitor.not_active', ['want' => (string) $logPath, 'got' => (string) ($effective ?: '—')]), ENT_QUOTES, 'UTF-8') ?>
        </p>
    <?php endif; ?>

    <ul class="mono text-[11px] text-gray-600 space-y-0.5">
        <?php foreach ($health as $h): ?>
            <li>
                <?= $h['effective'] ? '● ' : '○ ' ?><?= htmlspecialchars($h['path'], ENT_QUOTES, 'UTF-8') ?>
                — <?= number_format($h['size'] / 1024, 0, ',', ' ') ?> KB
                <?php if ($h['mtime']): ?>
                    · <?= __t('ui.error_monitor.last_write') ?> <?= htmlspecialchars(date('d-M-Y H:i:s', $h['mtime']), ENT_QUOTES, 'UTF-8') ?>
                <?php endif; ?>
                <?php if (!$h['writable']): ?>
                    · <span class="text-red-600"><?= __t('ui.error_monitor.read_only') ?></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($diskFree && $diskTotal): ?>
        <p class="text-[11px] text-gray-500 mt-2 mono">
            <?= __t('ui.error_monitor.disk') ?>:
            <?= number_format($diskFree / 1073741824, 2, ',', ' ') ?> GB / <?= number_format($diskTotal / 1073741824, 2, ',', ' ') ?> GB
            <?php if ($diskTotal > 0 && ($diskFree / $diskTotal) < 0.02): ?>
                <span class="text-red-600 font-semibold">— <?= __t('ui.error_monitor.disk_full') ?></span>
            <?php endif; ?>
        </p>
    <?php endif; ?>
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
        <!-- Filtering is a GET round-trip now, not a JS hide/show: it has to be
             applied BEFORE the 20-per-page slice or "page 2" would page through
             the unfiltered list. -->
        <form method="get" id="err-filter" class="flex items-center gap-2">
            <input type="hidden" name="bytes" value="<?= (int) $bytes ?>">
            <input id="err-search" type="text" name="q"
                   value="<?= htmlspecialchars((string) ($_GET['q'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                   placeholder="<?= __t('ui.filtrer') ?>"
                   class="bg-gray-50 border border-gray-200 rounded px-3 py-1 text-xs text-gray-900 outline-none focus:ring-2 focus:ring-lpc-light w-56">
            <select id="err-level" name="level" class="bg-gray-50 border border-gray-200 rounded px-2 py-1 text-xs">
                <option value=""><?= __t('ui.tous_niveaux') ?></option>
                <?php foreach ($levels as $lv): ?>
                    <option value="<?= htmlspecialchars($lv, ENT_QUOTES, 'UTF-8') ?>" <?= $lv === $fLevel ? 'selected' : '' ?>>
                        <?= htmlspecialchars($lv, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-secondary"><?= __t('ui.filtrer') ?></button>
        </form>
        <div class="flex items-center gap-1">
            <a class="btn <?= $sort === 'recent' ? 'btn-primary' : 'btn-secondary' ?>"
               href="<?= htmlspecialchars($qs(['sort' => 'recent', 'p' => 1]), ENT_QUOTES, 'UTF-8') ?>"><?= __t('ui.error_monitor.sort_recent') ?></a>
            <a class="btn <?= $sort === 'count' ? 'btn-primary' : 'btn-secondary' ?>"
               href="<?= htmlspecialchars($qs(['sort' => 'count', 'p' => 1]), ENT_QUOTES, 'UTF-8') ?>"><?= __t('ui.error_monitor.sort_count') ?></a>
        </div>
    </div>

    <?php if (empty($pageRows)): ?>
        <p class="text-gray-500 text-sm py-8 text-center italic">
            <?= __t('ui.error_monitor.no_errors_in_window') ?>
        </p>
    <?php else: ?>
    <p class="text-[11px] text-gray-500 mb-3">
        <?= htmlspecialchars(__t('ui.error_monitor.showing', [
            'from'  => (($page - 1) * ERR_PER_PAGE) + 1,
            'to'    => min($page * ERR_PER_PAGE, $totalGroups),
            'total' => $totalGroups,
        ]), ENT_QUOTES, 'UTF-8') ?>
    </p>

    <!-- Multi-select copy bar. Hidden until at least one checkbox is ticked;
         populated by admin-error_monitor.js. Lives above the list so it stays
         visible while the user scrolls through 20 items. -->
    <div class="err-multibar" id="err-multibar" role="region" aria-live="polite">
        <span class="count">
            <span id="err-multi-count">0</span> erreur(s) sélectionnée(s)
        </span>
        <div class="flex items-center gap-2">
            <button type="button" id="err-multi-clear" class="btn btn-secondary">
                Effacer
            </button>
            <button type="button" id="err-multi-copy" class="btn btn-primary">
                📋 Copier la sélection
            </button>
        </div>
    </div>

    <div class="space-y-2" id="err-list">
        <?php foreach ($pageRows as $row):
            // Severity buckets, not raw level names — the level list is wider
            // than the CSS class list (Recoverable fatal error, Core warning…),
            // and unmapped levels used to render as unstyled grey.
            $cls     = 'lvl lvl-' . ErrorMonitor::severity($row['level']);
            $explain = $row['explain'] !== '' ? __t('ui.error_monitor.explain.' . $row['explain']) : ''; ?>
            <details class="err-item bg-white border border-gray-200 rounded-lg overflow-hidden"
                     data-level="<?= htmlspecialchars($row['level'], ENT_QUOTES, 'UTF-8') ?>"
                     data-text="<?= htmlspecialchars(mb_strtolower(($row['message'] ?? '') . ' ' . ($row['file'] ?? '')), ENT_QUOTES, 'UTF-8') ?>">
                <!-- Copy payload stashed as JSON. Kept out of a data-attribute
                     because the sample text can carry quotes, angle brackets
                     and newlines and would be a nightmare to escape twice. A
                     <script type="application/json"> is inert content — the
                     browser never executes it — and JS reads .textContent. -->
                <script type="application/json" class="err-copy-payload"><?php
                    echo json_encode($copyBlob($row), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
                ?></script>
                <summary class="p-3 flex items-start justify-between gap-3 hover:bg-gray-50">
                    <!-- Selection checkbox. stopPropagation so ticking it
                         doesn't also toggle the <details> open/closed. -->
                    <input type="checkbox" class="err-check"
                           title="Sélectionner pour copie groupée"
                           aria-label="Sélectionner cette erreur"
                           onclick="event.stopPropagation();">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="<?= $cls ?>"><?= htmlspecialchars($row['level'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="chip"><?= (int) $row['count'] ?>&times;</span>
                            <?php if ($row['file']): ?>
                                <span class="mono text-gray-500 truncate"><?= htmlspecialchars(basename($row['file']) . ($row['line'] ? ":{$row['line']}" : ''), ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                        </div>
                        <p class="text-sm text-gray-900/90 truncate"><?= htmlspecialchars(explode("\n", $row['message'])[0] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
                        <?php if ($explain !== ''): ?>
                            <p class="text-xs text-gray-600 mt-1"><?= htmlspecialchars($explain, ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>
                        <p class="text-[10px] text-gray-500 mt-1">
                            <?= __t('ui.de') ?>
                            <span class="mono"><?= htmlspecialchars($fmtTs($row['first_seen']), ENT_QUOTES, 'UTF-8') ?></span>
                            &nbsp;→&nbsp;
                            <span class="mono"><?= htmlspecialchars($fmtTs($row['last_seen']), ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="text-gray-400">(<?= htmlspecialchars(date_default_timezone_get(), ENT_QUOTES, 'UTF-8') ?>)</span>
                        </p>
                    </div>
                    <div class="flex items-center gap-1 shrink-0">
                        <!-- Per-family copy. Stops propagation so summary
                             doesn't toggle when the user just wants the text. -->
                        <button type="button" class="err-copy-btn"
                                title="Copier cette erreur (partageable avec un LLM)"
                                aria-label="Copier cette erreur"
                                onclick="event.preventDefault();event.stopPropagation();">
                            📋 <span class="err-copy-label">Copier</span>
                        </button>
                        <button type="button" onclick="event.preventDefault();event.stopPropagation();this.closest('.err-item').remove();window.__lpcErrMon&&window.__lpcErrMon.refresh();"
                                class="text-gray-500 hover:text-red-300 text-xs px-2 py-1 rounded border border-gray-200">
                            <?= __t('ui.cacher') ?>
                        </button>
                    </div>
                </summary>
                <div class="p-3 border-t border-gray-200 bg-gray-50">
                    <?php if ($explain !== ''): ?>
                        <p class="text-[10px] font-bold uppercase tracking-widest text-gray-500"><?= __t('ui.error_monitor.what_it_means') ?></p>
                        <p class="text-xs text-gray-700 mb-3"><?= htmlspecialchars($explain, ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                    <?php if (!empty($row['locations'])): ?>
                        <p class="text-[10px] font-bold uppercase tracking-widest text-gray-500"><?= __t('ui.error_monitor.locations') ?></p>
                        <ul class="mono text-xs text-gray-600 mb-2">
                            <?php foreach ($row['locations'] as $loc => $n): ?>
                                <li><?= htmlspecialchars($loc, ENT_QUOTES, 'UTF-8') ?> <span class="chip"><?= (int) $n ?>&times;</span></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php elseif ($row['file']): ?>
                        <p class="text-xs text-gray-500 mono mb-2"><?= htmlspecialchars($row['file'] . ($row['line'] ? ":{$row['line']}" : ''), ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                    <?php if (!empty($row['sources'])): ?>
                        <p class="text-[10px] text-gray-500 mb-2"><?= __t('ui.error_monitor.sources') ?>: <span class="mono"><?= htmlspecialchars(implode(', ', array_keys($row['sources'])), ENT_QUOTES, 'UTF-8') ?></span></p>
                    <?php endif; ?>
                    <?php foreach ($row['samples'] as $s): ?>
                        <pre class="text-[11px] text-gray-500 whitespace-pre-wrap break-words mono mt-2 p-2 bg-black/30 rounded"><?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8') ?></pre>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endforeach; ?>
    </div>

    <?php if ($totalPages > 1): ?>
    <nav class="flex items-center justify-center gap-1 mt-4 flex-wrap" aria-label="pagination">
        <a class="btn btn-secondary <?= $page <= 1 ? 'pointer-events-none opacity-40' : '' ?>"
           href="<?= htmlspecialchars($qs(['p' => max(1, $page - 1)]), ENT_QUOTES, 'UTF-8') ?>">&larr;</a>

        <?php
        // Window of page links around the current page, with ellipses, so a
        // 400-group log doesn't render 400 pagination links either.
        $from = max(1, $page - 2);
        $to   = min($totalPages, $page + 2);
        if ($from > 1): ?>
            <a class="btn btn-secondary" href="<?= htmlspecialchars($qs(['p' => 1]), ENT_QUOTES, 'UTF-8') ?>">1</a>
            <?php if ($from > 2): ?><span class="text-gray-400 px-1">…</span><?php endif; ?>
        <?php endif; ?>

        <?php for ($i = $from; $i <= $to; $i++): ?>
            <a class="btn <?= $i === $page ? 'btn-primary' : 'btn-secondary' ?>"
               href="<?= htmlspecialchars($qs(['p' => $i]), ENT_QUOTES, 'UTF-8') ?>"><?= $i ?></a>
        <?php endfor; ?>

        <?php if ($to < $totalPages): ?>
            <?php if ($to < $totalPages - 1): ?><span class="text-gray-400 px-1">…</span><?php endif; ?>
            <a class="btn btn-secondary" href="<?= htmlspecialchars($qs(['p' => $totalPages]), ENT_QUOTES, 'UTF-8') ?>"><?= $totalPages ?></a>
        <?php endif; ?>

        <a class="btn btn-secondary <?= $page >= $totalPages ? 'pointer-events-none opacity-40' : '' ?>"
           href="<?= htmlspecialchars($qs(['p' => min($totalPages, $page + 1)]), ENT_QUOTES, 'UTF-8') ?>">&rarr;</a>
    </nav>
    <?php endif; ?>
    <?php endif; ?>
</section>

<?php endif; ?>

</main>
</div>

<!-- Clipboard toast target. Lives outside main so scroll containers don't
     clip it. Styled to fade in/out from admin-error_monitor.js. -->
<div id="err-toast" class="err-toast" role="status" aria-live="polite"></div>

<script src="<?= lpc_asset('/assets/js/modules/admin-error_monitor.js') ?>" defer></script>

<?= Rbac::jsBootstrap() ?>
<script src="<?= lpc_asset('/assets/js/lpc-rbac.js') ?>" defer></script>
</body>
</html>
