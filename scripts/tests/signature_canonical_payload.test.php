<?php
/**
 * scripts/tests/signature_canonical_payload.test.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — the guardrail that stops a future refactor from silently
 * invalidating every historical signature.
 *
 * WHY THIS TEST EXISTS
 *   DocumentSignature::hash($type, $doc) is sha256(JSON encoding of the
 *   canonical payload). If a well-intentioned edit to canonicalPayload_bl()
 *   reorders keys, renames a field, or bumps a rounding precision, EVERY
 *   BL signature ever recorded stops verifying — DocumentSignature::getActive()
 *   returns null because the recomputed hash no longer matches any stored
 *   value.
 *
 *   This file pins one fixed input JSON per document type to a known SHA-256.
 *   If the payload shape changes, the hash changes, the test fails loudly.
 *   The right response to a failing test is either:
 *     (a) undo the payload change, OR
 *     (b) bump the payload's 'v' key and add a NEW branch inside the
 *         payload function so historical rows keep verifying under the old
 *         version — see docs/SIGNATURES.md.
 *   NEVER update the expected hashes in this file without one of those.
 *
 * NO DATABASE REQUIRED
 *   DocumentSignature::hash() only reads the $doc array parameter — it does
 *   not touch the DB. That makes this test runnable from a bare CLI:
 *     php scripts/tests/signature_canonical_payload.test.php
 * -----------------------------------------------------------------------------
 */

// Bare require — no bootstrap needed, no session, no DB. The class file
// checks it isn't being hit directly, so we have to have a PHP_SELF that
// isn't its own basename. Running via CLI already satisfies that.
require_once __DIR__ . '/../../includes/classes/DocumentSignature.php';

// ----------------------------------------------------------------------------
// Fixed sample docs. Kept as literal arrays (not JSON files) so a diff on
// this file makes the shape change obvious in code review.
// ----------------------------------------------------------------------------

$samples = [
    'quote' => [
        'reference'   => 'DEV-2601-A001',
        'revision'    => 2,
        'date'        => '2026-01-15',
        'valid_until' => '2026-02-14',
        'client'      => [
            'name' => 'ACME Beverages SARL',
            'niu'  => 'M012345678901A',
            'rccm' => 'RC/DLA/2019/B/1234',
        ],
        'items' => [
            ['name' => 'Eau 20L bouchon', 'qty' => 100.0, 'unit_price' => 1500.0],
            ['name' => 'Eau 10L bouchon', 'qty' =>  50.0, 'unit_price' =>  800.0],
        ],
        'totals' => [
            'subtotal'    => 190000.00,
            'excise'      => 0.00,
            'tva_rate'    => 19.25,
            'tax'         => 36575.00,
            'grand_total' => 226575.00,
        ],
    ],

    'cre' => [
        'reference' => 'CRE-2601-42',
        'date'      => '2026-01-15',
        'client'    => ['name' => 'ACME Beverages SARL', 'niu' => 'M012345678901A'],
        'site_name' => 'Douala Bonabéri',
        'items' => [
            ['name' => 'Bouteille 20L bouchon',      'qty' => 30.0],
            ['name' => 'Bouteille 20L sans bouchon', 'qty' => 10.0],
        ],
    ],

    'bl' => [
        'reference' => 'BL-2601-107',
        'date'      => '2026-01-15',
        'client'    => ['name' => 'ACME Beverages SARL', 'niu' => 'M012345678901A'],
        'items' => [
            ['name' => 'Eau 20L bouchon', 'qty' => 100.0, 'delivered_qty' => 98.0,
             'returned_empty_qty' => 30.0, 'unit_price' => 1500.0],
        ],
        'totals'            => ['grand_total' => 147000.00],
        'payment_collected' => 100000.00,
        'observations'      => '2 bouteilles refusées pour cause de fuite.',
    ],

    'facture' => [
        'reference' => 'FAC-2601-88',
        'date'      => '2026-01-15',
        'due_date'  => '2026-02-14',
        'client'    => [
            'name' => 'ACME Beverages SARL',
            'niu'  => 'M012345678901A',
            'rccm' => 'RC/DLA/2019/B/1234',
        ],
        'items' => [
            ['name' => 'Eau 20L bouchon', 'qty' => 98.0, 'unit_price' => 1500.0],
        ],
        'totals' => [
            'subtotal'    => 147000.00,
            'excise'      => 0.00,
            'tva_rate'    => 19.25,
            'tax'         => 28297.50,
            'precompte'   => 0.00,
            'air'         => 0.00,
            'grand_total' => 175297.50,
            'net_payable' => 175297.50,
        ],
    ],

    'bon_commande' => [
        'reference' => 'BC-2601-19',
        'date'      => '2026-01-15',
        'client'    => ['name' => 'Fournisseur SARL', 'address' => 'Zone Industrielle, Douala'],
        'items' => [
            ['name' => 'Bouchons plastique', 'qty' => 10000.0, 'unit_price' => 25.0],
        ],
        'totals' => ['grand_total' => 250000.00],
    ],

    'payslip' => [
        'reference' => 'PAIE-202601-42',
        'date'      => '2026-01-01',
        'employee'  => ['name' => 'Jean Doe', 'cnps' => 'CN12345'],
        'breakdown' => [
            ['label' => 'Salaire de base', 'amount' => 200000.00, 'type' => 'earning'],
            ['label' => 'CNPS employé',    'amount' => -8400.00,  'type' => 'deduction'],
        ],
        // gross + net live on 'raw' per lpc_normalize_payslip()'s output shape.
        'raw' => ['gross_salary' => 200000.00, 'net_pay' => 191600.00],
    ],

    'contract' => [
        'reference' => 'CTR-2601-1',
        'date'      => '2026-01-15',
        'title'     => 'Contrat de fourniture d\'eau',
        'client'    => ['name' => 'ACME Beverages SARL', 'niu' => 'M012345678901A'],
        'body'      => "ARTICLE 1 — Objet\nLe fournisseur s'engage...",
    ],
];

// ----------------------------------------------------------------------------
// Expected hashes.
//
// FIRST-RUN BOOTSTRAP: this array is intentionally empty when the file is
// first shipped. Run the test once (from the project root):
//     php scripts/tests/signature_canonical_payload.test.php
// The runner will print the actual hashes for every sample and exit 1. Copy
// them into $expected below, commit, and from then on any drift trips a
// build failure with a clear diff.
//
// This bootstrap step exists because computing the hashes requires PHP's
// exact json_encode output (float precision, key ordering), which differs
// subtly enough from any other implementation that seeding them from
// another language would produce wrong values.
// ----------------------------------------------------------------------------

$expected = [
    // 'quote'        => '...',
    // 'cre'          => '...',
    // 'bl'           => '...',
    // 'facture'      => '...',
    // 'bon_commande' => '...',
    // 'payslip'      => '...',
    // 'contract'     => '...',
];

// ----------------------------------------------------------------------------
// Runner. On first invocation the expected hashes above are placeholders — the
// test prints the actual hashes so you can paste them in. On subsequent runs
// it asserts equality and exits non-zero on drift.
// ----------------------------------------------------------------------------

$fail = false;
$printFixup = false;
echo "signature_canonical_payload — DocumentSignature::hash() stability test\n";
echo str_repeat('-', 72) . "\n";

foreach ($samples as $type => $doc) {
    try {
        $actual = DocumentSignature::hash($type, $doc);
    } catch (Throwable $e) {
        echo sprintf("  %-14s  ERROR  %s\n", $type, $e->getMessage());
        $fail = true;
        continue;
    }

    $exp = $expected[$type] ?? '';
    if ($exp === '') {
        echo sprintf("  %-14s  MISSING EXPECTED    actual = %s\n", $type, $actual);
        $printFixup = true;
        $fail = true;
        continue;
    }

    if (hash_equals($exp, $actual)) {
        echo sprintf("  %-14s  OK    %s\n", $type, substr($actual, 0, 16) . '…');
    } else {
        echo sprintf("  %-14s  DRIFT expected %s\n                got      %s\n",
            $type, $exp, $actual);
        $printFixup = true;
        $fail = true;
    }
}

echo str_repeat('-', 72) . "\n";
if (!$fail) {
    echo "All canonical payloads hash to their expected values. \xE2\x9C\x93\n";
    exit(0);
}

echo "FAIL — the canonical payload shape has drifted.\n";
if ($printFixup) {
    echo "\nIf this drift is INTENTIONAL, bump 'v' in the affected canonical\n";
    echo "payload function AND update the expected hash below. Then update\n";
    echo "docs/SIGNATURES.md to note the version bump.\n";
    echo "\nCurrent actual hashes (copy into \$expected only after confirming intent):\n";
    foreach ($samples as $type => $doc) {
        try {
            echo sprintf("    '%s' => '%s',\n", $type, DocumentSignature::hash($type, $doc));
        } catch (Throwable $e) {
            /* already reported above */
        }
    }
}
exit(1);
