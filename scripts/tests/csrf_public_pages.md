# CSRF public-pages · accepted-risk register

**Sprint 7C · Deliverable 2** — closes AUDIT §2.1 item C2.

Two public-token pages (`public/documents/sign_bl.php` and
`public/documents/sign_cre.php`) are reachable by an unauthenticated
visitor whose session has no CSRF token to attach to a request. This
document records exactly which server actions are therefore excluded
from `Csrf::requireValid()`, why the risk is accepted, what compensating
controls are in place, and what a future release could do to raise the
bar further.

Any state-changing API endpoint **not** listed here **must** call
`Csrf::requireValid()`; a grep is committed alongside this doc so a code
review can verify the invariant in seconds.

---

## 1. Endpoints intentionally excluded from CSRF

| Controller | Action | Path | Reason |
|---|---|---|---|
| `api/v1/sales_controller.php` | `sign_bl`   | `POST /api/v1/sales_controller.php?action=sign_bl`   | Customer signs a BL from a phone whose session is fresh. No prior page renders a CSRF token they could echo back. |
| `api/v1/sales_controller.php` | `reject_bl` | `POST /api/v1/sales_controller.php?action=reject_bl` | Same customer session as `sign_bl`. |
| `api/v1/cre_controller.php`   | `sign_cre`  | `POST /api/v1/cre_controller.php?action=sign_cre`   | Customer signs a CRE from the same anonymous session model. |
| `api/v1/cre_controller.php`   | `reject_cre`| `POST /api/v1/cre_controller.php?action=reject_cre` | Same. |
| `api/v1/sales_controller.php` | `driver_confirm_delivery` | `POST /api/v1/sales_controller.php?action=driver_confirm_delivery` | First-touch on the receiver's device: adjusts quantities before the client OTP challenge. Runs in the same public session that later signs the BL. |

Every other `'PUBLIC'` marker in `$ACTION_PERMS` is either read-only
(no state change) or wired through `signer_otp_controller.php`, which
**does** call `Csrf::requireValid()` — see §3.

---

## 2. Compensating controls in place after Sprint 7C

Together these reduce the CSRF-bypass risk to a level the audit
explicitly accepted:

- **Cryptographic token binding.** Each `deliveries.token` and
  `cre_documents.token` is 128 bits of entropy
  (`bin2hex(random_bytes(16))`) generated server-side. Guessing one is
  infeasible; the URL is the auth secret.
- **One-shot token semantics.** Signing a BL/CRE moves it to
  `status='completed'` (BL) / `'signed'` (CRE). Both controllers refuse
  further writes on a completed row. Replay of a captured POST is a
  no-op.
- **Rate-limited endpoints.** `signer_otp_controller.php` guards
  `signer_otp_ip` (20 requests / IP / 15 min) and `SignerOtp::issue`
  guards `signer_otp_issue` (3 codes / phone / hour) via
  `RateLimiter::guard`.
- **IP + UA + timestamp captured.** Every submission writes
  `ip_address`, and the SignerOtp row records `ip` + `user_agent` so an
  audit trail exists per attempt.
- **Digital hash of submission bytes.** `sales_controller::sign_bl`
  writes `digital_hash = sha256(reference + name + phone + ip + timestamp
  + sha256(signature.png))`. Signature files land on disk (Sprint 2
  hardening); the hash covers the file's actual bytes, so any post-hoc
  tampering breaks verification.
- **OTP verified within 30 min of submit.** Sprint 7C · D1's SignerOtp
  requires the receiver to prove they hold the phone number the delivery
  was scheduled against before the signature pad is unlocked. The
  session flag `otp_verified_<sha256(token)>` is checked server-side
  inside both `sign_bl` and `sign_cre` — missing / stale flag rejects
  with `code='otp_required'`.

The SignerOtp gate is the meaningful CSRF-adjacent defence: a bare
CSRF attack would have to first coax the victim through the OTP flow,
which requires the attacker to know the victim's phone number **and**
intercept the SMS/e-mail within the 10-minute code window.

---

## 3. Why the SignerOtp controller is still CSRF-checked

`api/v1/signer_otp_controller.php` is technically reachable by an
unauthenticated visitor, but both sign_bl.php and sign_cre.php render
under our control and emit `Csrf::token()` inside their PAGE_DATA
hoister block. That gives us a matching CSRF token on the client side,
so the controller can (and does) call `Csrf::requireValid()`. This
turns the OTP request/verify pair into a full CSRF-protected exchange
even though the visitor has no login.

Concretely: the sign_bl.php PHP page emits
`{ csrf: Csrf::token(), csrfField: '_csrf' }` in `window.PAGE_DATA`;
`documents-sign_bl.js` reads it and forwards on every fetch as both an
`X-CSRF-Token` header and a `_csrf` body field.

---

## 4. What would raise the bar further (out of scope for v1)

Deferred to a future security sprint; not blocking ship.

- **Full mTLS on the public sign pages.** Issuing a client certificate
  per delivery would eliminate the URL-secret model entirely. High
  operational cost; irrelevant until the customer base can install
  certificates.
- **Phone-bound magic-link.** Instead of a shared token URL SMS'd
  ahead, mint a one-time link per (delivery, receiver phone) that
  expires on first-use OR after 60 minutes. Requires an SMS provider
  in production (currently optional — see `SMS_PROVIDER` in `.env`).
- **Device-bound signature keypair.** WebAuthn-style attestation from
  the receiver's device would let us verify the signer had the physical
  phone at submit time. Requires HTTPS everywhere (already true) and a
  fallback for feature-phone receivers.

Anything in this list would render §1 empty. Until then, §1 is the
accepted risk.

---

## 5. Invariant enforcement

Anyone reviewing a diff that touches `api/v1/*.php` can run:

```bash
grep -rl "Csrf::requireValid" api/v1/ | sort
grep -rnE "'PUBLIC'" api/v1/ | grep -v csrf_public_pages
```

The first list is the set of controllers with at least one CSRF gate.
The second is every action currently marked `'PUBLIC'`; each should
appear in §1 above with a written justification, or be removed. If a
new `'PUBLIC'` action lands that isn't in §1, the reviewer should
either (a) add it to §1 with a written justification, or (b) require
the author to swap to a real permission gate + CSRF check.
