#!/usr/bin/env python3
"""Second pass: UI text the French-accent heuristic missed, with exact spans.

Reports every HTML text node plus <title> and $pageTitle/$pageSubtitle values,
so coverage is decided by review rather than by a language guess. <script> and
<style> bodies are skipped so JavaScript is never rewritten.
"""
import re, os, sys, json, collections
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from audit import regions, ROOT, DIRS

NOISE = re.compile(r'^(?:[\d\s.,:%/+\-*()\[\]#&|]+|[A-Za-z]{1,2}|&\w+;|[^\w]*)$')
TECH = re.compile(r'^(?:https?:|/|\.|#|\$|[a-z0-9_]+(?:\.[a-z0-9_]+)+$|[a-z-]+/[a-z-]+)')


def interesting(t):
    t = t.strip()
    if not t or len(t) < 2 or NOISE.match(t) or TECH.match(t) or '<?' in t:
        return False
    return bool(re.search(r'[A-Za-zÀ-ÿ]{2,}', t))


def blocked(src):
    """Spans of <script>/<style> bodies — never rewrite these."""
    out = []
    for m in re.finditer(r'<(script|style)\b[^>]*>(.*?)</\1>', src, re.S | re.I):
        out.append((m.start(2), m.end(2)))
    return out


def scan(path):
    src = open(path, encoding='utf-8', errors='replace').read()
    skip = blocked(src)
    def inskip(i):
        return any(a <= i < b for a, b in skip)

    hits, seen = [], set()
    for m in re.finditer(r"\$page(?:Title|Subtitle)\s*=\s*'([^']*)'", src):
        if interesting(m.group(1)):
            hits.append({'kind': 'PHPSTR', 'text': m.group(1).strip(),
                         'start': m.start(1) - 1, 'end': m.end(1) + 1})
            seen.add(m.start(1))
    for kind, a, b in regions(src):
        if kind != 'html':
            continue
        for m in re.finditer(r'>([^<>\n]{2,140})<', src[a:b]):
            raw = m.group(1)
            t = raw.strip()
            if not interesting(t):
                continue
            s0 = a + m.start(1) + (len(raw) - len(raw.lstrip()))
            e0 = a + m.end(1) - (len(raw) - len(raw.rstrip()))
            if inskip(s0) or s0 in seen:
                continue
            seen.add(s0)
            hits.append({'kind': 'HTMLTEXT', 'text': t, 'start': s0, 'end': e0})
    return hits


def main():
    out = collections.OrderedDict()
    for d in DIRS:
        for dp, _, fn in os.walk(os.path.join(ROOT, d)):
            for f in sorted(fn):
                if f.endswith('.php'):
                    p = os.path.join(dp, f)
                    h = scan(p)
                    if h:
                        out[os.path.relpath(p, ROOT)] = h
    if '--json' in sys.argv:
        print(json.dumps(out, ensure_ascii=False, indent=1)); return
    c = collections.Counter()
    for rel, h in out.items():
        print(f"{rel:52} {len(h)}")
        for x in h:
            c[x['text']] += 1
    print("\nTOTAL", sum(c.values()), "UNIQUE", len(c))


if __name__ == '__main__':
    main()
