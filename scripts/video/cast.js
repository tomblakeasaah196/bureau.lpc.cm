"use strict";
/**
 * The people on screen (docs/TRAINING_VIDEOS.md §3): LPC's real staff, by their real names, as the owner
 * decided (17/11/2026: an internal training library). They are the five logins of the live database
 * (export of 17/11/2026). Scenes name people by key, never by address, so the next video meets the same
 * colleagues. Add a person here, never in a scene. There is no Sales login yet: add it once LPC creates one.
 */
const CAST = Object.freeze({
  timothee: { name: "Timothée Massomba", post: "Directeur Général", role: "directeur_general", email: "timothee.massomba@lpc.cm" },
  luc: { name: "Luc Nana", post: "Contrôleur Financier", role: "controleur_financier", email: "luc.nana@gmail.com" },
  daniel: { name: "Daniel Massomba", post: "Comptable", role: "accountant", email: "daniel.massomba@lpc.cm" },
  bertin: { name: "Bertin Chadeu", post: "Responsable des Opérations", role: "operations", email: "bertin.chadeu@gmail.com" },
  support: { name: "JBS Praxis Support", post: "Support technique", role: "admin", email: "praxisjbs@gmail.com" },
});

function emailOf(key) {
  if (!CAST[key]) throw new Error(`The cast has no "${key}" (scripts/video/cast.js).`);
  return CAST[key].email;
}

module.exports = { CAST, emailOf };
