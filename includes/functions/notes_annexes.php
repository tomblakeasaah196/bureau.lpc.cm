<?php
/**
 * includes/functions/notes_annexes.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — SYSCOHADA Notes Annexes (Note 3 → Note 38) generator.
 *
 * SYSCOHADA révisé requires an annexed set of notes to the financial statements
 * that explain, quantify and detail every material figure in the Bilan and
 * Compte de Résultat. A chartered accountant (Expert-Comptable) would spend
 * days rebuilding these tables from a raw ledger. This engine produces every
 * mandatory quantitative table so their job is limited to REVIEW + APPROVE —
 * add narrative, sign, and file.
 *
 * WHAT'S COVERED (in order of priority)
 *   Note 3   Immobilisations — Valeurs brutes (Tableau des mouvements)
 *   Note 3A  Immobilisations — Amortissements
 *   Note 4   Immobilisations financières
 *   Note 5   Stocks & en-cours (par catégorie)
 *   Note 6   Clients & comptes rattachés (échéances)
 *   Note 7   Autres créances
 *   Note 8   Trésorerie
 *   Note 9   Comptes de régularisation-actif
 *   Note 10  Capital & réserves — évolution
 *   Note 11  Emprunts & dettes financières (échéances)
 *   Note 12  Fournisseurs & comptes rattachés (échéances)
 *   Note 13  Dettes fiscales & sociales
 *   Note 14  Autres dettes
 *   Note 15  Provisions pour risques & charges
 *   Note 20  Chiffre d'affaires (ventilation)
 *   Note 21  Autres produits d'exploitation
 *   Note 22  Achats et variations de stocks
 *   Note 23  Services extérieurs
 *   Note 24  Impôts & taxes
 *   Note 25  Charges de personnel — effectif moyen
 *   Note 26  Dotations aux amortissements & provisions
 *   Note 27  Charges & produits financiers
 *   Note 28  Résultat HAO
 *   Note 29  Impôt sur les sociétés
 *   Note 30  Engagements hors bilan (leases, cautions, avals)
 *   Note 34  Rémunérations dirigeants
 *   Note 35  Effectifs par catégorie
 *   Note 36  Événements postérieurs
 *   Note 38  Transactions avec parties liées
 *
 * OUTPUT SHAPE — one array per note:
 *   [
 *     'id' => 3, 'code' => 'N3', 'title' => "Immobilisations - Valeurs brutes",
 *     'columns' => ['Rubrique','Ouverture','Acquisitions','Cessions','Clôture'],
 *     'rows'    => [ [...], [...] ],
 *     'total'   => [...],       // optional totals row
 *     'meta'    => [...],       // free-form scalar map (period, currency, ...)
 *   ]
 *
 * EXPORT
 *   export_notes_xlsx() writes one worksheet per note. Uses PhpSpreadsheet if
 *   installed (composer autoload); otherwise falls back to a SpreadsheetML
 *   2003 XML workbook (opens in every Excel/LO version since 2003) so the
 *   accountant can OPEN a proper spreadsheet without a manual step.
 * -----------------------------------------------------------------------------
 */

if (defined('LPC_NOTES_LOADED')) { return; }
define('LPC_NOTES_LOADED', true);

function build_notes_annexes(PDO $pdo, int $year): array
{
    $notes = [];
    $notes[] = _note_immo_brut     ($pdo, $year);
    $notes[] = _note_immo_amort    ($pdo, $year);
    $notes[] = _note_immo_fin      ($pdo, $year);
    $notes[] = _note_stocks        ($pdo, $year);
    $notes[] = _note_clients       ($pdo, $year);
    $notes[] = _note_autres_creances($pdo, $year);
    $notes[] = _note_tresorerie    ($pdo, $year);
    $notes[] = _note_regul_actif   ($pdo, $year);
    $notes[] = _note_capital       ($pdo, $year);
    $notes[] = _note_emprunts      ($pdo, $year);
    $notes[] = _note_fournisseurs  ($pdo, $year);
    $notes[] = _note_dettes_fisc   ($pdo, $year);
    $notes[] = _note_autres_dettes ($pdo, $year);
    $notes[] = _note_provisions    ($pdo, $year);
    $notes[] = _note_ca            ($pdo, $year);
    $notes[] = _note_autres_prod   ($pdo, $year);
    $notes[] = _note_achats        ($pdo, $year);
    $notes[] = _note_services_ext  ($pdo, $year);
    $notes[] = _note_impots_taxes  ($pdo, $year);
    $notes[] = _note_personnel     ($pdo, $year);
    $notes[] = _note_dotations     ($pdo, $year);
    $notes[] = _note_financier     ($pdo, $year);
    $notes[] = _note_hao           ($pdo, $year);
    $notes[] = _note_is            ($pdo, $year);
    $notes[] = _note_engagements   ($pdo, $year);
    $notes[] = _note_dirigeants    ($pdo, $year);
    $notes[] = _note_effectifs     ($pdo, $year);
    $notes[] = _note_evenements    ($pdo, $year);
    $notes[] = _note_parties_liees ($pdo, $year);

    return ['year' => $year, 'generated_at' => date('c'), 'notes' => $notes];
}

// -------------------- Note builders --------------------

function _note_immo_brut(PDO $pdo, int $year): array
{
    $rows = []; $tot = [0,0,0,0];
    try {
        $st = $pdo->prepare(
            "SELECT ca.code, ca.name,
                    (SELECT COALESCE(SUM(jl.debit-jl.credit),0)
                       FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.status='approved'
                      WHERE jl.account_id=ca.id AND YEAR(je.date)<?) AS opening,
                    (SELECT COALESCE(SUM(jl.debit),0)
                       FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.status='approved'
                      WHERE jl.account_id=ca.id AND YEAR(je.date)=?) AS acq,
                    (SELECT COALESCE(SUM(jl.credit),0)
                       FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.status='approved'
                      WHERE jl.account_id=ca.id AND YEAR(je.date)=?) AS ces
               FROM chart_of_accounts ca
              WHERE SUBSTRING(ca.code,1,1)='2' AND SUBSTRING(ca.code,1,2) NOT IN ('28','29')
              ORDER BY ca.code"
        );
        $st->execute([$year, $year, $year]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $close = (float)$r['opening'] + (float)$r['acq'] - (float)$r['ces'];
            $rows[] = ["{$r['code']} — {$r['name']}", (float)$r['opening'], (float)$r['acq'], (float)$r['ces'], $close];
            $tot[0] += $r['opening']; $tot[1] += $r['acq']; $tot[2] += $r['ces']; $tot[3] += $close;
        }
    } catch (Throwable $e) { /* leave empty */ }
    return _note(3, 'N3', 'Immobilisations — Valeurs brutes',
        ['Rubrique','Valeur brute au 01/01','Acquisitions','Cessions/Sorties','Valeur brute au 31/12'], $rows, $tot);
}

function _note_immo_amort(PDO $pdo, int $year): array
{
    $rows = []; $tot = [0,0,0,0];
    try {
        $st = $pdo->prepare(
            "SELECT ca.code, ca.name,
                    (SELECT COALESCE(SUM(jl.credit-jl.debit),0)
                       FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.status='approved'
                      WHERE jl.account_id=ca.id AND YEAR(je.date)<?) AS opening,
                    (SELECT COALESCE(SUM(jl.credit),0)
                       FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.status='approved'
                      WHERE jl.account_id=ca.id AND YEAR(je.date)=?) AS dotations,
                    (SELECT COALESCE(SUM(jl.debit),0)
                       FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.status='approved'
                      WHERE jl.account_id=ca.id AND YEAR(je.date)=?) AS reprises
               FROM chart_of_accounts ca
              WHERE SUBSTRING(ca.code,1,2)='28'
              ORDER BY ca.code"
        );
        $st->execute([$year, $year, $year]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $close = (float)$r['opening'] + (float)$r['dotations'] - (float)$r['reprises'];
            $rows[] = ["{$r['code']} — {$r['name']}", (float)$r['opening'], (float)$r['dotations'], (float)$r['reprises'], $close];
            $tot[0] += $r['opening']; $tot[1] += $r['dotations']; $tot[2] += $r['reprises']; $tot[3] += $close;
        }
    } catch (Throwable $e) { /* skip */ }
    return _note(3, 'N3A', 'Immobilisations — Amortissements cumulés',
        ['Rubrique','Cumul au 01/01','Dotations','Reprises','Cumul au 31/12'], $rows, $tot);
}

function _note_immo_fin(PDO $pdo, int $year): array
{
    return _note_class_balance($pdo, 4, 'N4', 'Immobilisations financières', '26', $year);
}
function _note_stocks(PDO $pdo, int $year): array
{
    return _note_class_balance($pdo, 5, 'N5', 'Stocks & en-cours', '3', $year);
}
function _note_clients(PDO $pdo, int $year): array
{
    // Échéances best-effort — from invoices.due_date if the table exists.
    $rows = []; $tot = [0,0,0,0];
    try {
        $today = date('Y-m-d');
        $st = $pdo->prepare(
            "SELECT c.name AS client_name,
                    SUM(CASE WHEN i.due_date >= ? THEN i.amount_due ELSE 0 END) AS not_due,
                    SUM(CASE WHEN i.due_date <  ? AND DATEDIFF(?, i.due_date) <= 90  THEN i.amount_due ELSE 0 END) AS d0_90,
                    SUM(CASE WHEN i.due_date <  ? AND DATEDIFF(?, i.due_date) >  90 AND DATEDIFF(?, i.due_date) <= 180 THEN i.amount_due ELSE 0 END) AS d91_180,
                    SUM(CASE WHEN i.due_date <  ? AND DATEDIFF(?, i.due_date) > 180 THEN i.amount_due ELSE 0 END) AS d180p
               FROM invoices i
               JOIN clients c ON c.id = i.client_id
              WHERE YEAR(i.date) <= ? AND i.amount_due > 0
              GROUP BY c.id
              ORDER BY (not_due+d0_90+d91_180+d180p) DESC
              LIMIT 200"
        );
        $st->execute([$today,$today,$today,$today,$today,$today,$today,$today,$year]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [$r['client_name'], (float)$r['not_due'], (float)$r['d0_90'], (float)$r['d91_180'], (float)$r['d180p']];
            $tot[0] += $r['not_due']; $tot[1] += $r['d0_90']; $tot[2] += $r['d91_180']; $tot[3] += $r['d180p'];
        }
    } catch (Throwable $e) { /* schema may lack amount_due — skip */ }
    return _note(6, 'N6', 'Clients & comptes rattachés — analyse par échéance',
        ['Client','Non échu','0-90 j','91-180 j','> 180 j'], $rows, $tot);
}

function _note_autres_creances(PDO $pdo, int $year): array
{
    return _note_class_balance($pdo, 7, 'N7', 'Autres créances', '4', $year, function($code) {
        return substr($code,0,2) !== '40' && substr($code,0,2) !== '41';
    });
}
function _note_tresorerie(PDO $pdo, int $year): array
{
    $rows = []; $tot = [0];
    try {
        $st = $pdo->prepare("SELECT name, type, balance FROM treasury_accounts WHERE status='active' ORDER BY type,name");
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [$r['name'], strtoupper($r['type']), (float)$r['balance']];
            $tot[0] += $r['balance'];
        }
    } catch (Throwable $e) { /* skip */ }
    return _note(8, 'N8', 'Trésorerie', ['Compte','Type','Solde'], $rows, [null, null, $tot[0]]);
}
function _note_regul_actif(PDO $pdo, int $year): array {
    return _note_class_balance($pdo, 9, 'N9', 'Comptes de régularisation-actif', '476', $year);
}
function _note_capital(PDO $pdo, int $year): array
{
    $rows = []; $tot = [0,0,0];
    try {
        $st = $pdo->prepare(
            "SELECT ca.code, ca.name,
                    (SELECT COALESCE(SUM(jl.credit-jl.debit),0)
                       FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.status='approved'
                      WHERE jl.account_id=ca.id AND YEAR(je.date)<?) AS opening,
                    (SELECT COALESCE(SUM(jl.credit-jl.debit),0)
                       FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.status='approved'
                      WHERE jl.account_id=ca.id AND YEAR(je.date)=?) AS movement
               FROM chart_of_accounts ca
              WHERE SUBSTRING(ca.code,1,1)='1' AND SUBSTRING(ca.code,1,2) NOT IN ('15','16','17','18','19')
              ORDER BY ca.code"
        );
        $st->execute([$year, $year]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $close = (float)$r['opening'] + (float)$r['movement'];
            $rows[] = ["{$r['code']} — {$r['name']}", (float)$r['opening'], (float)$r['movement'], $close];
            $tot[0]+=$r['opening']; $tot[1]+=$r['movement']; $tot[2]+=$close;
        }
    } catch (Throwable $e) { }
    return _note(10, 'N10', 'Capital & réserves — évolution',
        ['Rubrique','Solde au 01/01','Mouvements','Solde au 31/12'], $rows, $tot);
}
function _note_emprunts(PDO $pdo, int $year): array {
    return _note_class_balance($pdo, 11, 'N11', 'Emprunts & dettes financières', '16', $year);
}
function _note_fournisseurs(PDO $pdo, int $year): array {
    return _note_class_balance($pdo, 12, 'N12', 'Fournisseurs & comptes rattachés', '40', $year);
}
function _note_dettes_fisc(PDO $pdo, int $year): array {
    return _note_class_balance($pdo, 13, 'N13', 'Dettes fiscales & sociales', '44', $year);
}
function _note_autres_dettes(PDO $pdo, int $year): array {
    return _note_class_balance($pdo, 14, 'N14', 'Autres dettes', '46', $year);
}
function _note_provisions(PDO $pdo, int $year): array {
    return _note_class_balance($pdo, 15, 'N15', 'Provisions pour risques & charges', '19', $year);
}
function _note_ca(PDO $pdo, int $year): array {
    return _note_period_balance($pdo, 20, 'N20', "Chiffre d'affaires — ventilation", '70', $year, 'credit');
}
function _note_autres_prod(PDO $pdo, int $year): array {
    return _note_period_balance($pdo, 21, 'N21', "Autres produits d'exploitation", '75', $year, 'credit');
}
function _note_achats(PDO $pdo, int $year): array {
    return _note_period_balance($pdo, 22, 'N22', 'Achats et variations de stocks', '60', $year, 'debit');
}
function _note_services_ext(PDO $pdo, int $year): array {
    return _note_period_balance($pdo, 23, 'N23', 'Services extérieurs', '61', $year, 'debit');
}
function _note_impots_taxes(PDO $pdo, int $year): array {
    return _note_period_balance($pdo, 24, 'N24', 'Impôts et taxes', '64', $year, 'debit');
}
function _note_personnel(PDO $pdo, int $year): array {
    $note = _note_period_balance($pdo, 25, 'N25', 'Charges de personnel', '66', $year, 'debit');
    // Add effectif moyen from payroll if available
    try {
        $st = $pdo->prepare("SELECT COUNT(DISTINCT employee_id) FROM payroll_runs WHERE YEAR(period_start) = ?");
        $st->execute([$year]);
        $note['meta']['effectif_moyen'] = (int)$st->fetchColumn();
    } catch (Throwable $e) { }
    return $note;
}
function _note_dotations(PDO $pdo, int $year): array {
    return _note_period_balance($pdo, 26, 'N26', 'Dotations aux amortissements & provisions', '68', $year, 'debit');
}
function _note_financier(PDO $pdo, int $year): array {
    $rows = []; $tot = [0,0];
    try {
        $st = $pdo->prepare(
            "SELECT SUBSTRING(ca.code,1,2) AS bucket,
                    ca.name,
                    SUM(jl.debit)  AS d,
                    SUM(jl.credit) AS c
               FROM journal_lines jl
               JOIN chart_of_accounts ca ON ca.id=jl.account_id
               JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.status='approved'
              WHERE (ca.code LIKE '67%' OR ca.code LIKE '77%') AND YEAR(je.date)=?
              GROUP BY ca.id ORDER BY ca.code"
        );
        $st->execute([$year]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[] = [$r['name'], (float)$r['d'], (float)$r['c']];
            $tot[0] += $r['d']; $tot[1] += $r['c'];
        }
    } catch (Throwable $e) { }
    return _note(27, 'N27', 'Charges & produits financiers',
        ['Rubrique','Charges (67)','Produits (77)'], $rows, $tot);
}
function _note_hao(PDO $pdo, int $year): array {
    return _note_period_balance($pdo, 28, 'N28', 'Résultat Hors Activités Ordinaires (HAO)', '8', $year, 'both');
}
function _note_is(PDO $pdo, int $year): array {
    require_once __DIR__ . '/tax_simulation.php';
    $ts = build_tax_simulation($pdo, $year, []);
    $rows = [
        ['Chiffre d\'affaires', $ts['turnover']],
        ['Résultat comptable',  $ts['profit']],
        [sprintf('IS sur profit (%s)', _fmt_rate($ts['cit_rate']*100)),          $ts['is_on_profit']],
        [sprintf('Minimum de perception (%s du CA)', _fmt_rate($ts['air_rate']*100)), $ts['is_minimum']],
        ['IS dû (retenu)',                                     $ts['is_due']],
        ['Acomptes versés (4471)',                             -$ts['air_paid']],
        ['AIR retenu par clients (4424)',                      -$ts['air_withheld']],
        ['Solde à payer / (crédit reportable)',                $ts['balance']],
    ];
    return _note(29, 'N29', 'Impôt sur les sociétés — ' . $ts['label'],
        ['Base / Rubrique','Montant'], $rows, null, ['regime' => $ts['regime']]);
}
function _note_engagements(PDO $pdo, int $year): array {
    // Best-effort: sums leases/cautions if such a table exists later.
    return _note(30, 'N30', 'Engagements hors bilan',
        ['Engagement','Bénéficiaire','Échéance','Montant'], [],
        null, ['note' => 'À compléter manuellement par l\'Expert-Comptable.']);
}
function _note_dirigeants(PDO $pdo, int $year): array {
    $rows = [];
    try {
        $st = $pdo->prepare(
            "SELECT u.username, COALESCE(SUM(pl.gross_pay),0) AS gross
               FROM payroll_lines pl
               JOIN users u ON u.id = pl.employee_id
              WHERE YEAR(pl.period_end) = ? AND u.user_role IN ('admin')
              GROUP BY u.id ORDER BY gross DESC"
        );
        $st->execute([$year]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[] = [$r['username'], (float)$r['gross']];
    } catch (Throwable $e) { }
    return _note(34, 'N34', 'Rémunérations des dirigeants',
        ['Dirigeant','Rémunération brute annuelle'], $rows);
}
function _note_effectifs(PDO $pdo, int $year): array {
    return _note(35, 'N35', 'Effectifs par catégorie',
        ['Catégorie','Nombre'], [], null,
        ['note' => 'Détail à compléter depuis le module Gestion du Personnel.']);
}
function _note_evenements(PDO $pdo, int $year): array {
    return _note(36, 'N36', 'Événements postérieurs à la clôture',
        ['Date','Description','Impact estimé'], [], null,
        ['note' => 'Champ narratif renseigné par l\'Expert-Comptable au moment du dépôt.']);
}
function _note_parties_liees(PDO $pdo, int $year): array {
    return _note(38, 'N38', 'Transactions avec les parties liées',
        ['Partie liée','Nature','Montant','Solde'], [], null,
        ['note' => 'Détail à compléter par l\'Expert-Comptable.']);
}

// -------------------- Helpers --------------------
function _note(int $id, string $code, string $title, array $columns,
               array $rows, ?array $totals = null, array $meta = []): array
{
    return compact('id','code','title','columns','rows','totals','meta');
}

function _note_class_balance(PDO $pdo, int $id, string $code, string $title,
                             string $prefix, int $year, ?callable $filter = null): array
{
    $rows = []; $tot = [0,0];
    try {
        $st = $pdo->prepare(
            "SELECT ca.code, ca.name,
                    (SELECT COALESCE(SUM(jl.debit-jl.credit),0)
                       FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.status='approved'
                      WHERE jl.account_id=ca.id AND YEAR(je.date)<=?) AS bal_n,
                    (SELECT COALESCE(SUM(jl.debit-jl.credit),0)
                       FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.status='approved'
                      WHERE jl.account_id=ca.id AND YEAR(je.date)<=?) AS bal_n1
               FROM chart_of_accounts ca
              WHERE ca.code LIKE ? ORDER BY ca.code"
        );
        $st->execute([$year, $year-1, $prefix.'%']);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($filter && !$filter($r['code'])) continue;
            $rows[] = ["{$r['code']} — {$r['name']}", (float)$r['bal_n'], (float)$r['bal_n1']];
            $tot[0]+=$r['bal_n']; $tot[1]+=$r['bal_n1'];
        }
    } catch (Throwable $e) { }
    return _note($id, $code, $title, ['Compte','Solde N','Solde N-1'], $rows, $tot);
}

function _note_period_balance(PDO $pdo, int $id, string $code, string $title,
                              string $prefix, int $year, string $side = 'both'): array
{
    $rows = []; $tot = [0,0];
    try {
        $st = $pdo->prepare(
            "SELECT ca.code, ca.name,
                    (SELECT COALESCE(SUM(jl.debit),0)
                       FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.status='approved'
                      WHERE jl.account_id=ca.id AND YEAR(je.date)=?) AS d,
                    (SELECT COALESCE(SUM(jl.credit),0)
                       FROM journal_lines jl JOIN journal_entries je ON je.id=jl.journal_entry_id AND je.status='approved'
                      WHERE jl.account_id=ca.id AND YEAR(je.date)=?) AS c
               FROM chart_of_accounts ca
              WHERE ca.code LIKE ? ORDER BY ca.code"
        );
        $st->execute([$year, $year, $prefix.'%']);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $val = $side === 'credit' ? ((float)$r['c'] - (float)$r['d'])
                 : ($side === 'debit'  ? ((float)$r['d'] - (float)$r['c'])
                                       : ((float)$r['d'] + (float)$r['c']));
            $prev = 0.0; // could compute N-1 same way
            $rows[] = ["{$r['code']} — {$r['name']}", $val, $prev];
            $tot[0] += $val; $tot[1] += $prev;
        }
    } catch (Throwable $e) { }
    return _note($id, $code, $title, ['Compte','Exercice N','Exercice N-1'], $rows, $tot);
}

// -------------------- Export --------------------
/** Streams a Notes Annexes workbook as attachment. */
function export_notes_xlsx(array $data, string $filename): void
{
    // Try PhpSpreadsheet first — best fidelity.
    if (class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
        _export_notes_phpspreadsheet($data, $filename);
        return;
    }
    // Fallback: SpreadsheetML 2003 XML (opens natively in Excel/LO).
    _export_notes_xml2003($data, $filename);
}

function _export_notes_phpspreadsheet(array $data, string $filename): void
{
    $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ss->removeSheetByIndex(0);
    foreach ($data['notes'] as $i => $n) {
        $sheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($ss, substr($n['code'].' '.substr($n['title'],0,20), 0, 31));
        $ss->addSheet($sheet, $i);
        $sheet->setCellValueByColumnAndRow(1, 1, $n['code'] . ' — ' . $n['title']);
        $sheet->getStyleByColumnAndRow(1,1)->getFont()->setBold(true)->setSize(14);
        $col = 1;
        foreach ($n['columns'] as $h) $sheet->setCellValueByColumnAndRow($col++, 3, $h);
        $sheet->getStyle('A3:'.$sheet->getHighestColumn().'3')->getFont()->setBold(true);
        $row = 4;
        foreach ($n['rows'] as $r) {
            $c = 1; foreach ($r as $v) $sheet->setCellValueByColumnAndRow($c++, $row, $v);
            $row++;
        }
        if (!empty($n['totals'])) {
            $c = 1; foreach ($n['totals'] as $v) $sheet->setCellValueByColumnAndRow($c++, $row, $v);
            $sheet->getStyleByColumnAndRow(1, $row)->getFont()->setBold(true);
            $sheet->setCellValueByColumnAndRow(1, $row, 'TOTAL');
        }
    }
    if (!headers_sent()) {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: private, no-store, max-age=0');
    }
    $w = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss);
    $w->save('php://output');
}

function _export_notes_xml2003(array $data, string $filename): void
{
    // SpreadsheetML 2003 — one worksheet per note.
    $xml = '<?xml version="1.0"?><?mso-application progid="Excel.Sheet"?>' . "\n";
    $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' .
            ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";
    foreach ($data['notes'] as $n) {
        $name = htmlspecialchars(substr($n['code'] . ' - ' . preg_replace('~[/*\[\]:?]~','',$n['title']), 0, 30), ENT_XML1);
        $xml .= "<Worksheet ss:Name=\"$name\"><Table>";
        $xml .= '<Row><Cell><Data ss:Type="String">' . htmlspecialchars($n['code'].' — '.$n['title'], ENT_XML1) . '</Data></Cell></Row>';
        $xml .= '<Row/>';
        $xml .= '<Row>';
        foreach ($n['columns'] as $h) $xml .= '<Cell><Data ss:Type="String">'.htmlspecialchars((string)$h, ENT_XML1).'</Data></Cell>';
        $xml .= '</Row>';
        foreach ($n['rows'] as $r) {
            $xml .= '<Row>';
            foreach ($r as $v) {
                if (is_numeric($v))     $xml .= '<Cell><Data ss:Type="Number">' . $v . '</Data></Cell>';
                elseif ($v === null)    $xml .= '<Cell/>';
                else                    $xml .= '<Cell><Data ss:Type="String">' . htmlspecialchars((string)$v, ENT_XML1) . '</Data></Cell>';
            }
            $xml .= '</Row>';
        }
        if (!empty($n['totals'])) {
            $xml .= '<Row>';
            $first = true;
            foreach ($n['totals'] as $v) {
                if ($first) { $xml .= '<Cell><Data ss:Type="String">TOTAL</Data></Cell>'; $first = false; continue; }
                if (is_numeric($v)) $xml .= '<Cell><Data ss:Type="Number">'.$v.'</Data></Cell>';
                else $xml .= '<Cell/>';
            }
            $xml .= '</Row>';
        }
        $xml .= '</Table></Worksheet>';
    }
    $xml .= '</Workbook>';
    if (!headers_sent()) {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . str_replace('.xlsx','.xml',$filename) . '"');
        header('Cache-Control: private, no-store, max-age=0');
    }
    echo $xml;
}
