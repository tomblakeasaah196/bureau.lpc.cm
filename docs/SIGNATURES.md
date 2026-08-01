# Signatures — universal spec

> **Every document in Bureau LPC that requires a signature MUST use the system
> described in this file. There is no per-document signature scheme. There
> never will be.**
>
> If a future feature ships a new document type that needs a signature and
> does not appear in this file, that is a bug in the pull request.

Last consolidated: migration `050_signatures_universal.sql`.

---

## The two parties

Every signature belongs to exactly one of these parties, and the two never
mix on the same signature row.

### External — the counterparty

Who: the customer, the recipient, the supplier — the party opposite LPC.

How they sign: on their phone, from a token link. They draw a signature with
their finger, type their name / role / phone, tap **Confirmer et signer**.

What is recorded: a Base64 PNG of the drawing, the typed identity triple,
their IP, the exact document figures they signed against.

What is NOT recorded: any OTP. The old Sprint 7C phone-OTP gate was removed
in this migration — the token link IS the credential.

### Internal — LPC staff

Who: an LPC staff member, already authenticated in the ERP.

How they sign: from inside the ERP, with a "Signer" button on the document's
detail page. No drawing pad, no image, no phone.

What is recorded: the signer's identity as it exists in the database at
signing time (name + role from `UserProfile`, resolved from the session
— **never** from a request field, so nobody can sign as "Le Directeur
Général" by typing it into a form), their IP + user agent, the exact
document figures they signed against.

Same document can have BOTH: a BL customer signs externally on their phone,
then an authorised LPC staffer signs it internally from the ERP. Both rows
live in `document_signatures` distinguished by the `party` column. Both get
their own QR on the printed PDF. Both are verifiable at `/verify/{token}`.

---

## Document types

The seven types this system covers today. Adding an eighth requires a
migration; see the checklist further down.

| Slug           | Internal signature | External signature | Notes                                          |
|----------------|--------------------|--------------------|------------------------------------------------|
| `quote`        | ✅ (existing)      | ✅ (new)           | Proposition commerciale · Sprint 10 blueprint  |
| `cre`          | ✅                 | ✅                 | Certificat de restitution d'emballages         |
| `bl`           | ✅                 | ✅                 | Bon de livraison                               |
| `facture`      | ✅                 | ✅                 | Invoice                                        |
| `bon_commande` | ✅                 | ✅                 | Purchase order to supplier                     |
| `payslip`      | ✅                 | ✅                 | Bulletin de paie                               |
| `contract`     | ✅                 | ✅                 | Placeholder for the contracts module           |

All fourteen slots are supported in code today. Whether a specific document
type actually invites an external signature is a *permission* decision, not
a schema one — turn `signatures.{type}.external.sign` on for the roles that
should have that authority and the "invite to sign" button appears.

---

## Storage

One table, `document_signatures`, defined in migrations 048 and 050.

Every signature row carries:

- `document_type` + `document_id` — identifies the document
- `party` — `internal` or `external`
- `content_hash` — `sha256` of the canonical payload at signing time
- `verify_token` — 40 random hex chars, the only key `/verify/…` accepts
- `signed_at`, `ip`, `user_agent`
- `revoked_at`, `revoked_by`, `revoke_reason` (nullable)

Internal-only columns: `signer_user_id`, `signer_name`, `signer_role`.

External-only columns: `signature_image_b64` (Base64 PNG data URL of the
drawn pad — stored inline per Feb-era behaviour), `signatory_name`,
`signatory_role`, `signatory_phone`.

## Why a signature can go stale

`content_hash` is `sha256(DocumentSignature::canonicalPayload($type, $doc))`
at signing time. Every renderer recomputes that same hash from the document
as it stands right now, and only surfaces a signature row if the two match.

Edit a price after signing and the old row stops being active — the PDF
falls back to unsigned rather than stamping a figure nobody actually
attested to. The row itself is never overwritten, so
"who signed which exact figures, and when" survives every later edit or
revocation.

---

## Canonical payload

Per-type functions in `DocumentSignature.php`, one per slug:

- `canonicalPayload_quote($doc)`
- `canonicalPayload_cre($doc)`
- `canonicalPayload_bl($doc)`
- `canonicalPayload_facture($doc)`
- `canonicalPayload_bon_commande($doc)`
- `canonicalPayload_payslip($doc)`
- `canonicalPayload_contract($doc)`

Each returns an associative array with a `v` version key. **Order and key
names are load-bearing.** Changing a field name silently invalidates every
signature that was ever hashed against the old shape. If the payload has to
change: bump `v`, add a NEW branch inside the payload function for the new
version, keep the old branch reachable.

`scripts/tests/signature_canonical_payload.test.php` pins one fixed input
per type to a known sha256 — that test is what stops a future refactor from
breaking every signature at once. Run it before merging any change to
`DocumentSignature.php`.

---

## Permissions

Fourteen rows in the `permissions` table — one per (type, party) pair:

    signatures.{type}.{party}.sign

Example: `signatures.cre.internal.sign`, `signatures.bl.external.sign`.

Seeded to `admin` only. Any other role that should sign a given (type,
party) gets the permission granted explicitly from
`Administration → Rôles & Permissions`. External signatures don't check
permissions at signing time — the token is the credential — but the same
permission gates who can INVITE the counterparty to sign in the operator
UI.

---

## Endpoints

All signature actions go through one controller.

```
POST /api/v1/signatures_controller.php?action=sign_internal
POST /api/v1/signatures_controller.php?action=sign_external
POST /api/v1/signatures_controller.php?action=revoke
GET  /api/v1/signatures_controller.php?action=status
```

The old per-doc-type endpoints (`cre_controller.php?action=sign_cre`,
`sales_controller.php?action=sign_bl`, `proposal_signature_controller.php`)
are kept as thin proxies or deprecation stubs — see each file's docblock.
No new code should call them.

`sign_external` also fires the doc-type's post-signature side effects
(delivery status flip, empties ledger updates, inventory movements,
sales-order finalisation) via `includes/functions/signature_side_effects.php`.
Everything happens in one transaction so a signature is never recorded
without its state change, or vice versa.

---

## Frontend

Three shared pieces. None of them should ever be duplicated per document
type — that duplication is exactly what this system replaced.

**1. External signing page** (customer, on their phone)

`public/documents/sign_cre.php` and `public/documents/sign_bl.php`, both
driven by `assets/js/modules/signature-universal.js`. A new external-signing
page follows the same pattern: identity fields with ids `sig_name` /
`sig_role` / `sig_phone`, a `#signature-pad` canvas, a `#sig_submit_btn`,
and a `#lpc-page-data` block carrying `{token, docType, csrf, csrfField}`.

**2. Internal signing button** (LPC staff, inside the ERP)

`includes/components/signature_sign_button.php`. Two lines in the host
page's nav:

```php
<?php
$sign_btn_type  = 'facture';
$sign_btn_token = (string) ($_GET['token'] ?? '');
require __DIR__ . '/../../includes/components/signature_sign_button.php';
?>
```

It emits the button, the modal, a `#lpc-sign-data` JSON block and the
`signature-internal.js` script tag — or **nothing at all** if the viewer
isn't logged in or lacks `signatures.{type}.internal.sign`. An unauthorised
viewer never receives the markup, the token or the CSRF value.

Currently wired on all six document pages: `quote.php`, `facture.php`,
`bon_livraison.php`, `bon_commande.php`, `print_cre.php`, `payslip.php`.

Note the id is `lpc-sign-data`, **not** `lpc-page-data`: several host pages
already emit their own `#lpc-page-data` for unrelated reasons, and two
elements sharing an id would leave the winner to document order.

**3. Rendered signature area** (HTML page + dompdf PDF)

`includes/components/signature_block.php`. **Never** hand-render a signature
stamp in a document template — setting the `$sig_*` variables and
`require`-ing the partial is the only supported way.

It handles all four states: unsigned, signed-active, signed-stale (hash
mismatch, i.e. document edited after signing), revoked. Each looks the same
on every document type. Optional `$sig_labels` overrides the two column
headings — the devis passes its Proposal-Studio-editable strings so an admin
rewording the offer still controls what prints above each signature.

### Height budget

`lpc_render_quote_pdf_html()` guarantees the devis is always one A4 page and
allocates the signature block exactly **34mm**
(`scripts/tests/quote_onepager.test.php` asserts the page count). The block
lays each party out as stamp | identity | QR side by side for that reason.
Stacking the QR under the stamp costs ~24mm more and pushes a signed devis
onto page 2. Measure before changing.

---

## Public verification page

`public/verify.php` accepts `?token=…` (or `/verify/{40-hex-token}` via the
`.htaccess` rewrite) and shows three stacked sections:

1. Auth banner — "Ceci est la vérification officielle d'une signature
   électronique émise par LPC."
2. Structured document summary — per-type: reference, client, total,
   signer, date, hash fragment, party tag (internal/external).
3. LPC contact block — email / phone / address / RCCM / NIU pulled from
   `CompanyProfile`. Anyone who wants to verify physically has everything
   they need to reach the company directly.

Three states are answered explicitly, never conflated:

- **unknown** — 404. Never distinguishes malformed vs. never-existed.
- **revoked** — 200, shown as revoked with the original signer + date
  still visible. Not a 404, so someone holding an old printed PDF can't
  claim "the link is just broken".
- **valid** — 200, full summary.

---

## Adding a new document type

Four steps, in this order:

1. **Register the slug.** Add it to `DocumentSignature::TYPES`. The class
   will throw at signing time if the slug isn't there.
2. **Implement the canonical payload.** Add `canonicalPayload_{slug}($doc)`
   as a private static method in `DocumentSignature.php`. Return an array
   with `v => 1` and every field that matters for the document's meaning.
   Keys are load-bearing forever.
3. **Add the two permissions in a migration.** Rows for
   `signatures.{slug}.internal.sign` and `signatures.{slug}.external.sign`
   in the `permissions` table, seeded to admin. See migration 050 for the
   idiom.
4. **Wire the PDF template to the shared render partial.** Set `$sig_type`,
   `$sig_doc_id`, `$sig_doc`, `$sig_context` and `require`
   `includes/components/signature_block.php` at the signature area of
   your template. Do the same in the corresponding HTML page under
   `public/documents/`.
5. **Add the internal sign button.** Set `$sign_btn_type` +
   `$sign_btn_token` and `require`
   `includes/components/signature_sign_button.php` in the page's nav.
6. **Teach `verify.php` to summarise it.** Add a `case` to
   `verify_summary()` returning a title and three or four fields. Without
   this the QR resolves but shows no document context.

That is the entire contract. Nothing else in the codebase gets to invent
its own signature scheme.

If the new type also needs an external (counterparty) signing page, model
it on `public/documents/sign_cre.php` — the simplest of the two — and reuse
`assets/js/modules/signature-universal.js` rather than writing new JS.

Optional, only if the new type has business-state side effects (like BL
updating deliveries.status): register a handler in
`lpc_signature_side_effects_dispatch()` inside
`includes/functions/signature_side_effects.php`.

---

## Related files

- Migration: `migrations/048_document_signature.sql` (original schema),
  `migrations/050_signatures_universal.sql` (universal extension)
- Class: `includes/classes/DocumentSignature.php`
- Side effects: `includes/functions/signature_side_effects.php`
- Render partial: `includes/components/signature_block.php`
- Internal sign button: `includes/components/signature_sign_button.php`
- Controller: `api/v1/signatures_controller.php`
- Shared JS: `assets/js/modules/signature-universal.js` (external, customer)
  and `assets/js/modules/signature-internal.js` (internal, LPC staff)
- Verify page: `public/verify.php`
- QR helper: `includes/functions/qr.php`
- Tests: `scripts/tests/signature_canonical_payload.test.php`

Related but historic (do NOT extend):

- `includes/classes/SignerOtp.php` — Sprint 7C phone OTP class. Kept
  dormant, no active callers. Do not add new ones.
- `api/v1/signer_otp_controller.php` — same, dormant.
- `api/v1/proposal_signature_controller.php` — the devis-only signature
  endpoint from Sprint 10. Still functional (it calls the same
  `DocumentSignature` class) but no longer used by any page. New callers
  must use `signatures_controller.php`.
- `assets/js/modules/documents-quote-sign.js`,
  `documents-sign_cre.js`, `documents-sign_bl.js` — replaced by the two
  shared modules. Reduced to tombstones that log a console warning; safe
  to `git rm`.
- Legacy signature columns on `deliveries` (`signature_image`,
  `driver_signature_image`, etc.) and `cre_documents` (`signature_image`,
  etc.). Retained for backward reading of pre-migration-050 rows;
  writes go to `document_signatures` only.
