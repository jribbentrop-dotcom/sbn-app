"""
Restore the Billie Holiday arrangement into version v59 (billie-holiday) from the
pre-corruption backup (leadsheet 596). The current v59 json_data was overwritten by
a Joe-Pass-era save before the apiShow ?v= fix.

Usage:
  python scripts/restore_billie_version.py            # dry-run
  python scripts/restore_billie_version.py --commit   # apply
"""
import sqlite3, hashlib, sys
def md5(s): return hashlib.md5((s or '').encode()).hexdigest()[:12]

COMMIT = '--commit' in sys.argv
SRC = 'database/sbn.db.bak-merge-20260623-195014'

src = sqlite3.connect(SRC)
b_key, b_json, b_tab, b_sc = src.execute(
    "SELECT song_key, json_data, tab_xml, shortcode_content FROM sbn_leadsheets WHERE id=596"
).fetchone()
src.close()

live = sqlite3.connect('database/sbn.db')
v = live.execute(
    "SELECT id, leadsheet_id, song_key, json_data FROM sbn_leadsheet_versions WHERE version_slug='billie-holiday'"
).fetchone()
if not v:
    print("!! no billie-holiday version found"); sys.exit(1)
vid, lid = v[0], v[1]

print(f"target version v{vid} (leadsheet {lid})")
print(f"  before: key={v[2]} json_md5={md5(v[3])} len={len(v[3])}")
print(f"  after : key={b_key} json_md5={md5(b_json)} len={len(b_json)}  (restored from backup 596)")

if not COMMIT:
    print("\n(dry-run — re-run with --commit)")
    live.close(); sys.exit(0)

live.execute(
    "UPDATE sbn_leadsheet_versions SET song_key=?, json_data=?, melody_tab_xml=?, shortcode_content=? WHERE id=?",
    (b_key, b_json, b_tab, b_sc, vid)
)
live.commit()
after = live.execute("SELECT song_key, json_data FROM sbn_leadsheet_versions WHERE id=?", (vid,)).fetchone()
print(f"\nRESTORED. now: key={after[0]} json_md5={md5(after[1])} len={len(after[1])}")
print("NOTE: re-run progression/voicing detection for this version (php artisan or admin re-save).")
live.close()
