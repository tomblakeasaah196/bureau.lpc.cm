# Architecture

This is a stub. The authoritative architecture reference lives in the root
`README.md` (§2) — it covers the folder layout, request flow, RBAC engine,
env loading, and coding conventions.

This file exists as a placeholder for deeper diagrams and design docs to be
added as the revamp progresses:

- ADRs (Architecture Decision Records) for anything non-obvious
- ER diagram of the current schema
- Sequence diagrams for the auth + RBAC flow, the tournée reconciliation flow,
  and the invoice → payment → journal-entry flow
- Deployment topology (once we migrate off shared cPanel)

For now: read `README.md`, then read `AUDIT_REPORT.md` for historical context.

## Cross-cutting subsystems

Universal specs that any new module has to conform to — treat them as
architectural invariants, not optional guidelines:

- **Signatures** — `docs/SIGNATURES.md`. Every document type that needs a
  signature (customer-facing or internal LPC-staff attestation) uses one
  table, one class, one controller, one render partial, one verify page.
  See the "adding a new document type" checklist in that spec before
  designing any new signable document.
