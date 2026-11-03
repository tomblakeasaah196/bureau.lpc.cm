# Data wipe tool: clearing test data before real data starts

For JBS Praxis only. Built 2 Nov 2026 on the owner's decisions. The client tested bureau on mock data for two months; this tool clears that data module by module and restarts document numbering, so real data starts clean. **Never run it on the live site without the owner.**

## Who can open it
- **One account only:** the signed-in staff account whose email equals `WIPE_ADMIN_EMAIL` in the server's `.env`.
- **One address only:** the request must carry `?k=<WIPE_URL_KEY>`, also from `.env` (32 characters or more). The page's path is in this public repository, but the key isn't.
- **Everyone else** gets exactly what an unknown URL gets: `.htaccess` rewrites it to `index.php`, which shows the sign-in page, or sends a signed-in person to their dashboard. This covers admins, and everyone while either setting is empty. The page and every endpoint answer the same way.
- **In that account's sidebar only:** "Effacer les données" ("Clear data" in English), under Courrier. The link carries the key, so nothing has to be bookmarked (owner's decision, 3 Nov 2026). It shows only while both settings are present and that account is active (`DataWipeAccess::menuLink()`).
- **Nowhere else in the app:**
  - no permission or role, no entry in any other menu, no ⌘K entry;
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
   - staff logins, users, roles, permissions, employees and their documents (one inactive account at a time can go from the "Un par un" tab, below);
   - the chart of accounts (except the owned sub-accounts above) and reporting structure;
   - settings and preferences, treasury accounts and categories, budget envelopes and warehouses seeded by migrations;
   - **Courrier (`mail_*`), because it holds a copy of real mail**, and the help centre;
   - the tool's own log.

   A pick that would need any of these is refused, with the reason.
4. **Confirm:**
   - a 6-digit code emailed to `WIPE_ADMIN_EMAIL`: hashed, 10 minutes, 5 tries, single use, valid only for the pick it was sent for (none is sent while `MAIL_FROM` is unset);
   - a written reason;
   - **the MD:** the active staff account with matricule `LPC-MD-01` (the owner's rule; bureau has no MD role, and there is no MD setting in `.env`).
     - The confirmation screen shows the MD's name and the address that will be told.
     - If no active account carries the matricule with a valid email, the screen says so, and **no code is sent and no wipe runs**: a wipe never happens without the MD being told.
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

## One by one (the second tab)
Added 3 Nov 2026 on the owner's decisions. The "Tables entières" tab is unchanged. "Un par un" deletes single records, with the same safeguards: the gate, a code tied to exactly what was picked (each record, and the choice made for each account), a written reason, the MD's notice, one transaction with the copy first, and the history.

Code: `DataWipe::searchRecords()`, `planRecords()`, `runRecords()`; `includes/classes/DataWipeStaff.php`; `assets/js/modules/admin-data_wipe-records.js`.

- **What can be picked, by number or name** (`DataWipeMap::RECORDS`):
  - documents: invoices, quotes, orders, BLs, purchase orders, receipts, expenses, CRE, inventories, recycling sales;
  - one client, supplier or product;
  - staff accounts, **inactive only**. Never the `WIPE_ADMIN_EMAIL` account, and never the MD's (LPC-MD-01), even inactive.
- **What goes with it:** the same closure as the whole-table tab, starting from those rows: foreign keys, links without one, whole documents, owned sub-accounts and entries, notifications, activity.
- **Numbering:** when a deleted document held its counter's last number, the counter steps back, so the next document takes that number again. One from the middle leaves a gap. The plan says which, in plain words, before the code is sent.
- **A staff account:**
  - **The person goes:** the login goes together with the employee record, contract, payslips, advances, profile, sessions, devices, preferences and notifications (`DataWipeMap::STAFF_OWN`), with what depends on them.
  - **Their records, two choices per account:**
    - **Passer à un autre compte** (hand over), with an active account picked. Every column naming the account is set to that account, activity lines included.
    - **Les effacer aussi** (wipe too):
      - a row the account created, logged, operated or drove goes; so does one that can't be kept without it (`NOT NULL`);
      - a row it only approved, validated or last edited stays, with its name cleared (owner, 3 Nov 2026);
      - its activity lines go;
      - a protected row that needs the name (a treasury account it created) refuses the wipe: hand that account over instead.
  - **Staff ids:** about 160 columns hold one, but only about 50 have a foreign key. `DataWipeStaff::columns()` finds them all, by foreign key or by name (`*_by`, `user_id`, `*_user_id`, `driver_id`, `operator_id`, `assigned_to`; integer columns only). A column added later is covered too.
  - **Courrier** (owner, 3 Nov 2026):
    - **Their own mailbox goes:** only bureau's copy; the mail stays on the mail server. The safety copy keeps the mailbox's settings, never its messages: the database drops the mail with the mailbox, so private mail is never in a downloadable file. After a restore, the mail comes back on the next sync once the login is active again.
    - **Access, signature, presence:** their access to shared mailboxes, their signature and their presence go.
    - **Their traces in shared mailboxes follow the choice.** Hand over: notes, assignments and "sent by" move to the picked account. Wipe: their notes go, and their name is cleared from assignments and "opened by". Shared mail itself is never touched.
- **No trace:** the activity lines bureau's triggers write during a one-by-one delete (an account's deletion, the hand-overs) are removed in the same transaction.

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
  - A personal mailbox removed with a staff account comes back without its mail; bureau copies it again from the mail server once that login is active.

## Server set-up (the owner)
1. **`.env`:** add two lines (back up `.env` first; edit in place):
   ```
   WIPE_ADMIN_EMAIL=<the email of the one account allowed>
   WIPE_URL_KEY=<40 random characters>
   ```
   Once both lines are in, "Effacer les données" appears under Courrier in that account's sidebar. Its address is `https://bureau.lpc.cm/modules/admin/data_wipe.php?k=<WIPE_URL_KEY>`. Keep the key out of the repo and out of shared chats.
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
- that only that account's sidebar links to the tool, no other menu does, and nothing goes to the error log.

`scripts/tests/wipe/data_wipe_records.test.php` (same job) covers the "Un par un" tab:
- search;
- plans of documents, clients and products;
- counters stepping back, and gaps;
- staff refusals;
- hand-over and wipe-too across declared and undeclared columns;
- Courrier;
- a run, then a restore;
- a failure half-way;
- codes tied to the choices.

Its model of bureau is `support/fixture_schema.sql` plus `support/fixture_staff.sql`.

`scripts/tests/wipe/doc_counter.test.php` covers numbering.
