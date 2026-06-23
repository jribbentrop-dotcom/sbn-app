import sqlite3
db = sqlite3.connect('database/sbn.db')
def rows(sql, p=()): return db.execute(sql, p).fetchall()
def one(sql, p=()): return db.execute(sql, p).fetchone()[0]

print("deleted rows gone?")
for old in (596, 592):
    print(f"  leadsheet {old}:", "GONE" if not one("SELECT COUNT(*) FROM sbn_leadsheets WHERE id=?", (old,)) else "STILL PRESENT")

for pid in (463, 551):
    ls = one("SELECT title FROM sbn_leadsheets WHERE id=?", (pid,))
    dv = one("SELECT default_version_id FROM sbn_leadsheets WHERE id=?", (pid,))
    print(f"\n{ls!r} (id={pid}), default_version_id={dv}")
    for v in rows("SELECT id, version_slug, label, performer, difficulty, song_key FROM sbn_leadsheet_versions WHERE leadsheet_id=? ORDER BY difficulty", (pid,)):
        print(f"   version {v}")
    # default version still belongs to this leadsheet?
    ok = one("SELECT COUNT(*) FROM sbn_leadsheet_versions WHERE id=? AND leadsheet_id=?", (dv, pid))
    print(f"   default_version_id valid for this leadsheet: {'YES' if ok else 'NO — BROKEN'}")

print("\norphaned cache rows (leadsheet_id with no leadsheet)?")
for t in ['sbn_progression_occurrences','sbn_voicing_usage','sbn_voicing_drafts']:
    orph = one(f"SELECT COUNT(*) FROM {t} c LEFT JOIN sbn_leadsheets l ON c.leadsheet_id=l.id WHERE l.id IS NULL")
    nullv = one(f"SELECT COUNT(*) FROM {t} WHERE version_id IS NULL")
    print(f"  {t}: orphans={orph} null_version={nullv}")

# version_id rows must still belong to a version whose leadsheet matches the cache leadsheet_id
print("\ncache version_id <-> leadsheet_id consistency:")
for t in ['sbn_progression_occurrences','sbn_voicing_usage']:
    bad = one(f"""SELECT COUNT(*) FROM {t} c
                  JOIN sbn_leadsheet_versions v ON c.version_id=v.id
                  WHERE c.leadsheet_id != v.leadsheet_id""")
    print(f"  {t}: mismatched rows = {bad}")

db.close()
