#!/usr/bin/env python3
"""End-to-end verification of the translation sweep."""
import re, os, sys, collections

ROOT = '/sessions/exciting-brave-edison/mnt/bureau.lpc.cm'
DICT = os.path.join(ROOT, 'includes/config/i18n_dictionaries.php')
src = open(DICT, encoding='utf-8').read()
fail = 0


def block(lang):
    m = re.search(r"'" + lang + r"'\s*=>\s*\[", src)
    i = m.end(); d = 1; j = i
    while d > 0:
        if src[j] == '[': d += 1
        elif src[j] == ']': d -= 1
        j += 1
    return src[i:j - 1]


def pairs(lang):
    # values appear in both single and double quotes in this file
    out = []
    for m in re.finditer(r"'((?:[^'\\]|\\.)*)'\s*=>\s*(?:'((?:[^'\\]|\\.)*)'|\"((?:[^\"\\]|\\.)*)\")",
                         block(lang)):
        out.append((m.group(1), m.group(2) if m.group(2) is not None else m.group(3)))
    return out


fr_p, en_p = pairs('fr'), pairs('en')
fr, en = dict(fr_p), dict(en_p)
print(f"dictionary: fr={len(fr)}  en={len(en)}")

# 1. duplicate keys (PHP would silently keep the last one)
for lang, p in (('fr', fr_p), ('en', en_p)):
    dup = [k for k, n in collections.Counter(k for k, _ in p).items() if n > 1]
    if dup:
        fail += 1
        print(f"FAIL duplicate keys in {lang}: {dup[:10]}")
    else:
        print(f"  ok  no duplicate keys in {lang}")

# 2. parity
only_fr, only_en = set(fr) - set(en), set(en) - set(fr)
if only_fr or only_en:
    fail += 1
    print(f"FAIL parity — fr-only {len(only_fr)} {sorted(only_fr)[:5]}, "
          f"en-only {len(only_en)} {sorted(only_en)[:5]}")
else:
    print("  ok  every key exists in both fr and en")

# 3. every __t() key used in code actually exists
used = collections.Counter()
missing = collections.defaultdict(list)
for dp, dn, fn in os.walk(ROOT):
    dn[:] = [d for d in dn if d not in ('node_modules', 'vendor', '.git', 'uploads')]
    for f in fn:
        if not f.endswith('.php'):
            continue
        p = os.path.join(dp, f)
        if os.path.samefile(p, DICT):
            continue
        body = open(p, encoding='utf-8', errors='replace').read()
        # drop comments so docblock examples are not counted as call sites
        body = re.sub(r'/\*.*?\*/', '', body, flags=re.S)
        body = re.sub(r'^\s*//[^\n]*', '', body, flags=re.M)
        for m in re.finditer(r"__t\(\s*'((?:[^'\\]|\\.)*)'", body):
            k = m.group(1)
            used[k] += 1
            if k not in fr:
                missing[os.path.relpath(p, ROOT)].append(k)
print(f"  __t() call sites: {sum(used.values())} using {len(used)} distinct keys")
if missing:
    fail += 1
    print(f"FAIL {sum(len(v) for v in missing.values())} calls reference keys not in the dictionary")
    for r, ks in list(missing.items())[:5]:
        print(f"       {r}: {sorted(set(ks))[:4]}")
else:
    print("  ok  every __t() key resolves")

# 4. English values that are still identical to French (possible misses)
same = [k for k in fr if k in en and fr[k] == en[k] and re.search(r'[a-zA-Z]{3}', fr[k])]
untranslated = [k for k in same if re.search(r'[éèêàçùôîïœ]', fr[k])]
print(f"  fr==en values: {len(same)} (of which still carry French accents: {len(untranslated)})")
if untranslated:
    fail += 1
    print("FAIL accented strings left untranslated in EN:", untranslated[:10])
else:
    print("  ok  no accented French left in the EN dictionary")

# 5. unused keys (informational)
unused = [k for k in fr if k not in used]
print(f"  keys never referenced by __t(): {len(unused)} (informational)")

print("\nRESULT:", "FAIL" if fail else "PASS")
sys.exit(1 if fail else 0)
