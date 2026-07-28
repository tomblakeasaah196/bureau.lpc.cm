# Setting Up Automated Deployment — Step-by-Step Guide

This guide gets `bureau.lpc.cm` deploying itself automatically: every time
code is pushed to `main` on GitHub, GitHub Actions logs into the server and
runs the deploy for you. No more copy-pasting files by hand.

There are two things to set up, in order:

1. **A new SSH key**, generated on the server (cPanel), so GitHub can log
   into the server without a password.
2. **Four secrets in GitHub**, so the automated deploy knows how to reach
   the server and what key to use.

Total time: about 15 minutes. Nothing here is reversible-but-dangerous —
worst case, you generate a key you don't end up using, which costs nothing.

---

## Before you start

**A quick glossary**, since a few terms come up repeatedly:

- **SSH** — the protocol used to remotely log into a server and run
  commands. It's what lets GitHub's servers connect to your cPanel server
  and run the deploy script.
- **SSH key pair** — two matching files: a **private key** (secret, never
  shared, proves your identity) and a **public key** (safe to share,
  installed on the server to say "anyone with the matching private key is
  allowed in").
- **Repository secret** — a piece of sensitive information (like the
  private key) stored inside GitHub, encrypted, attached to your specific
  repository. GitHub Actions can read these while it runs, but no human
  browsing the repo can see the value again once saved — not even you.

**One rule that matters more than anything else in this guide:**

> ⚠️ **The private key never gets pasted into our chat.** Copy it straight
> from cPanel into GitHub's secret box. If a private key is ever pasted
> anywhere outside those two places — chat, a screenshot, a text file, an
> email — it has to be treated as compromised and regenerated, even if
> nothing bad actually happens with it. This exact thing happened earlier
> in this project with a previous key, which is why we're generating a
> completely new one now instead of reusing it.

**What you'll need open:**

- Your cPanel login (URL is usually something like `https://bureau.lpc.cm:2083`
  or a link from your hosting provider's welcome email)
- Your GitHub login, with access to `https://github.com/tomblakeasaah196/bureau.lpc.cm`

---

## Part 1 — Generate a new SSH key in cPanel

### Step 1.1 — Log into cPanel

Open your cPanel URL in a browser tab and log in. Keep this tab open for
the whole of Part 1.

### Step 1.2 — Find "SSH Access"

On the cPanel home screen, there's a search box near the top (it usually
says "Find a Setting..." or similar). Type **SSH** into it.

Click the result called **SSH Access**. (It normally lives under a
section called **Security** if you're scrolling manually instead of
searching.)

### Step 1.3 — Open key management

On the SSH Access page, look for a button or link called **Manage SSH
Keys**. Click it.

You'll land on a page with a list of any existing keys (there may already
be one from earlier — leave it alone, we're not touching it) and, below
or beside that list, two options: **Import Key** and **Generate a New
Key**.

Click **Generate a New Key**.

### Step 1.4 — Fill in the new key form

You'll see a form with a few fields. Fill them in like this:

| Field | What to enter |
|---|---|
| **Key Name** | Something you'll recognize later, e.g. `github-deploy-2026` — anything other than the name of the old key |
| **Key Password** / **Password (Again)** | **Leave both blank.** cPanel may show a warning that this is less secure — that's expected and fine here, because GitHub Actions has no way to type a password when it connects, so a passphrase-protected key would just fail every deploy. |
| **Key Type** | **ED25519** if it's offered (it's the modern, recommended type). If you don't see ED25519 as an option, choose **RSA** and set **Key Size** to **4096**. |

Click **Generate Key** (button text may say "Generate Key" or "Create").

You should see a success message. Click through it (often "Go Back") to
return to the key list.

### Step 1.5 — Authorize the new key

Back on the key list, find the key you just created. It will likely be
marked as **Not Authorized**.

Click **Manage** next to it (sometimes shown as an icon rather than the
word "Manage"). On the page that opens, click the **Authorize** button.

This single click copies the public half of the key into the server's
`~/.ssh/authorized_keys` file for you — the step that actually grants
access. You don't need to copy or paste anything for this part.

Once done, the key's status should flip to **Authorized**.

> If you don't see an "Authorize" button and instead see "Deauthorize",
> you're already done with this step — it means it authorized
> automatically when generated on your cPanel version.

### Step 1.6 — Note your connection details

You need three more pieces of information: the **host**, the **port**,
and your **SSH username**. Where to find them depends on your cPanel
theme:

- Some cPanel themes show a box on the SSH Access page itself with an
  example command like `ssh yourusername@yourserver.com -p 21098` — if
  you see something like that, the pieces you need are right there.
- If not, check your hosting provider's original welcome/setup email —
  it usually states the SSH hostname and port explicitly, since shared
  hosting almost never uses the default port 22.
- Your **SSH username is the same username you use to log into cPanel
  itself.** Based on how your databases are named in this project
  (`smartqaq_lpc_core`, `smartqaq_jbsoperations`), your cPanel/SSH
  username is very likely **`smartqaq`** — cPanel automatically prefixes
  database names with the account username. Worth double-checking against
  what you actually type to log into cPanel, but that's a strong hint.
- The **host** is often — but not always — the same as your domain
  (`bureau.lpc.cm`). Some hosts instead give you a separate server
  hostname like `server123.yourhost.com`. If you're not sure, your
  hosting provider's welcome email is the most reliable source.

Write these three values down somewhere temporarily (a sticky note app,
not this chat) — you'll paste them into GitHub in Part 2.

### Step 1.7 — Get the private key

Back on the SSH Access → Manage SSH Keys page, find your new
(now-authorized) key in the list again. There should be an option like
**View Private Key** (sometimes reached via the same "Manage" page from
Step 1.5, sometimes a separate link).

Click it. cPanel will display the full private key as text, starting
with a line like:

```
-----BEGIN OPENSSH PRIVATE KEY-----
```

and ending with:

```
-----END OPENSSH PRIVATE KEY-----
```

**Select and copy the entire block, including both of those BEGIN/END
lines.** If there's a "Copy" or "Download Key" button, that works too —
either way, keep this in your clipboard and go straight to Part 2, don't
paste it anywhere in between.

---

## Part 2 — Add the secrets to GitHub

### Step 2.1 — Open the repository settings

Go to `https://github.com/tomblakeasaah196/bureau.lpc.cm` and log in if
needed.

Click the **Settings** tab (top of the repo page, in the row with Code /
Issues / Pull requests / etc. — you may need to click a **⚙** or a `…`
menu if the window is narrow).

> If you don't see a "Settings" tab at all, you're not logged in as the
> repository owner/an account with admin rights on it — make sure you're
> logged into the GitHub account that created the repo.

### Step 2.2 — Navigate to Actions secrets

In the left sidebar of the Settings page, find **Secrets and variables**
and click it to expand it, then click **Actions**.

You'll land on a page titled something like **Actions secrets and
variables**, with a **New repository secret** button near the top right.

### Step 2.3 — Add the first secret: the private key

Click **New repository secret**.

Two fields appear:

- **Name** — type exactly: `DEPLOY_SSH_KEY`
  (capitalization and underscores matter — this must match exactly)
- **Secret** — paste the private key you copied in Step 1.7, the whole
  block including the BEGIN/END lines

Click **Add secret**.

### Step 2.4 — Add the remaining three secrets

Repeat **New repository secret** three more times, once for each of
these (name on the left, value is whatever you noted in Step 1.6):

| Name (type exactly) | Value |
|---|---|
| `DEPLOY_SSH_HOST` | the host/server name from Step 1.6 |
| `DEPLOY_SSH_USER` | your SSH username from Step 1.6 |
| `DEPLOY_SSH_PORT` | the port number from Step 1.6 (just digits, e.g. `21098`) |

### Step 2.5 — Confirm all four are there

Once done, the secrets list on that page should show four entries:

```
DEPLOY_SSH_KEY
DEPLOY_SSH_HOST
DEPLOY_SSH_USER
DEPLOY_SSH_PORT
```

GitHub never shows the values again after saving (by design) — you'll
only ever see the names, with "Updated X minutes ago" beside each. That's
normal and expected; it's not a sign anything went wrong.

---

## What to tell me when you're done

Just confirm the four secret **names** exist (never the values) — for
example: *"all 4 are added."*

Separately, whenever you've closed whatever program had the
`bureau.lpc.cm` folder open on your machine (GitHub Desktop, an editor,
etc.), let me know that too. Once both of those are true, I'll:

1. Clear the stuck lock file on my end
2. Commit and push everything that's currently sitting ready to go
   (the app-shell rollout, the notifications backend, all of it)
3. That push triggers the first real automated deploy — I'll watch the
   result with you and we'll fix anything that comes up together

---

## Troubleshooting

**"My cPanel looks completely different from what's described."**
cPanel themes vary by hosting provider. The names may differ slightly
(e.g. "SSH Access Manager" instead of "SSH Access"), but every modern
cPanel has some form of SSH key management — search for "SSH" in
cPanel's search bar and you should land on it regardless of theme.

**"There's no ED25519 option, only RSA and DSA."**
Use RSA with key size 4096. It's slightly older but still fully secure
and fully supported by GitHub Actions — nothing later in this guide
changes.

**"I can't find the host/port anywhere."**
Contact your hosting provider's support and ask specifically for "the
SSH hostname and port for my account" — this is a common support request
and they'll have it on hand immediately.

**"I think I made a mistake on one of the 4 secrets."**
No problem — click on the secret's name in GitHub, there's an "Update"
option that lets you overwrite the value. No need to delete and recreate.

**"Should I delete the old, exposed SSH key from cPanel while I'm in
there?"**
Yes, worth doing for cleanliness — on the same "Manage SSH Keys" page,
find the old key and use **Manage → Deauthorize**, then delete it. It's
already being treated as unusable, this just removes it from the server
entirely so it can't be used even in theory.
