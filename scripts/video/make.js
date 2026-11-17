#!/usr/bin/env node
"use strict";
/**
 * Make a Bureau LPC training video (docs/TRAINING_VIDEOS.md).
 *
 *   node scripts/video/make.js <scene> script                 print the narration, chapter by chapter, with its length
 *   node scripts/video/make.js <scene> prepare                the ground a scene films on (its own `prepare`)
 *   node scripts/video/make.js <scene> narrate                the voice for every line (cached by its words)
 *   node scripts/video/make.js <scene> film [--only=c03s01]   record the shots (or only some)
 *   node scripts/video/make.js <scene> assemble               cards, captions, chapters, sound: the MP4, .srt, .vtt, poster, manifest
 *   node scripts/video/make.js <scene> all                    narrate, film and assemble
 *   node scripts/video/make.js - serve                        serve the filming copy on 127.0.0.1:8140 (foreground)
 *   node scripts/video/make.js - snapshot <name>              save the filming copy; `restore <name>` puts it back
 *
 * <scene> is a file in scripts/video/scenes/ (with or without .js). Filming needs the copy served
 * (§4.1), ffmpeg, Chrome (browser.js) and the narration's Python (VIDEO_PYTHON, §1).
 *
 *   VIDEO_HOME      the folder outside the repository that holds the copy and the output (ground.js)
 *   VIDEO_OUT       where this video's work and files go (default VIDEO_HOME/out/<scene>)
 *   VIDEO_PYTHON    a Python with scripts/video/requirements.txt (default ~/.video-venv)
 *   VIDEO_VOICE     "edge" (Denise, the owner's choice) or "kokoro" (the local fallback); default edge
 */
const fs = require("node:fs");
const os = require("node:os");
const path = require("node:path");
const { execFileSync } = require("node:child_process");

/**
 * How a word on screen is said. The caption keeps the left, the voice reads the right. French TTS reads
 * most acronyms letter by letter on its own; these are the ones it gets wrong or that read better spelt.
 * Add to it when a take mispronounces something (and say so in §6 of the runbook).
 */
const SAID = [
  [/\bbureau\.lpc\.cm\b/gi, "bureau point L P C point C M"],
  [/\bLPC\b/g, "L P C"],
  [/\bERP\b/g, "E R P"],
  [/\bJBS\b/g, "J B S"],
  [/\bBL\b/g, "B L"],
  [/\bBLs\b/g, "B L"],
  [/\bCRE\b/g, "C R E"],
  [/\bFCFA\b/g, "francs CFA"],
  [/\bTVA\b/g, "T V A"],
  [/\bRH\b/g, "R H"],
  [/\bBI\b/g, "B I"],
  [/\bCRM\b/g, "C R M"],
  [/\bIA\b/g, "I A"],
  [/\bCtrl\b/g, "Contrôle"],
  [/(?<!code )\bPIN\b/g, "code PIN"],
  [/«\s*|\s*»/g, " "],
];

/** A line's words as the voice reads them: "|" only splits the caption. */
const speechOf = (line) =>
  SAID.reduce((t, [re, to]) => t.replace(re, to), line.speech || line.say).replace(/\|/g, " ").replace(/\s+/g, " ").replace(/\s+([.,;:!?])/g, "$1").trim();

function loadScene(name) {
  const file = path.resolve(__dirname, "scenes", name.endsWith(".js") ? name : `${name}.js`);
  if (!fs.existsSync(file)) throw new Error(`No scene ${file}`);
  return require(file);
}

/** The scene, flattened: every shot with its chapter, every line with an id and its words. */
function planOf(scene) {
  const shots = [];
  scene.chapters.forEach((chapter, ci) => {
    chapter.shots.forEach((shot, si) => {
      const id = `c${String(ci + 1).padStart(2, "0")}s${String(si + 1).padStart(2, "0")}`;
      shots.push({
        ...shot, id, chapter: ci + 1, chapterName: chapter.name,
        lines: shot.lines.map((line, li) => {
          const l = typeof line === "string" ? { say: line } : line;
          if (!l.say) throw new Error(`${id} line ${li + 1} has no say.`);
          if (/[—–]/.test(l.say)) throw new Error(`${id} line ${li + 1}: no dashes in what a viewer reads (runbook §2).`);
          return { ...l, id: `${id}l${String(li + 1).padStart(2, "0")}`, speech: speechOf(l) };
        }),
      });
    });
  });
  return { ...scene, shots, chapters: scene.chapters.length };
}

function printScript(plan) {
  let words = 0;
  let chapter = 0;
  for (const shot of plan.shots) {
    if (shot.chapter !== chapter) {
      chapter = shot.chapter;
      console.warn(`\n## ${chapter} · ${shot.chapterName}`);
    }
    const where = shot.kind === "card" ? `${shot.card} card` : `${shot.kind}${shot.as ? `, as ${shot.as}` : ", signed out"}${shot.start ? `, ${shot.start}` : ""}`;
    console.warn(`\n[${shot.id} · ${where}]`);
    for (const l of shot.lines) {
      words += l.speech.split(/\s+/).length;
      console.warn(`- ${l.say.replace(/\|/g, "")}${l.speech !== l.say.replace(/\|/g, " ").replace(/\s+/g, " ").trim() ? `\n    (dit : ${l.speech})` : ""}`);
    }
  }
  console.warn(`\n${words} words, about ${(words / 150).toFixed(1)} minutes at 150 words a minute (the narrate step measures it).`);
}

const venvPython = () => {
  const win = path.join(os.homedir(), ".video-venv", "Scripts", "python.exe");
  const nix = path.join(os.homedir(), ".video-venv", "bin", "python");
  return process.env.VIDEO_PYTHON || (fs.existsSync(win) ? win : fs.existsSync(nix) ? nix : "python3");
};

async function main() {
  const [name, step = "all", ...rest] = process.argv.slice(2);
  if (!name) { console.warn(fs.readFileSync(__filename, "utf8").split("*/")[0]); process.exit(2); }
  const ground = require("./ground");

  if (name === "-") {
    if (step === "serve") { const p = ground.serve(); console.warn(`serving the copy on 127.0.0.1:${ground.PORT} (as ${ground.ORIGIN})`); await new Promise((r) => p.on("exit", r)); return; }
    if (step === "snapshot") return console.warn(ground.snapshot(rest[0]));
    if (step === "restore") { ground.restore(rest[0]); return console.warn(`restored ${rest[0]}`); }
    if (step === "migrate") return ground.migrate();
    throw new Error(`Unknown step ${step}`);
  }

  const plan = planOf(loadScene(name));
  if (step === "script") return printScript(plan);

  const out = process.env.VIDEO_OUT || path.join(ground.HOME, "out", plan.id);
  if (step === "prepare") {
    if (!plan.prepare) throw new Error(`${plan.id} has no prepare step.`);
    return plan.prepare({ log: console.warn, out, ground });
  }

  const audioDir = path.join(out, "audio");
  const python = venvPython();
  fs.mkdirSync(out, { recursive: true });
  const steps = step === "all" ? ["narrate", "film", "assemble"] : [step];
  process.env.VIDEO_OUT = out;

  if (steps.includes("narrate")) {
    const engine = process.env.VIDEO_VOICE || plan.voice?.engine || "edge";
    const lines = path.join(out, "lines.json");
    fs.writeFileSync(lines, JSON.stringify({
      engine,
      ...(engine === "edge" ? { voice: "fr-FR-DeniseNeural", rate: "-4%" } : { voice: "ff_siwis", speed: 0.95 }),
      lines: plan.shots.flatMap((s) => s.lines.map((l) => ({ id: l.id, speech: l.speech }))),
    }, null, 1));
    execFileSync(python, [path.join(__dirname, "narrate.py"), "speak", lines, audioDir], { stdio: "inherit", env: { ...process.env, PYTHONIOENCODING: "utf-8" } });
  }
  const durations = JSON.parse(fs.readFileSync(path.join(audioDir, "durations.json"), "utf8"));
  for (const s of plan.shots) for (const l of s.lines) {
    if (!(l.id in durations)) throw new Error(`No narration for ${l.id}: run the narrate step.`);
    l.duration = durations[l.id];
  }

  if (steps.includes("film")) {
    const cast = require("./cast");
    ground.guard();
    const used = [...new Set(plan.shots.map((s) => s.as).filter(Boolean))];
    for (const u of used) cast.emailOf(u); // throws for a person the cast lacks
    // A person the scene shows signing in on camera needs the filming password too.
    const typed = [...new Set([...(plan.signsIn || []), ...used])];
    ground.ready(typed.map((k) => cast.emailOf(k)));
    const up = await fetch(`http://127.0.0.1:${ground.PORT}/`).then((r) => r.ok, () => false);
    if (!up) throw new Error(`The copy is not being served on 127.0.0.1:${ground.PORT}: run \`node scripts/video/make.js - serve\` first (§4.1).`);
    const only = rest.find((a) => a.startsWith("--only="));
    await require("./film").film(plan, out, {
      origin: ground.ORIGIN,
      port: ground.PORT,
      password: ground.PASSWORD,
      emailOf: (key) => cast.emailOf(key),
      only: only ? new Set(only.slice(7).split(",")) : null,
    });
  }

  if (steps.includes("assemble")) {
    const { final, length, chapters } = await require("./assemble").assemble(plan, out, { audioDir, python });
    console.warn(`\n${final}\n${(length / 60).toFixed(1)} minutes, ${chapters.length} chapters`);
  }
}

module.exports = { speechOf, planOf, SAID };

if (require.main === module) main().then(() => process.exit(0), (err) => { console.error(err.stack || err.message); process.exit(1); });
