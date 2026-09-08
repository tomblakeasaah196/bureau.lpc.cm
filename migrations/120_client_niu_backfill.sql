-- 120_client_niu_backfill.sql
--
-- Reconciles the two columns the clients table keeps a NIU in.
--
-- THE BUG THIS CLOSES
--   `clients` carries both `niu` (canonical) and `tax_id` (legacy). Which one
--   a client's number ended up in depended on the vintage of the form that
--   saved it, and the two halves of the app disagreed about where to look:
--
--     · assets/js/modules/crm-clients.js reads `client.niu || client.tax_id`,
--       so the CRM edit modal always showed a number that existed in either
--       column — the ERP looked correct;
--     · api/v1/get_invoice.php read only `c.niu`, so the same client printed
--       "NIU : —" on their invoice, and the document warned that the buyer's
--       NIU was missing.
--
--   Worse, api/v1/update_client.php set `tax_id = :tax_id` from a key the
--   client form has never posted. Saving an existing client — to fix a phone
--   number, say — blanked tax_id, so a NIU held only there was destroyed by
--   an unrelated edit. Both writers now put one value in both columns; this
--   migration repairs the rows that were saved before they did.
--
-- IDEMPOTENT
--   Both statements are UPDATEs guarded by their own WHERE clause. Re-running
--   this file matches nothing the second time. No schema change: the columns
--   and the idx_clients_niu index already exist (base schema + migration 040).

START TRANSACTION;

-- 1. The repair: rows whose number only ever reached the legacy column.
UPDATE clients
   SET niu = TRIM(tax_id)
 WHERE (niu IS NULL OR TRIM(niu) = '')
   AND tax_id IS NOT NULL
   AND TRIM(tax_id) <> '';

-- 2. The mirror: rows that have the canonical column filled and the legacy one
--    empty. fetch_clients.php still selects tax_id and the CRM modal still
--    falls back to it, so leaving it empty keeps a trap open for the next
--    reader that picks the wrong column.
UPDATE clients
   SET tax_id = TRIM(niu)
 WHERE (tax_id IS NULL OR TRIM(tax_id) = '')
   AND niu IS NOT NULL
   AND TRIM(niu) <> '';

COMMIT;

-- Verification:
--   -- must return 0 rows: no client may hold a NIU in one column only
--   SELECT id, lpc_code, name, niu, tax_id
--     FROM clients
--    WHERE COALESCE(NULLIF(TRIM(niu), ''), '') <> COALESCE(NULLIF(TRIM(tax_id), ''), '');
--
--   -- what a B2B invoice will print for this client
--   SELECT id, name, COALESCE(NULLIF(TRIM(niu), ''), NULLIF(TRIM(tax_id), '')) AS printed_niu
--     FROM clients WHERE type = 'B2B' ORDER BY name;
