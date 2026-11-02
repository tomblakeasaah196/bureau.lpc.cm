# Data wipe tool: clearing test data before real data starts

For JBS Praxis only. Built 2 Nov 2026 on the owner's decisions. The client tested bureau on mock data for two months; this tool clears that data module by module and restarts document numbering, so real data starts clean. **Never run it on the live site without the owner.**

## Who can open it
- **One account only:** the signed-in staff account whose email equals `WIPE_ADMIN_EMAIL` in the server's `.env`.
- **One address only:** the request must carry `?k=<WIPE_URL_KEY>`, also from `.env` (32 characters or more). The page's path is in this public repository, but the key isn't.
- **Everyone else** gets exactly what an unknown URL gets: `.htaccess` rewrites it to `index.php`, which shows the sign-in page, or sends a signed-in person to their dashboard. This covers admins, and everyone while either setting is empty. The page and every endpoint answer the same way.
- **Nowhere in the app:**
  - no permission or role, no menu entry, no ⌘K entry;
  - no line in the error log or the activity log;
  - the email address and the key are never written in code, docs or commits.

Code:
- `includes/classes/DataWipeAccess.php`: the gate, codes, the MD notice;
- `modules/admin/data_wipe.php`: the screen;
- `api/v1/data_wipe_controller.php`: the endpoints;
- `assets/js/modules/admin-data_wipe.js`: the screen's script.

## What a wipe does (`includes/classes/DataWipe.php`)
1. **Pick tables**, grouped by module.
2. **Plan.** The screen shows everything that goes with the pick, with row counts and why each table was added, plus balances before → after. It collects, until nothing new turns up:
   - rows linked by **foreign keys**, read from `information_schema` (so new tables are followed). A `NOT NULL` or `CASCADE` link means the row goes; an optional link is cleared instead (a delivery keeps going without its truck).
   - **links bureau keeps without a foreign key** (`DataWipeMap::softLinks()`):
     - the journal entries a document posted (`source_type` / `source_id`, and `PAY-<receipt>-…` for client payments) and their reversals;
     - treasury movements by reference (`PAY-`, `PAY-FRS-`, `PAY-ADV-`, `PAY-SAL-`, `REC-`, the expense reference), and both legs of a transfer;
     - stock movements by type, `reference_id`, product and time (CRE returns carry no reference, so they are matched on product, quantity, operator and the signature time);
     - price history by order / PO reference, rebates of a PO;
     - PDFs, signatures, signing codes;
     - notifications that name the record or link to its page;
     - activity lines (`audit_logs`) about it.
   - **whole documents:** when a line goes, its document goes too (`DataWipeMap::WHOLE`).
   - **owned rows:** a client's 411xxx and a supplier's 401xxx sub-account; the journal entry a document points at.
3. **Never wiped** (`DataWipeMap::PROTECTED`):
   - staff logins, users, roles, permissions, employees and their documents;
   - the chart of accounts (except the owned sub-accounts above) and reporting structure;
   - settings and preferences, treasury accounts and categories, budget envelopes and warehouses seeded by migrations;
   - **Courrier (`mail_*`), because it holds a copy of real mail**, and the help centre;
   - the tool's own log.

   A pick that would need any of these is refused, with the reason.
4. **Confirm:**
   - a 6-digit code emailed to `WIPE_ADMIN_EMAIL`: hashed, 10 minutes, 5 tries, single use, valid only for the pick it was sent for (none is sent while `MAIL_FROM` is unset);
   - a written reason;
   - the MD's account (matricule `LPC-MD-01`) must exist and be active.
5. **Run, in one transaction.** If the copy fails, nothing is wiped.
   1. Lock `data_wipe_lock`: one wipe at a time.
   2. Clear optional links.
   3. Put posted entries back to draft (the trigger refuses to delete a posted entry; a locked year is refused at plan time).
   4. Copy, then delete, children before parents.
   5. Recalculate stored figures (`DataWipeBalances`):
      - treasury balances: minus the wiped movements;
      - client wallets: advances, overpayments and wallet use of wiped payments;
      - invoice and order payment status, rebuilt from the payments left;
      - the empties ledger: what wiped signed BLs and CREs counted, and open BLs held in flight;
      - CUMP back to 0 where no reception is left.

      Stock quantities and the ledger follow by themselves, since they are sums of what is left.
   6. Restart `document_counters` of document tables that are now empty, so the next invoice is `FAC-…-0001`.
   7. Write the safety copy, then commit.
6. **After the commit:**
   - files of wiped rows that nothing else uses are deleted;
   - `AUTO_INCREMENT` of emptied tables restarts;
   - **the MD is told**, by email and in bureau's notifications: what was wiped, the counts, when, why, and by whom.

## The safety copy
- **What it is:** `wipe-<id>-<random>.tar.gz`, holding `restore.sql`, `files/` and `LISEZMOI.txt`.
- **Where it lives:** in `WIPE_COPY_DIR`, or by default `bureau_wipe_copies/` two levels above the site (the cPanel home, next to `public_html`). That is outside the web root, with mode 0600.
- **Who can get it:** only the same account can download it, from the "Effacements passés" list.
- **How long:** 30 days; the cron below deletes it after that. The wipe itself stays in `data_wipes`.
- **Restoring:** in phpMyAdmin, import `restore.sql` into bureau's database.
  - It undoes every step, last first: rows, cleared links, posted status, recalculated balances, counters, and `updated_at` stamps. Tested: after a wipe and a restore, every business table is identical to before.
  - Put the files back into the site folder, keeping their paths.
  - Do it **before** new data is entered; afterwards, new rows and restored rows would share ids and numbers.
  - The import adds one "created" line per restored invoice, payment and journal entry to the activity log (MySQL triggers).

## Server set-up (the owner)
1. **`.env`:** add two lines (back up `.env` first; edit in place):
   ```
   WIPE_ADMIN_EMAIL=<the email of the one account allowed>
   WIPE_URL_KEY=<40 random characters>
   ```
   The address of the screen is `https://bureau.lpc.cm/modules/admin/data_wipe.php?k=<WIPE_URL_KEY>`. Keep it out of the repo and out of shared chats.
2. **Cron** (cPanel → Cron Jobs, daily):
   ```
   20 1 * * * /usr/local/bin/ea-php83 /home/smartqaq/public_html/bureau.lpc.cm/scripts/cron/purge_data_wipe_copies.php >/dev/null 2>&1
   ```
3. **Migration** `355_data_wipe.sql` runs with the deploy.

## Tests
`scripts/tests/wipe/data_wipe.test.php` (CI job "Numbering and data wipe tests", MariaDB 10.11) checks:
- plans and the links above;
- protected tables, refusals;
- a wipe with bureau's posted-entry trigger;
- balances, counters, `AUTO_INCREMENT`, files;
- restore exactness;
- a failure half-way changing nothing;
- the lock, the gate, codes, the cron;
- that nothing is in a menu or the error log.

`scripts/tests/wipe/doc_counter.test.php` covers numbering.
