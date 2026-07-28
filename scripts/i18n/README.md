# i18n maintenance scripts

Run from the repo root. No PHP required.

| script | what it answers |
|---|---|
| `python3 scripts/i18n/audit.py` | Which accented French strings are still hardcoded? |
| `python3 scripts/i18n/sweep.py` | Which *unaccented* UI strings are still hardcoded? (catches `Ventes & Dispatch`, `Client *`) |
| `python3 scripts/i18n/check.py` | Do all PHP files still parse structurally? |
| `python3 scripts/i18n/verify.py` | FR/EN key parity, duplicate keys, unresolved `__t()` keys, French left in EN. |

`exclude.json` lists strings that are deliberately never translated: OHADA/tax
acronyms (CNPS, IRPP, AIR), currency codes, proper nouns (LA PETITE COUR,
Prometal S.A.) and technical identifiers.

Both `audit.py` and `sweep.py` should report zero outside `exclude.json`.
Run `verify.py` before every release; it exits non-zero on failure so it can
be wired into CI.

## Adding a new page

1. Resolve the language with `$lang = lpc_i18n_current_lang();` — **never**
   from `$_GET['lang']` directly. Reading the query string alone was the bug
   that made pages render half-French: `__t()` follows session + cookie, so a
   page reached without `?lang=` disagreed with its own ternaries.
2. Wrap display text in `__t('key')`, add the key to **both** the `fr` and `en`
   blocks of `includes/config/i18n_dictionaries.php`.
3. Run `audit.py`, `sweep.py` and `verify.py`.
