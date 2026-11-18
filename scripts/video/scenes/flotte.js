"use strict";
/**
 * Modules, partie 6 : Flotte. docs/TRAINING_VIDEOS.md says how a scene is written and filmed.
 *
 * LPC has no driver accounts: the Responsable des Opérations runs the fleet (Bertin Chadeu on screen). He
 * registers the delivery truck, assigns it for the day, logs a fuel fill, reports a breakdown and records the
 * repair. Every cost becomes a request in Gestion des Dépenses (the owner's answer 6), which the Contrôleur
 * Financier validates and pays (Luc Nana). Narrated by role (runbook §2).
 *
 * Ground: snapshot pre-06-film (after-02-gestion-des-achats + migration 133, no vehicle yet). The scene writes:
 * the truck (an example plate until LPC gives its own), today's assignment, a 60 L fuel fill (EX-2611-00001),
 * a breakdown, a repair of 85 000 F (EX-2611-00002), and the fuel request paid from MTN MOBILE MONEY.
 */
const h = require("../helpers");

const PAGE = {
  fleet: "/modules/fleet/vehicles.php",
  expenses: "/modules/accounting/expenses.php",
};
const TRUCK = { plate: "LT 742 KL", model: "Toyota Dyna" };

const pick = async (s, select, value) => {
  await s.hover(s.page.locator(select));
  await s.page.locator(select).selectOption(value);
  await s.wait(0.6);
};
/** The option of a select whose text matches. */
const pickLike = async (s, select, re) => {
  await s.page.waitForFunction((sel) => document.querySelectorAll(`${sel} option`).length > 1, select, { timeout: 15000 });
  const opt = await s.page.locator(`${select} option`).filter({ hasText: re }).first().getAttribute("value");
  await pick(s, select, opt);
};
const setDate = async (s, sel, iso) => { await s.hover(s.page.locator(sel)); await s.page.locator(sel).fill(iso); await s.wait(0.5); };
const tab = (s, id) => s.click(s.page.locator(`#tab-${id}`));
const toast = (s, text) => s.page.locator("#lpc-toast-root, .lpc-toast, [role=status], [role=alert]").filter({ hasText: text }).last();
const expenseRow = (s, ref) => s.page.locator("#list_body tr").filter({ hasText: ref }).first();

module.exports = {
  id: "flotte",
  file: "LPC-ERP-Flotte",
  meta: { title: "LPC ERP · Flotte (Modules, partie 6)", artist: "JBS Praxis" },
  library: {
    slug: "flotte",
    title: "Flotte",
    subtitle: "Le camion, ses affectations, le carburant, les pannes et les réparations",
    series: "Modules",
    part: 6,
    parts: 15,
    pages: [PAGE.fleet],
  },
  opening: {
    eyebrow: "Formation LPC ERP · Modules, partie 6",
    title: "Flotte",
    subtitle: "Le camion, ses affectations, le carburant, les pannes et les réparations",
    left: "bureau.lpc.cm",
  },
  closing: {
    eyebrow: "Formation LPC ERP · Modules, partie 6",
    title: "Merci",
    subtitle: "À suivre : Gestion des Vides",
    left: "bureau.lpc.cm",
  },
  holders: { operations: "Bertin Chadeu", controleur: "Luc Nana" },

  async prepare({ log, ground }) {
    ground.restore("pre-06-film");
    log("ground: pre-06-film restored");
  },

  chapters: [
    {
      name: "Bienvenue",
      shots: [{
        kind: "card", card: "opening",
        lines: [
          "Bienvenue dans Flotte & Maintenance, le module des véhicules de LPC ERP.",
          "LPC n'utilise pas de comptes chauffeur : le Responsable des Opérations tient la flotte.",
          "Il enregistre le camion, l'affecte chaque jour, saisit les pleins, les pannes et les réparations.",
          "Chaque coût part à la Finance, qui le valide et le paie dans Gestion des Dépenses.",
        ],
      }],
    },

    {
      name: "L'écran de la flotte",
      shots: [{
        kind: "desktop", as: "bertin", role: "operations", start: PAGE.fleet,
        lines: [
          { say: "Le Responsable des Opérations ouvre Flotte & Maintenance, dans Logistique & Stock.",
            do: (s) => h.soft(s, h.shell.menu(s, "Flotte & Maintenance")) },
          { say: "Cinq onglets : le tableau de bord, le parc, les affectations du jour, la maintenance et le carburant.",
            do: (s) => h.tour(s, ["dashboard", "flotte", "affectations", "maintenance", "carburant"].map((t) => s.page.locator(`#tab-${t}`)), 0.35) },
          { say: "Le parc est encore vide : ces chiffres se rempliront au fil du travail.",
            do: (s) => h.soft(s, s.page.locator("#kpi_active_vehicles")) },
          { say: "Dans le menu, « Relevé Carburant » et « Déclarer une Panne » ouvrent ce même écran.",
            do: (s) => h.tour(s, [h.shell.menu(s, "Relevé Carburant"), h.shell.menu(s, "Déclarer une Panne")], 0.5) },
        ],
      }],
    },

    {
      name: "Le camion",
      shots: [{
        kind: "desktop", as: "bertin", role: "operations", start: PAGE.fleet, snapshot: true,
        lines: [
          { say: "Dans Parc Automobile, il ajoute le camion de livraison.",
            do: async (s) => { await tab(s, "flotte"); await s.click(h.button(s, "Ajouter un Véhicule")); } },
          { say: "L'immatriculation, le type, Camion, puis la marque et le modèle.",
            do: async (s) => {
              await s.type(s.page.locator("#veh_plate"), TRUCK.plate, { delay: 90 });
              await pick(s, "#veh_type", "truck");
              await s.type(s.page.locator("#veh_make_model"), TRUCK.model, { delay: 70 });
            } },
          { say: "Le carburant, du gasoil, et le compteur le jour où il entre au parc : 84 250 kilomètres.",
            speech: "Le carburant, du gasoil, et le compteur le jour où il entre au parc : quatre-vingt-quatre mille deux cent cinquante kilomètres.",
            do: async (s) => { await pick(s, "#veh_fuel_type", "diesel"); await s.type(s.page.locator("#veh_odometer"), "84250", { delay: 110 }); } },
          { say: "Les échéances de l'assurance et de la visite technique : l'ERP prévient quinze jours avant.",
            speech: "Les échéances de l'assurance et de la visite technique : l'E R P prévient quinze jours avant.",
            do: async (s) => { await setDate(s, "#veh_insurance", "2027-03-31"); await setDate(s, "#veh_tech_visit", "2026-11-30"); } },
          { say: "« Enregistrer » : le camion entre au parc, en service.",
            do: async (s) => { await s.click(s.page.locator("#btn-submit-veh")); await s.wait(1); await h.soft(s, s.page.locator("#table-body-vehicles tr").first()); } },
          { say: "Au tableau de bord, la visite technique du 30 novembre est déjà signalée.",
            speech: "Au tableau de bord, la visite technique du trente novembre est déjà signalée.",
            do: async (s) => { await tab(s, "dashboard"); await h.soft(s, s.page.locator("#dash-alerts-body tr").first(), 6000); } },
        ],
      }],
    },

    {
      name: "L'affectation du jour",
      shots: [{
        kind: "desktop", as: "bertin", role: "operations", start: PAGE.fleet, snapshot: true,
        lines: [
          { say: "Chaque matin, il note qui conduit quel véhicule, dans Affectations.",
            do: (s) => tab(s, "affectations") },
          { say: "« Nouvelle Affectation » : seuls les véhicules en service sont proposés.",
            do: async (s) => { await s.click(h.button(s, "Nouvelle Affectation")); await pickLike(s, "#assign_vehicle_id", new RegExp(TRUCK.plate)); } },
          { say: "Le chauffeur se choisit dans la liste du personnel : un employé n'a pas besoin de compte.",
            do: (s) => h.soft(s, s.page.locator("#assign_driver_id")) },
          { say: "Aujourd'hui, le Responsable des Opérations conduit lui-même.",
            do: (s) => pickLike(s, "#assign_driver_id", /Bertin/) },
          { say: "Le jour est déjà le bon, et le kilométrage de départ reprend le dernier relevé.",
            do: (s) => h.tour(s, [s.page.locator("#assign_date"), s.page.locator("#assign_odo_start")], 0.4) },
          { say: "« Affecter » : la journée est notée.",
            do: async (s) => { await s.click(s.page.locator("#btn-submit-assign")); await s.wait(1); await h.soft(s, s.page.locator("#table-body-assignments tr").first()); } },
        ],
      }],
    },

    {
      name: "Le plein",
      shots: [{
        kind: "desktop", as: "bertin", role: "operations", start: PAGE.fleet, snapshot: true,
        lines: [
          { say: "De retour de la station, il saisit le plein dans Suivi Carburant.",
            do: async (s) => { await tab(s, "carburant"); await s.click(h.button(s, "Saisir Carburant")); } },
          { say: "Le camion. Le type de carburant suit le véhicule : gasoil.",
            do: async (s) => { await pickLike(s, "#fuel_vehicle_id", new RegExp(TRUCK.plate)); await s.page.locator("#fuel_vehicle_id").dispatchEvent("change"); await h.soft(s, s.page.locator("#fuel_type")); } },
          { say: "La station, puis le compteur : 84 310 kilomètres.",
            speech: "La station, puis le compteur : quatre-vingt-quatre mille trois cent dix kilomètres.",
            do: async (s) => {
              await s.type(s.page.locator("#fuel_station"), "TOTAL Bonabéri", { delay: 70 });
              await s.type(s.page.locator("#fuel_odometer"), "84310", { delay: 110 });
            } },
          { say: "Soixante litres à 828 FCFA : le total se calcule, 49 680 FCFA.",
            speech: "Soixante litres à huit cent vingt-huit francs : le total se calcule, quarante-neuf mille six cent quatre-vingts francs.",
            do: async (s) => {
              await s.type(s.page.locator("#fuel_liters"), "60", { delay: 140 });
              await s.type(s.page.locator("#fuel_price"), "828", { delay: 140 });
              await h.soft(s, s.page.locator("#fuel_total_display"));
            } },
          { say: "Le ticket peut être joint en photo : la Finance le retrouvera avec la dépense.",
            do: (s) => h.soft(s, s.page.locator("#fuel_receipt")) },
          { say: "« Valider » : le plein est noté, et une demande de dépense part à la Finance.",
            do: async (s) => { await s.click(s.page.locator("#btn-submit-fuel")); await h.soft(s, toast(s, "EX-2611-00001"), 6000); } },
          { say: "Sa référence s'affiche sur la ligne du plein, à valider.",
            do: (s) => h.soft(s, s.page.locator("#table-body-fuel tr").first().locator("td").last(), 6000) },
        ],
      }],
    },

    {
      name: "La panne",
      shots: [{
        kind: "desktop", as: "bertin", role: "operations", start: PAGE.fleet, snapshot: true,
        lines: [
          "En fin de tournée, l'embrayage patine : le camion doit s'arrêter.",
          { say: "Dans Parc Automobile, il déclare la panne.",
            do: async (s) => { await tab(s, "flotte"); await s.click(h.button(s, "Déclarer une panne")); } },
          { say: "Le camion, et ce qui ne va pas.",
            do: async (s) => {
              await pickLike(s, "#brk_vehicle_id", new RegExp(TRUCK.plate));
              await s.type(s.page.locator("#brk_desc"), "Embrayage qui patine, camion à l'arrêt au dépôt.", { delay: 35 });
            } },
          { say: "« Déclarer la panne » : le camion passe en panne.",
            do: async (s) => { await s.click(s.page.locator("#btn-submit-brk")); await s.wait(1); await h.soft(s, s.page.locator("#table-body-vehicles .status-badge-repair").first(), 6000); } },
          { say: "Il n'est plus proposé aux affectations, et le tableau de bord le compte au garage.",
            do: async (s) => { await tab(s, "dashboard"); await h.soft(s, s.page.locator("#kpi_repair_vehicles"), 6000); } },
        ],
      }],
    },

    {
      name: "La réparation",
      shots: [{
        kind: "desktop", as: "bertin", role: "operations", start: PAGE.fleet, snapshot: true,
        lines: [
          { say: "Le garage a remplacé l'embrayage. Dans Maintenance & Documents, il enregistre l'intervention.",
            do: async (s) => { await tab(s, "maintenance"); await s.click(h.button(s, "Enregistrer Intervention")); } },
          { say: "Le camion, le type Réparation, et le coût : 85 000 FCFA.",
            speech: "Le camion, le type Réparation, et le coût : quatre-vingt-cinq mille francs.",
            do: async (s) => {
              await pickLike(s, "#maint_vehicle_id", new RegExp(TRUCK.plate));
              await pick(s, "#maint_type", "repair");
              await s.type(s.page.locator("#maint_cost"), "85000", { delay: 110 });
            } },
          { say: "Le garage, et ce qui a été fait.",
            do: async (s) => {
              await s.type(s.page.locator("#maint_garage"), "Garage Akwa Auto", { delay: 60 });
              await s.type(s.page.locator("#maint_desc"), "Kit d'embrayage remplacé.", { delay: 45 });
            } },
          { say: "« Enregistrer » : le camion repasse en service, et le coût part à la Finance.",
            do: async (s) => { await s.click(s.page.locator("#btn-submit-maint")); await h.soft(s, toast(s, "EX-2611-00002"), 6000); } },
          { say: "Le journal garde la panne et la réparation, avec la demande de dépense de chacune.",
            do: (s) => h.tour(s, [s.page.locator("#table-body-maintenance tr").filter({ hasText: "Panne" }).first(),
                                  s.page.locator("#table-body-maintenance tr").filter({ hasText: "EX-2611-00002" }).first().locator("td").last()], 0.6) },
          "Une vidange ou une assurance s'enregistre de la même façon ; l'assurance met aussi sa date à jour.",
        ],
      }],
    },

    {
      name: "La Finance valide",
      shots: [{
        kind: "desktop", as: "luc", role: "controleur", start: PAGE.expenses, snapshot: true,
        lines: [
          { say: "Côté Finance, le Contrôleur Financier ouvre Gestion des Dépenses.",
            do: (s) => h.soft(s, h.shell.menu(s, "Gestion des Dépenses")) },
          { say: "Les demandes de la flotte l'attendent, à valider, chacune avec son camion.",
            do: async (s) => { await s.click(s.page.locator("[onclick*=\"switchExpenseTab('list')\"]").first()); await h.soft(s, expenseRow(s, "EX-2611-00001"), 6000); } },
          { say: "Le plein correspond au ticket : il le valide et le paie.",
            do: (s) => s.click(expenseRow(s, "EX-2611-00001").locator("button[title='Valider et payer']")) },
          { say: "Il choisit le compte qui paie : MTN MOBILE MONEY.",
            speech: "Il choisit le compte qui paie : M T N Mobile Money.",
            do: (s) => pickLike(s, "#pay_treasury", /MTN/) },
          { say: "« Valider & comptabiliser » : l'écriture passe en comptabilité, une seule fois.",
            do: async (s) => { await s.click(s.page.locator("#modal-pay button[onclick*=submitPay]")); await s.wait(1.2); await h.soft(s, expenseRow(s, "EX-2611-00001"), 6000); } },
          { say: "Dans Flotte, la ligne du plein le montre aussi : payée.",
            do: async (s) => { await s.go(PAGE.fleet + "#carburant"); await h.soft(s, s.page.locator("#table-body-fuel tr").first().locator("td").last(), 8000); } },
          "Les deux chiffres restent : l'argent donné pour le carburant, et le carburant saisi par les Opérations.",
          "On peut ainsi les comparer, mois par mois.",
        ],
      }],
    },

    {
      name: "Pour conclure",
      shots: [{
        kind: "card", card: "closing",
        lines: [
          "En résumé : les Opérations tiennent la flotte, la Finance valide et paie chaque coût dans Gestion des Dépenses.",
          "La prochaine vidéo de la série : Gestion des Vides.",
          "Merci de votre attention.",
        ],
      }],
    },
  ],
};
