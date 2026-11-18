#!/usr/bin/env python3
"""
scripts/tests/ledger_mapping.test.py

Guards the treasury → OHADA mapping without needing a database.

The bug this exists to prevent shipped for months:

    $ohada = ($row['type'] === 'bank') ? '521' : '571';

Every non-bank instrument fell into 571 Caisse, so mobile money was booked as
physical cash and the Trésorerie reconciliation could never balance. The map is
now data, and this test asserts three things about it:

  1. momo resolves to 552, never to 571.
  2. The map lives in one place, TreasuryAccount::OHADA_PARENT (it places the
     account's own sub-account: 5211, 5521, 5711). JournalPoster has no copy
     of it any more: since the owner's decision 12 (18/11/2026) it posts to
     the account's own sub-account and never falls back to a row under the
     parent (that is how CCA Bank would have posted to « LPC52111 »).
  3. Every OHADA parent the map can produce is actually seeded by a migration,
     because the sub-account cannot be created under an unseeded parent.

Run:  python3 scripts/tests/ledger_mapping.test.py
"""
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parents[2]

EXPECTED = {
    'bank':   '521',   # Banques
    'momo':   '552',   # Monnaie électronique — téléphone portable
    'caisse': '571',   # Caisse (physical cash only)
}

failures = []


def check(label, ok, detail=''):
    print(f"  {'PASS' if ok else 'FAIL'}  {label}{'' if ok else f' — {detail}'}")
    if not ok:
        failures.append(label)


def parse_php_map(path, const_name):
    """Pull a PHP `const NAME = [ 'k' => 'v', ... ];` into a dict."""
    src = path.read_text(encoding='utf-8')
    m = re.search(const_name + r'\s*=\s*\[(.*?)\];', src, re.S)
    if not m:
        return None
    return dict(re.findall(r"'([\w]+)'\s*=>\s*'([\w]+)'", m.group(1)))


print('─── treasury type → OHADA parent ───')

ta = parse_php_map(ROOT / 'includes/classes/TreasuryAccount.php', 'OHADA_PARENT')
jp_src = (ROOT / 'includes/classes/JournalPoster.php').read_text(encoding='utf-8')

check('TreasuryAccount::OHADA_PARENT parses', ta is not None)
check('JournalPoster keeps no second copy of the map', 'TREASURY_OHADA' not in jp_src)
m = re.search(r'function coaFromTreasury\(.*?\n    \}', jp_src, re.S)
check('coaFromTreasury never falls back to a row under the parent',
      bool(m) and 'coaByOhada' not in m.group(0) and 'coa_account_id' in m.group(0))

if ta:
    for kind, ohada in EXPECTED.items():
        check(f'{kind} → {ohada} (TreasuryAccount)', ta.get(kind) == ohada, f'got {ta.get(kind)}')
    check('mobile money is NOT booked as Caisse', ta.get('momo') != '571', 'momo maps to 571')

print('\n─── every parent the map can emit is seeded ───')

migrations = (ROOT / 'migrations').glob('*.sql')
seeded = set()
for f in migrations:
    body = f.read_text(encoding='utf-8')
    # INSERT INTO ohada_accounts ... ('552', 'name', 'asset')
    for block in re.findall(r'INSERT INTO ohada_accounts.*?;', body, re.S | re.I):
        seeded |= set(re.findall(r"\('(\d+)'", block))

for kind, ohada in sorted((ta or {}).items()):
    check(f"OHADA {ohada} ({kind}) seeded by a migration", ohada in seeded,
          f'{ohada} is in no INSERT INTO ohada_accounts')

# 6317 is not in the type map — it is the expense account the operator's
# commission is booked to by hand — but nothing can select it if it was never
# seeded either.
check('OHADA 6317 (MoMo fees) seeded', '6317' in seeded,
      'the fee account cannot be picked in the expense form')

print('\nAll assertions passed.' if not failures else f'\n{len(failures)} assertion(s) FAILED.')
sys.exit(0 if not failures else 1)
