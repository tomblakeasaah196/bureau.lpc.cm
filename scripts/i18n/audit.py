#!/usr/bin/env python3
"""Audit untranslated UI strings in the Bureau LPC ERP templates.

Splits each file into PHP and HTML regions first, so that:
  * quoted-literal detection only runs inside PHP (no matching across HTML
    attribute boundaries, which produced junk like `">Debit</th> <th class="`)
  * text-node detection only runs inside HTML
Comments are stripped from PHP regions so docblock prose is never reported.

Each hit is classified:
  T          already routed through __t()
  TERNARY    already bilingual via a $lang/$en ternary
  HARDCODED  single-language PHP literal   -> needs work
  HTMLTEXT   single-language HTML text node -> needs work
"""
import re, os, sys, json, collections

ROOT = '/sessions/exciting-brave-edison/mnt/bureau.lpc.cm'
DIRS = ['modules', 'includes/components']

ACCENT = re.compile(r'[éèêëàâçùûôöîïœÉÈÊÀÇÔÎ]')
FRWORD = re.compile(r'\b(le|la|les|des|une|un|du|de|pour|avec|par|dans|sur|aucun|aucune|'
                    r'tous|toutes|nouveau|nouvelle|ajouter|modifier|supprimer|enregistrer|'
                    r'annuler|rechercher|retour|voir|liste|nom|montant|statut|actions|'
                    r'clients|facture|factures|stock|commande|commandes|fournisseur|solde|'
                    r'paiement|valider|quantite|methode|periode|categorie|details)\b', re.I)
TERNARY = re.compile(r"\$(?:lang\s*===?\s*'(?:en|fr)'|_*[a-zA-Z]*[eE]n)\s*\?")
# Dotted identifiers (permissions, i18n keys, css-ish tokens) are never UI copy.
IDENT = re.compile(r'^[a-z0-9_]+(?:\.[a-z0-9_]+)+$')


def regions(src):
    """Yield (kind, start, end) for 'php' and 'html' spans."""
    out, i, n = [], 0, len(src)
    while i < n:
        j = src.find('<?', i)
        if j == -1:
            out.append(('html', i, n)); break
        if j > i:
            out.append(('html', i, j))
        k = j + (5 if src.startswith('<?php', j) else 3 if src.startswith('<?=', j) else 2)
        # walk to the matching ?>, honouring strings/comments/heredocs
        while k < n:
            c = src[k]
            if src.startswith('?>', k):
                k += 2; break
            if src.startswith('<<<', k):
                m = re.match(r"<<<[ \t]*(?:'([A-Za-z_]\w*)'|\"?([A-Za-z_]\w*)\"?)\r?\n", src[k:])
                if m:
                    tag = m.group(1) or m.group(2)
                    e = re.search(r"^\s*" + tag + r"\b", src[k + m.end():], re.M)
                    k = n if not e else k + m.end() + e.end(); continue
            if c in '\'"':
                q, k2 = c, k + 1
                while k2 < n and src[k2] != q:
                    k2 += 2 if src[k2] == '\\' else 1
                k = k2 + 1; continue
            if src.startswith('/*', k):
                e = src.find('*/', k + 2); k = n if e == -1 else e + 2; continue
            if src.startswith('//', k) or (c == '#' and not src.startswith('#[', k)):
                e = src.find('\n', k); k = n if e == -1 else e; continue
            k += 1
        out.append(('php', j, k)); i = k
    return out


def french(t):
    return bool(ACCENT.search(t) or FRWORD.search(t))


def scan(path):
    src = open(path, encoding='utf-8', errors='replace').read()
    lines = src.split('\n')
    hits = []
    for kind, a, b in regions(src):
        chunk = src[a:b]
        if kind == 'php':
            # blank out comments so docblocks are not scanned
            chunk = re.sub(r'/\*.*?\*/', lambda m: ' ' * len(m.group()), chunk, flags=re.S)
            chunk = re.sub(r'(^|\s)(//|\#(?!\[))[^\n]*',
                           lambda m: m.group(1) + ' ' * (len(m.group()) - len(m.group(1))), chunk)
            for m in re.finditer(r"'((?:[^'\\\n]|\\.){3,140})'|\"((?:[^\"\\\n]|\\.){3,140})\"", chunk):
                lit = m.group(1) if m.group(1) is not None else m.group(2)
                if not french(lit) or lit.strip().startswith(('/', '#', 'http', '$')):
                    continue
                if IDENT.match(lit.strip()):
                    continue
                ln = src[:a + m.start()].count('\n')
                ctx = '\n'.join(lines[max(0, ln - 2):ln + 2])
                k = 'T' if '__t(' in ctx else 'TERNARY' if TERNARY.search(ctx) else 'HARDCODED'
                hits.append({'line': ln + 1, 'kind': k, 'text': lit,
                             'start': a + m.start(), 'end': a + m.end(),
                             'quote': "'" if m.group(1) is not None else '"'}) 
        else:
            for m in re.finditer(r'>([^<>\n]{3,140})<', chunk):
                t = m.group(1).strip()
                if not t or not french(t) or '$' in t:
                    continue
                ln = src[:a + m.start()].count('\n')
                raw = m.group(1)
                lead = len(raw) - len(raw.lstrip())
                trail = len(raw) - len(raw.rstrip())
                hits.append({'line': ln + 1, 'kind': 'HTMLTEXT', 'text': t,
                             'start': a + m.start(1) + lead,
                             'end': a + m.end(1) - trail})
    return hits


def main():
    report, tally = {}, collections.Counter()
    for d in DIRS:
        for dp, _, fn in os.walk(os.path.join(ROOT, d)):
            for f in sorted(fn):
                if not f.endswith('.php'):
                    continue
                p = os.path.join(dp, f)
                h = scan(p)
                if h:
                    report[os.path.relpath(p, ROOT)] = h
                    for x in h:
                        tally[x['kind']] += 1
    report = dict(sorted(report.items()))
    if '--json' in sys.argv:
        print(json.dumps(report, ensure_ascii=False, indent=1)); return
    for rel, h in report.items():
        c = collections.Counter(x['kind'] for x in h)
        todo = c['HARDCODED'] + c['HTMLTEXT']
        if todo or '--all' in sys.argv:
            print(f"{rel:52} todo={todo:<4} " + '  '.join(f"{k}={v}" for k, v in sorted(c.items())))
    print('\nTOTALS:', dict(tally))
    print('Needs work:', tally['HARDCODED'] + tally['HTMLTEXT'])


if __name__ == '__main__':
    main()
