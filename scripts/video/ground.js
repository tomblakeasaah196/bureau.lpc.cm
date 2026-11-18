"use strict";
/**
 * The ground a training video is filmed on (docs/TRAINING_VIDEOS.md §4): a private copy of bureau.lpc.cm
 * on this machine, never the live site.
 *
 *   VIDEO_HOME (default C:/Users/Tom-Blake/bureau_video, or ~/bureau_video) holds everything that is
 *   NOT in the repository, because it holds LPC's real data:
 *     filming.env      the app's .env for the copy (LPC_ENV_PATH points the app at it)
 *     mysql-data/      the copy's own MySQL 8.4, on 127.0.0.1:3317 (never the 3307 other chats use)
 *     dumps/           the live database exports the copy is loaded from
 *     snapshots/       the copy saved before a chapter that writes, so a retake starts where the take did
 *     uploads/         the copy's uploads folder
 *     out/<scene>/     narration, shots and the finished MP4 of each video
 *
 * The app reads its database from filming.env; this file refuses any database that is not
 * bureau_video* on this machine, so a slip can never write to the live site.
 */
const fs = require("node:fs");
const os = require("node:os");
const path = require("node:path");
const { execFileSync, spawn } = require("node:child_process");

const ROOT = path.resolve(__dirname, "..", "..");
const HOME = process.env.VIDEO_HOME || (process.platform === "win32" ? "C:/Users/Tom-Blake/bureau_video" : path.join(os.homedir(), "bureau_video"));
const ENV_FILE = path.join(HOME, "filming.env");
/** What the camera's browser believes it is visiting: the app's real address, served by this machine. */
const ORIGIN = "http://bureau.lpc.cm";
const PORT = Number(process.env.VIDEO_PORT || 8140);
const DB_PORT = Number(process.env.VIDEO_DB_PORT || 3317);
/** The password filming gives the people it signs in as, on the copy only. */
const PASSWORD = process.env.VIDEO_PASSWORD || "Formation-LPC-2026!";

function firstExisting(list) {
  return list.find((p) => p && fs.existsSync(p));
}
function laragon(kind, exe) {
  const base = `C:/laragon/bin/${kind}`;
  if (!fs.existsSync(base)) return null;
  const dirs = fs.readdirSync(base).sort().reverse();
  for (const d of dirs) {
    const p = path.join(base, d, kind === "mysql" ? "bin" : "", exe);
    if (fs.existsSync(p)) return p;
  }
  return null;
}
/** PHP 8.3, the server's version (the php on PATH may be 8.5 without pdo_mysql). */
const PHP = () => firstExisting([process.env.VIDEO_PHP, "C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe", laragon("php", "php.exe")]) || "php";
const MYSQL_BIN = () => process.env.VIDEO_MYSQL_BIN || path.dirname(laragon("mysql", "mysql.exe") || "mysql");
const exe = (name) => path.join(MYSQL_BIN(), process.platform === "win32" ? `${name}.exe` : name);

/** filming.env, parsed. */
function env() {
  if (!fs.existsSync(ENV_FILE)) throw new Error(`No ${ENV_FILE}: set the copy up first (docs/TRAINING_VIDEOS.md §4.1).`);
  const out = {};
  for (const line of fs.readFileSync(ENV_FILE, "utf8").split(/\r?\n/)) {
    const m = /^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/.exec(line);
    if (m) out[m[1]] = m[2].replace(/^["']|["']$/g, "");
  }
  return out;
}

function db() {
  const e = env();
  const [host, portPart] = String(e.DB_HOST || "").split(";port=");
  return { host, port: Number(portPart || 3306), name: e.DB_NAME, user: e.DB_USER || "root", pass: e.DB_PASS || "" };
}

/** Refuse anything but the filming copy: a local MySQL, a bureau_video* database. */
function guard() {
  const d = db();
  if (!["127.0.0.1", "localhost"].includes(d.host) || d.port !== DB_PORT || !/^bureau_video/.test(d.name || ""))
    throw new Error(`Refusing to film on ${d.user}@${d.host}:${d.port}/${d.name}: filming runs only on the copy (127.0.0.1:${DB_PORT}/bureau_video).`);
  return d;
}

const auth = (d) => ["-h", d.host, "-P", String(d.port), "-u", d.user, ...(d.pass ? [`-p${d.pass}`] : [])];

/** Run SQL on the copy; returns rows as arrays of strings. */
function sql(query, { database } = {}) {
  const d = guard();
  const out = execFileSync(exe("mysql"), [...auth(d), "-N", "-B", "--default-character-set=utf8mb4", database === null ? "" : (database || d.name), "-e", query].filter((a) => a !== ""),
    { encoding: "utf8", maxBuffer: 64 * 1024 * 1024 });
  return out.split(/\r?\n/).filter(Boolean).map((r) => r.split("\t"));
}
const quote = (s) => `'${String(s).replace(/\\/g, "\\\\").replace(/'/g, "\\'")}'`;

/** A bcrypt hash PHP's password_verify accepts, made by PHP itself. */
function hash(password) {
  return execFileSync(PHP(), ["-r", "echo password_hash(getenv('P'), PASSWORD_BCRYPT, ['cost' => 10]);"], { env: { ...process.env, P: password }, encoding: "utf8" });
}

/**
 * Make `emails` ready to be signed in as: the filming password (checked against the hash, set only if it
 * differs, so nothing changes needlessly), no forced reset, no failed attempts counting against them.
 */
function ready(emails) {
  if (!emails.length) return;
  guard();
  const rows = sql(`SELECT email, password_hash FROM users WHERE email IN (${emails.map(quote).join(",")})`);
  const known = new Set(rows.map((r) => r[0].toLowerCase()));
  const missing = emails.filter((e) => !known.has(e.toLowerCase()));
  if (missing.length) throw new Error(`Not in the copy's users: ${missing.join(", ")} (cast.js).`);
  const check = execFileSync(PHP(), ["-r", "foreach (json_decode(getenv('R'), true) as [$e, $h]) echo $e, ' ', password_verify(getenv('P'), $h) ? 1 : 0, PHP_EOL;"],
    { env: { ...process.env, P: PASSWORD, R: JSON.stringify(rows) }, encoding: "utf8" });
  const stale = check.split(/\r?\n/).filter((l) => l.endsWith(" 0")).map((l) => l.split(" ")[0]);
  if (stale.length) sql(`UPDATE users SET password_hash = ${quote(hash(PASSWORD))} WHERE email IN (${stale.map(quote).join(",")})`);
  sql(`UPDATE users SET must_reset_password = 0, status = 'active' WHERE email IN (${emails.map(quote).join(",")})`);
  sql("DELETE FROM login_attempts");
}

const snapFile = (name) => path.join(HOME, "snapshots", `${name}.sql`);

/** Save the copy as `name` (before a chapter that writes). */
function snapshot(name) {
  const d = guard();
  fs.mkdirSync(path.join(HOME, "snapshots"), { recursive: true });
  const out = fs.openSync(snapFile(name), "w");
  try {
    execFileSync(exe("mysqldump"), [...auth(d), "--single-transaction", "--routines", "--triggers", "--no-tablespaces", "--default-character-set=utf8mb4", d.name], { stdio: ["ignore", out, "inherit"] });
  } finally {
    fs.closeSync(out);
  }
  // The fake mail server's state goes with it (Courrier: mail on the server and its copy in the database).
  if (fs.existsSync(MAIL_STATE)) fs.copyFileSync(MAIL_STATE, snapFile(name).replace(/\.sql$/, ".mail.json"));
  return snapFile(name);
}

/** Put the copy back as it was at `name`. Stop nothing: the PHP server opens a connection per request. */
function restore(name) {
  const d = guard();
  if (!fs.existsSync(snapFile(name))) throw new Error(`No snapshot ${name}`);
  load(snapFile(name), { quiet: true });
  const mailCopy = snapFile(name).replace(/\.sql$/, ".mail.json");
  if (fs.existsSync(mailCopy)) fs.copyFileSync(mailCopy, MAIL_STATE);
  return d;
}

/** Load an SQL file (a live export, or a snapshot) into a fresh copy of the database. */
function load(file, { quiet = false } = {}) {
  const d = guard();
  sql(`DROP DATABASE IF EXISTS \`${d.name}\`; CREATE DATABASE \`${d.name}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`, { database: null });
  const input = fs.openSync(file, "r");
  try {
    execFileSync(exe("mysql"), [...auth(d), "--default-character-set=utf8mb4", "--max_allowed_packet=256M", d.name], { stdio: [input, quiet ? "ignore" : "inherit", "inherit"] });
  } finally {
    fs.closeSync(input);
  }
  routines();
}

/**
 * The stored procedure every journal entry posts through (post_journal_entry, migration 004). The live
 * export (phpMyAdmin « Quick ») carries the triggers but no routines, so on a copy loaded from it no
 * entry could post: a reception answered « Erreur serveur » (found filming Gestion des Achats,
 * 18/11/2026). Recreated from the migration's own text after every load and restore.
 */
function routines() {
  const d = guard();
  const src = fs.readFileSync(path.join(ROOT, "migrations", "004_double_entry_trigger.sql"), "utf8");
  const block = /DROP PROCEDURE IF EXISTS post_journal_entry \/\/[\s\S]*?END\/\//.exec(src);
  if (!block) throw new Error("post_journal_entry not found in migration 004.");
  execFileSync(exe("mysql"), [...auth(d), "--default-character-set=utf8mb4", d.name],
    { input: `DELIMITER //\n${block[0]}\nDELIMITER ;\n`, stdio: ["pipe", "ignore", "inherit"] });
}

/** Apply the repository's pending migrations to the copy (the copy runs this branch's code). */
function migrate() {
  guard();
  execFileSync(PHP(), [path.join(ROOT, "scripts", "migrate.php")], { stdio: "inherit", env: { ...process.env, LPC_ENV_PATH: ENV_FILE } });
}

/**
 * Serve the copy: PHP's own server on 127.0.0.1:PORT with scripts/video/router.php standing in for
 * .htaccess, the app pointed at filming.env, and mail() handed to Mailpit (a local catcher, so the
 * copy can show a real reset email while nothing leaves this machine).
 */
function serve({ detached = false } = {}) {
  guard();
  const args = [
    "-d", "SMTP=127.0.0.1", "-d", "smtp_port=1025", "-d", "sendmail_from=bureau@lpc.cm",
    "-d", "upload_max_filesize=20M", "-d", "post_max_size=22M", "-d", "memory_limit=256M", "-d", "display_errors=0",
    "-S", `127.0.0.1:${PORT}`, "-t", ROOT, path.join(__dirname, "router.php"),
  ];
  return spawn(PHP(), args, { cwd: ROOT, env: { ...process.env, ...mailEnv() }, stdio: detached ? "ignore" : "inherit", detached });
}

/**
 * Courrier on the copy (scripts/video/mail-fake.php): the fake mail server's state and Praxis AI's written
 * answers live in VIDEO_HOME, and both are always on, so the copy can never reach mail.lpc.cm or Google.
 */
const MAIL_STATE = path.join(HOME, "mail-state.json");
const AI_REPLIES = path.join(HOME, "ai-replies.json");
function mailEnv() {
  return { LPC_ENV_PATH: ENV_FILE, LPC_VIDEO_MAIL_STATE: MAIL_STATE, LPC_VIDEO_AI_REPLIES: AI_REPLIES };
}

/** Run scripts/video/mail.php on the copy: `seed <file>`, `sync`, `send`. Returns its output. */
function mail(...args) {
  guard();
  return execFileSync(PHP(), [path.join(__dirname, "mail.php"), ...args], { cwd: ROOT, env: { ...process.env, ...mailEnv() }, encoding: "utf8" });
}

/** mail(), without stopping the camera: a shot that sends or syncs mid-take keeps recording while PHP works. */
function mailAsync(...args) {
  guard();
  return new Promise((resolve, reject) => {
    require("node:child_process").execFile(PHP(), [path.join(__dirname, "mail.php"), ...args], { cwd: ROOT, env: { ...process.env, ...mailEnv() }, encoding: "utf8" },
      (err, stdout, stderr) => (err ? reject(new Error(`${err.message}\n${stderr}`)) : resolve(stdout)));
  });
}

/** The written answers Praxis AI gives on the copy (a scene writes its own before filming). */
function aiReplies(list) {
  fs.writeFileSync(AI_REPLIES, JSON.stringify(list, null, 1));
  try { fs.unlinkSync(AI_REPLIES + ".unmatched.jsonl"); } catch { /* none yet */ }
}

/** Mailpit's newest message to `to` (subject, HTML), for a shot that shows an email. */
async function mailTo(to, { subject } = {}) {
  const list = await fetch("http://127.0.0.1:8025/api/v1/messages?limit=50").then((r) => r.json());
  const hit = list.messages.find((m) => m.To.some((t) => t.Address.toLowerCase() === to.toLowerCase()) && (!subject || subject.test(m.Subject)));
  if (!hit) return null;
  const full = await fetch(`http://127.0.0.1:8025/api/v1/message/${hit.ID}`).then((r) => r.json());
  // A plain-text message (the app's reset email is one) is shown as a mail reader shows it: the words
  // as written, line breaks kept, in a readable face, links underlined.
  const esc = (t) => String(t || "").replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
  const text = `<div style="padding:28px 34px;font:17px/1.65 Inter,system-ui,sans-serif;color:#1d2b24;white-space:pre-wrap;word-break:break-word">${
    esc(full.Text).replace(/(https?:\/\/\S+)/g, '<span style="color:#005A2B;text-decoration:underline">$1</span>')}</div>`;
  return { to, subject: full.Subject, html: full.HTML || text, at: full.Date };
}

module.exports = { ROOT, HOME, ENV_FILE, ORIGIN, PORT, DB_PORT, PASSWORD, PHP, env, db, guard, sql, quote, ready, snapshot, restore, load, routines, migrate, serve, mailTo, mail, mailAsync, aiReplies, MAIL_STATE, AI_REPLIES };
