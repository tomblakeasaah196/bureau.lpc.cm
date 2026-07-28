#!/usr/bin/env python3
"""Structural sanity check for the PHP templates (no php binary in this sandbox).

Tokenises each file into PHP vs HTML regions, skipping strings and comments,
then verifies braces/parens/brackets balance and that no string is left open.
Catches the realistic failure mode of scripted edits: an unescaped quote or a
dropped delimiter.
"""
import os, sys, re

ROOT = '/sessions/exciting-brave-edison/mnt/bureau.lpc.cm'
SKIP = ('node_modules', 'vendor', '.git', 'uploads')


def check(path):
    s = open(path, encoding='utf-8', errors='replace').read()
    i, n = 0, len(s)
    inphp = False
    stack = []
    errs = []
    while i < n:
        if not inphp:
            m = s.find('<?php', i)
            m2 = s.find('<?=', i)
            cands = [x for x in (m, m2) if x != -1]
            if not cands:
                break
            i = min(cands)
            i += 5 if i == m and m != -1 and s.startswith('<?php', i) else 3
            inphp = True
            continue
        c = s[i]
        if c == '?' and s.startswith('?>', i):
            inphp = False; i += 2; continue
        if c == '/' and s.startswith('//', i):
            i = s.find('\n', i);  i = n if i == -1 else i; continue
        if c == '#' and not s.startswith('#[', i):
            i = s.find('\n', i);  i = n if i == -1 else i; continue
        if c == '/' and s.startswith('/*', i):
            j = s.find('*/', i + 2); i = n if j == -1 else j + 2; continue
        if c == '<' and s.startswith('<<<', i):
            m = re.match(r"<<<[ \t]*(?:'([A-Za-z_]\w*)'|\"?([A-Za-z_]\w*)\"?)\r?\n", s[i:])
            if m:
                tag = m.group(1) or m.group(2)
                end = re.search(r"^\s*" + tag + r"\b", s[i + m.end():], re.M)
                i = n if not end else i + m.end() + end.end()
                continue
        if c in '\'"':
            q = c; j = i + 1
            while j < n:
                if s[j] == '\\': j += 2; continue
                if s[j] == q: break
                j += 1
            if j >= n:
                errs.append(f"unterminated {q} string opened at line {s[:i].count(chr(10))+1}")
                break
            i = j + 1; continue
        if c in '([{':
            stack.append((c, s[:i].count('\n') + 1)); i += 1; continue
        if c in ')]}':
            if not stack:
                errs.append(f"unmatched '{c}' at line {s[:i].count(chr(10))+1}")
            else:
                o, ln = stack.pop()
                if '([{'.index(o) != ')]}'.index(c):
                    errs.append(f"mismatched '{o}' (line {ln}) vs '{c}' (line {s[:i].count(chr(10))+1})")
            i += 1; continue
        i += 1
    if stack:
        o, ln = stack[-1]
        errs.append(f"unclosed '{o}' opened at line {ln}")
    return errs


bad = 0
count = 0
for dp, dn, fn in os.walk(ROOT):
    dn[:] = [d for d in dn if d not in SKIP]
    for f in fn:
        if not f.endswith('.php'):
            continue
        p = os.path.join(dp, f)
        count += 1
        e = check(p)
        if e:
            bad += 1
            print(f"FAIL {os.path.relpath(p, ROOT)}")
            for x in e[:3]:
                print("      ", x)
print(f"\nchecked {count} php files — {bad} with structural problems")
sys.exit(1 if bad else 0)
