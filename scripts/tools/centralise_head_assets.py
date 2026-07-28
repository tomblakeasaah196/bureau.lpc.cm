#!/usr/bin/env python3
"""
scripts/tools/centralise_head_assets.py
------------------------------------------------------------------------------
Move every shell page's <head> asset tags into includes/components/head_assets.php
so all CSS/JS URLs go through lpc_asset() and carry ?v=<mtime>.

Per page:
  · delete its own  <link href="/assets/css/tailwind.css">
  · delete its own  <link href="/assets/css/lpc-shell.css">
  · delete its own  sidebar-collapse <script> snippet
  · delete its existing head_assets.php require (wherever it sat)
  · insert the head_assets.php require as the LAST thing before </head>

That last point fixes three real ordering bugs found on 28 July 2026:
  · accounting/invoices.php and inventory/stock.php required head_assets AFTER
    their own lpc-shell.css link, so tailwind.css loaded last and beat the shell
  · admin/error_monitor.php never required head_assets at all

Asserted: aborts without writing if any page doesn't match cleanly.

    python3 scripts/tools/centralise_head_assets.py --check
"""
import re
import sys
import pathlib

ROOT = pathlib.Path(__file__).resolve().parents[2]
CHECK = "--check" in sys.argv

REQUIRE_LINE = ("<?php require $_SERVER['DOCUMENT_ROOT'] . "
                "'/includes/components/head_assets.php'; ?>")

RX_TAILWIND = re.compile(r'^[ \t]*<link rel="stylesheet" href="/assets/css/tailwind\.css">[ \t]*\n', re.M)
RX_SHELLCSS = re.compile(r'^[ \t]*<link rel="stylesheet" href="/assets/css/lpc-shell\.css">[ \t]*\n', re.M)
RX_COLLAPSE = re.compile(r"^[ \t]*<script>\(function\(\)\{try\{if\(localStorage\.getItem\('lpc\.sidebar\.collapsed'\).*?\}\)\(\);</script>[ \t]*\n", re.M)
RX_HEADREQ  = re.compile(r"^[ \t]*<\?php require (?:\$_SERVER\['DOCUMENT_ROOT'\] \. |__DIR__ \. )'[^']*head_assets\.php'; \?>[ \t]*\n", re.M)
RX_HEADEND  = re.compile(r'^([ \t]*)</head>', re.M)

pages = sorted(p for p in ROOT.glob('modules/**/*.php')
               if 'lpc-shell-main' in p.read_text(encoding='utf-8'))

errors, changes = [], {}

for path in pages:
    rel = path.relative_to(ROOT).as_posix()
    src = original = path.read_text(encoding='utf-8')

    head_end = RX_HEADEND.search(src)
    if not head_end:
        errors.append(f"{rel}: no </head>")
        continue

    n_tw = len(RX_TAILWIND.findall(src))
    n_sh = len(RX_SHELLCSS.findall(src))
    n_cp = len(RX_COLLAPSE.findall(src))
    n_hr = len(RX_HEADREQ.findall(src))

    if n_sh != 1:
        errors.append(f"{rel}: expected 1 lpc-shell.css link, found {n_sh}")
    if n_cp != 1:
        errors.append(f"{rel}: expected 1 collapse snippet, found {n_cp}")
    if n_tw > 1 or n_hr > 1:
        errors.append(f"{rel}: unexpected duplicates tailwind={n_tw} head_assets={n_hr}")
    if errors and errors[-1].startswith(rel):
        continue

    src = RX_TAILWIND.sub('', src)
    src = RX_SHELLCSS.sub('', src)
    src = RX_COLLAPSE.sub('', src)
    src = RX_HEADREQ.sub('', src)

    m = RX_HEADEND.search(src)
    if not m:
        errors.append(f"{rel}: lost </head> during rewrite")
        continue
    indent = m.group(1)
    src = src[:m.start()] + f"{indent}{REQUIRE_LINE}\n" + src[m.start():]

    # Every page must end up with exactly one require and no bare asset links.
    if len(RX_HEADREQ.findall(src)) != 1:
        errors.append(f"{rel}: require count wrong after rewrite")
    if RX_TAILWIND.search(src) or RX_SHELLCSS.search(src):
        errors.append(f"{rel}: bare asset link survived")

    if src != original:
        changes[rel] = src

if errors:
    print("ABORTED — nothing written:\n  " + "\n  ".join(errors))
    sys.exit(1)

print(f"{len(changes)}/{len(pages)} pages {'would change' if CHECK else 'written'}")
for rel, src in changes.items():
    if not CHECK:
        (ROOT / rel).write_text(src, encoding='utf-8')
    print("  ok   " + rel)
