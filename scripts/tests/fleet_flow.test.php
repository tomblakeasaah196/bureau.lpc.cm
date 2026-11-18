<?php
/**
 * scripts/tests/fleet_flow.test.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP · Flotte (18 Nov 2026, the training video QA,
 * docs/VIDEO_QA_FINDINGS.md §6). What the fleet controller must keep doing:
 *
 *   · one gate before any action: the action is read from where the handler
 *     reads it (query string for GET, JSON or multipart body for a write); a
 *     write with no action is refused; every write checks its permission and
 *     CSRF. A multipart POST used to arrive with a null action and skip both;
 *   · the Responsable des Opérations holds every fleet permission (133);
 *   · a daily assignment names an employee, not only a user;
 *   · a breakdown puts the vehicle « en panne »; a repair puts it back;
 *   · every fleet cost creates an UNPAID expense linked both ways (vehicle,
 *     source), and nothing is posted to the ledger from Flotte (the owner's
 *     answer 6: Finance validates and pays in Gestion des Dépenses).
 *
 * The flow itself was run by hand against the filming copy (MySQL). CI has no
 * database for this app: these are wiring checks.
 *
 * Run:  php scripts/tests/fleet_flow.test.php
 * -----------------------------------------------------------------------------
 */

$root = dirname(__DIR__, 2);
$fc  = file_get_contents($root . '/api/v1/fleet_controller.php');
$mig = file_get_contents($root . '/migrations/133_fleet_operations_and_expenses.sql');
$js  = file_get_contents($root . '/assets/js/modules/fleet-vehicles.js');
$pg  = file_get_contents($root . '/modules/fleet/vehicles.php');

$fail = 0;
function check(string $label, bool $ok): void
{
    global $fail;
    printf("  %s  %s\n", $ok ? 'PASS' : 'FAIL', $label);
    if (!$ok) $fail++;
}
/** The source of one `if ($action === 'name')` block, up to the next one. */
function action_block(string $src, string $name): string
{
    $a = strpos($src, "if (\$action === '{$name}'");
    if ($a === false) return '';
    $b = strpos($src, "\n    if (\$action === '", $a + 10);
    return substr($src, $a, ($b === false ? strlen($src) : $b) - $a);
}

echo "Flotte: one gate before any action\n";
$gate = substr($fc, 0, (int) strpos($fc, '// [GET] READ OPERATIONS'));
check('a write reads its action from the JSON body or the multipart form',
      str_contains($gate, "\$payload = is_array(\$json) ? \$json : (!empty(\$_POST) ? \$_POST : null);")
      && str_contains($gate, "\$action = is_array(\$payload) ? (\$payload['action'] ?? null) : null;"));
check('an unknown or missing action is refused (400)',
      str_contains($gate, "if (!is_string(\$action) || !isset(\$allowed[\$action]))"));
check('every action checks its permission', str_contains($gate, 'Rbac::requirePermission($allowed[$action]);'));
check('every write checks CSRF', (bool) preg_match('/if \(\$isWrite\) \{.*?Csrf::requireValid\(\);/s', $gate));
check('the write handlers use the gated action, not their own read of the body',
      !preg_match('/\$action\s*=\s*\$payload\[/', substr($fc, strlen($gate))));
foreach (['create' => 'fleet.vehicles.create', 'update' => 'fleet.vehicles.edit',
          'create_assignment' => 'fleet.vehicles.assign', 'log_fuel' => 'fleet.fuel.log',
          'log_maintenance' => 'fleet.vehicles.maintenance', 'report_breakdown' => 'fleet.breakdown.report'] as $a => $p) {
    check("write '$a' needs $p", (bool) preg_match("/'$a'\s*=>\s*'" . preg_quote($p, '/') . "'/", $gate));
}
check('a GET with no action is a read that needs fleet.vehicles.view',
      str_contains($gate, "\$action = \$_GET['action'] ?? 'read';") && str_contains($gate, "'read'           => 'fleet.vehicles.view'"));

echo "\nOperations runs the fleet\n";
foreach (['fleet.vehicles.create', 'fleet.vehicles.edit', 'fleet.vehicles.assign', 'fleet.vehicles.maintenance',
          'fleet.fuel.log', 'fleet.breakdown.report'] as $p) {
    check("133 grants $p to operations", str_contains($mig, "'$p'") && str_contains($mig, "WHERE r.name = 'operations'"));
}
check('the page does not ask for the driver list on load (403 for view-only roles)',
      !preg_match('/window\.onload\s*=\s*\(\)\s*=>\s*\{[^}]*fetchDriversList\(\)/s', $js));
check('the page shows each button only to who may use it',
      str_contains($pg, "<?php if (\$can['create']): ?>") && str_contains($pg, "<?php if (\$can['fuel']): ?>")
      && str_contains($pg, "<?php if (\$can['breakdown']): ?>"));

echo "\nA driver is an employee\n";
$assign = action_block($fc, 'create_assignment');
check('the driver list is the active staff', str_contains(action_block($fc, 'get_drivers'), 'FROM employees'));
check('an assignment stores employee_id', str_contains($assign, 'INSERT INTO vehicle_assignments (vehicle_id, driver_id, employee_id'));
check('133 adds vehicle_assignments.employee_id and frees driver_id',
      str_contains($mig, 'ADD COLUMN employee_id INT NULL') && str_contains($mig, 'MODIFY COLUMN driver_id INT NULL'));
check('opening the window keeps today as the date (reset() emptied it)',
      (bool) preg_match("/form-assign'\)\.reset\(\);\s*\n(?:\s*\/\/[^\n]*\n)*\s*document\.getElementById\('assign_date'\)\.valueAsDate = new Date\(\);/", $js));

echo "\nBreakdown and repair\n";
$brk = action_block($fc, 'report_breakdown');
$mnt = action_block($fc, 'log_maintenance');
check('a breakdown sets the vehicle en panne', str_contains($brk, "SET status = 'repair'"));
check("a breakdown is logged as 'breakdown'", str_contains($brk, "'breakdown'"));
check('a repair puts a vehicle en panne back in service',
      str_contains($mnt, "UPDATE vehicles SET status = 'active' WHERE id = ? AND status = 'repair'"));
check("log_maintenance cannot write 'breakdown'", str_contains($mnt, "['routine', 'repair', 'insurance', 'fine', 'other']"));

echo "\nFleet costs reach Gestion des Dépenses as requests\n";
$req = substr($fc, (int) strpos($fc, 'function fleet_expense_request'), 1800);
check('the request is saved unpaid, with no treasury account', str_contains($req, "'unpaid'") && !str_contains($req, 'treasury_account_id'));
check('the request carries the vehicle and its source', str_contains($req, 'vehicle_id, source_type, source_id'));
check('fuel creates a « Carburant & Transport » request linked back',
      str_contains(action_block($fc, 'log_fuel'), "fleet_expense_request(\$pdo, 'fuel'")
      && str_contains(action_block($fc, 'log_fuel'), 'UPDATE fuel_logs SET expense_id'));
check('a costed intervention creates a request linked back',
      str_contains($mnt, 'if ($cost > 0)') && str_contains($mnt, 'UPDATE vehicle_maintenance SET expense_id'));
check("fuel goes to 'logistique' (6022), repairs to 'entretien_vehicule' (6225)",
      str_contains($fc, "'fuel'      => 'logistique'") && str_contains($fc, "'repair'    => 'entretien_vehicule'"));
check('nothing is posted to the ledger from Flotte', !str_contains($fc, 'JournalPoster') && !str_contains($fc, 'journal_entries'));
check('133 links fuel_logs, vehicle_maintenance and expenses',
      str_contains($mig, 'fk_fuel_expense') && str_contains($mig, 'fk_vm_expense') && str_contains($mig, 'fk_expense_vehicle'));
check("133 creates the 'entretien_vehicule' category on 6225",
      str_contains($mig, "'entretien_vehicule'") && str_contains($mig, "code = '6225'"));

echo "\n" . ($fail === 0 ? "All fleet checks passed.\n" : "$fail fleet check(s) FAILED.\n");
exit($fail === 0 ? 0 : 1);
