"""
Merge duplicate leadsheet rows into one song with multiple versions.

For each (primary, secondary) pair:
  1. Re-home the secondary's existing version row onto the primary leadsheet
     (new leadsheet_id, distinct version_slug, performer/label/difficulty).
  2. Re-point the secondary's detection-cache rows (occurrences/usage/drafts)
     leadsheet_id -> primary (version_id already correct).
  3. Set the primary's label/performer/difficulty on ITS basic version.
  4. Delete the now-empty secondary leadsheet row.

Reversible only via DB backup — take one before running with --commit.

Usage:
  python scripts/merge_song_versions.py            # dry-run preview
  python scripts/merge_song_versions.py --commit   # apply
"""
import sqlite3, sys

COMMIT = '--commit' in sys.argv
DB = 'database/sbn.db'

# (primary_id, primary_label, primary_performer, primary_difficulty),
# (secondary_id, secondary_slug, secondary_performer, secondary_difficulty)
MERGES = [
    {
        'primary':   {'id': 463, 'label': 'Joe Pass',       'performer': 'Joe Pass',       'difficulty': 4, 'slug': 'joe-pass'},
        'secondary': {'id': 596, 'label': 'Billie Holiday', 'performer': 'Billie Holiday', 'difficulty': 3, 'slug': 'billie-holiday'},
    },
    {
        'primary':   {'id': 551, 'label': 'Arrangement in Db', 'performer': None, 'difficulty': 3, 'slug': 'in-db'},
        'secondary': {'id': 592, 'label': 'Arrangement in F',  'performer': None, 'difficulty': 3, 'slug': 'in-f'},
    },
]

CACHES = ['sbn_progression_occurrences', 'sbn_voicing_usage', 'sbn_voicing_drafts']

db = sqlite3.connect(DB)
db.execute('PRAGMA foreign_keys=ON')

def one(sql, p=()):
    return db.execute(sql, p).fetchone()

for m in MERGES:
    P, S = m['primary'], m['secondary']
    print(f"\n=== merge secondary {S['id']} INTO primary {P['id']} ===")

    prim = one("SELECT slug, title, default_version_id FROM sbn_leadsheets WHERE id=?", (P['id'],))
    sec  = one("SELECT slug, title FROM sbn_leadsheets WHERE id=?", (S['id'],))
    if not prim or not sec:
        print("  !! one of the rows is missing — skipping"); continue
    print(f"  primary  : {prim[1]!r} (slug={prim[0]})")
    print(f"  secondary: {sec[1]!r} (slug={sec[0]})  -> will be DELETED")

    # secondary's version row
    sv = one("SELECT id, version_slug, song_key FROM sbn_leadsheet_versions WHERE leadsheet_id=?", (S['id'],))
    pv = one("SELECT id, version_slug FROM sbn_leadsheet_versions WHERE leadsheet_id=?", (P['id'],))
    print(f"  secondary version row {sv} -> re-home onto primary as slug={S['slug']!r}")
    print(f"  primary   version row {pv} -> relabel slug={P['slug']!r}")

    # cache rows to re-point
    for t in CACHES:
        c = one(f"SELECT COUNT(*) FROM {t} WHERE leadsheet_id=?", (S['id'],))[0]
        print(f"    {t}: {c} rows re-point leadsheet_id {S['id']}->{P['id']}")

    if not COMMIT:
        continue

    # 1. relabel primary's basic version
    db.execute(
        "UPDATE sbn_leadsheet_versions SET version_slug=?, label=?, performer=?, difficulty=? WHERE id=?",
        (P['slug'], P['label'], P['performer'], P['difficulty'], pv[0])
    )
    # 2. re-home secondary version onto primary
    db.execute(
        "UPDATE sbn_leadsheet_versions SET leadsheet_id=?, version_slug=?, label=?, performer=?, difficulty=? WHERE id=?",
        (P['id'], S['slug'], S['label'], S['performer'], S['difficulty'], sv[0])
    )
    # 3. re-point detection caches
    for t in CACHES:
        db.execute(f"UPDATE {t} SET leadsheet_id=? WHERE leadsheet_id=?", (P['id'], S['id']))
    # 4. delete secondary leadsheet
    db.execute("DELETE FROM sbn_leadsheets WHERE id=?", (S['id'],))
    print("  COMMITTED")

if COMMIT:
    db.commit()
    print("\nAll merges committed.")
else:
    print("\n(dry-run — re-run with --commit to apply)")

db.close()
