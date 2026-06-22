# -*- coding: utf-8 -*-
"""
One-off: set license_status + is_pro across sbn_leadsheets per the
free-reference / SBNpro model.

Rules:
  is_pro = true  -> curated material with full viewer/cinema arrangement.
                    Only ever true for public_domain titles.
  is_pro = false -> free reference page only (edu text + top voicings + generic
                    progressions). Used for copyrighted standards.

license_status: legal record (US pub year + 95). 2026 cutoff => pub <= 1930 PD.
This is an editorial best-effort, not legal advice; review before going live.
"""
import sqlite3, os, sys

db = os.path.join(os.path.dirname(__file__), '..', 'database', 'sbn.db')
con = sqlite3.connect(db)
con.row_factory = sqlite3.Row
cur = con.cursor()

PD   = 'public_domain'
COP  = 'copyrighted'
UNK  = 'unknown'

# --- Explicit license calls by slug -----------------------------------------
# PUBLIC DOMAIN: traditional/folk/classical + pre-1931 standards.
public_domain = {
    # traditional / folk / classical (clearly PD)
    'amazing-grace', 'canarios', 'estudio', 'greensleeves', 'ode-to-joy',
    'romance', 'scarborough-fair', 'shenandoah', 'st-james-infirmary',
    'vals', 'wellerman', 'londonderry-air', 'in-the-hall-of-the-mountain-king',
    'greensleeves', 'canon-in-d', 'gymnopedie-1', 'exercise-in-c',
    'dream-a-little-dream',  # 1931? treat below if needed
    # pre-1931 jazz standards -> PD by 2026 (pub year + 95 <= 2026)
    'body-and-soul',                    # 1930
    'love-for-sale',                    # 1930
    'swonderful',                       # 1927
    'what-is-this-thing-called-love',   # 1929
    'georgia-on-my-mind',               # 1930
    'gee-baby-aint-i-good-to-you',      # 1929
    'the-birth-of-the-blues',           # 1926
    'i-cant-give-you-anything-but-love',# 1928
    'mack-the-knive',                   # 1928 (Die Dreigroschenoper)
    'tico-tico',                        # 1917
    'nesta-rua',                        # traditional Brazilian
}

# COPYRIGHTED: post-1930, still protected (incl. life+70 jurisdictions).
copyrighted = {
    'por-una-cabeza',          # 1935
    'ill-remember-april',      # 1942
    # --- draft standards (mostly bossa/jazz, all copyrighted) ---
    'acapulco', 'agua-de-beber', 'aquarela-do-brasil', 'as-time-goes-by',
    'avarandado', 'blue-bossa', 'body-and-soul-1', 'brigas-nunca-mais',
    'chega-de-saudade', 'corcovado', 'desafinado', 'dindi',
    'e-preciso-perdoar', 'fotografia', 'gentle-rain-the',
    'girl-from-ipanema-the', 'incompatibilidade-de-genios', 'insensatez',
    'httpsyoutubeevpzxhuqvpy',  # Love For Sale dupe (copyrighted arrangement)
    'manha-de-carnaval', 'maria-luisa', 'moon-and-sand', 'night-and-day',
    'on-green-dolphin-street', 'once-i-loved', 'one-note-samba',
}

# Add the remaining standards (researched as unknown) to copyrighted, per
# decision: conservative classification, publish as free reference.
copyrighted |= {
    'the-girl-from-ipanema', 'wave', 'song-for-my-father',
    'watch-what-happens', 'the-shadow-of-your-smile', 'so-danco-samba',
    'samba-da-bencao', 'the-man-i-love', 'sons-de-carrilhoes',
    'once-i-loved',
}

# Junk / non-song / duplicate rows: never publish, stay draft, license unknown.
SKIP_PUBLISH = {
    'top10',                    # not a song
    'untitled',                 # "Without a Song" placeholder/test row
    'httpsyoutubeevpzxhuqvpy',  # malformed Love For Sale dupe (slug is a URL)
    'body-and-soul-1',          # duplicate of published body-and-soul
}

def classify(slug):
    if slug in public_domain:
        return PD
    if slug in copyrighted:
        return COP
    return UNK

rows = cur.execute("SELECT id, slug, title, status, is_pro, license_status FROM sbn_leadsheets").fetchall()

changes = []
for r in rows:
    slug = r['slug']
    lic = classify(slug)

    # Publish everything (free reference at minimum); copyrighted+unknown stay
    # is_pro=false. PD that is already published keeps its full arrangement
    # (is_pro=true); PD drafts get published as reference but NOT auto-pro
    # (curating the full arrangement is a separate manual step).
    if slug in SKIP_PUBLISH:
        # Junk/dupe/non-song rows: never publish, never pro.
        new_status = r['status']
        new_is_pro = 0
    else:
        new_status = 'publish'
        # is_pro only for PD that already had a published full arrangement.
        # PD drafts publish as reference; curating their full arrangement +
        # flipping is_pro is a separate manual step.
        new_is_pro = 1 if (lic == PD and r['status'] == 'publish') else 0

    if (lic != r['license_status']) or (new_is_pro != (r['is_pro'] or 0)) or (new_status != r['status']):
        changes.append((r['id'], slug, r['title'], r['status'], new_status,
                        r['is_pro'], new_is_pro, r['license_status'], lic))

dry = '--apply' not in sys.argv
print("DRY RUN" if dry else "APPLYING", f"-- {len(changes)} rows change\n")
unk = [c for c in changes if c[8] != UNK and c[8] != c[8]]  # noop
unknowns = [r for r in rows if classify(r['slug']) == UNK]
print(f"{'ID':>4} {'slug':<32} {'status':>14} {'is_pro':>10}  license")
for c in changes:
    cid, slug, title, st0, st1, pro0, pro1, lic0, lic1 = c
    print(f"{cid:>4} {slug:<32} {st0:>6}->{st1:<6} {str(pro0):>4}->{str(pro1):<4}  {lic0}->{lic1}")

if unknowns:
    print(f"\n!! {len(unknowns)} still 'unknown' (review manually, will publish as is_pro=0):")
    for r in unknowns:
        print("   ", r['slug'], '-', r['title'])

if not dry:
    for c in changes:
        cur.execute("UPDATE sbn_leadsheets SET status=?, is_pro=?, license_status=? WHERE id=?",
                    (c[4], c[6], c[8], c[0]))
    con.commit()
    print("\nCommitted.")
con.close()
