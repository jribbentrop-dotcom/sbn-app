import sqlite3, os

db = os.path.join(os.path.dirname(__file__), '..', 'database', 'sbn.db')
con = sqlite3.connect(db)
con.row_factory = sqlite3.Row
cur = con.cursor()

cols = [r[1] for r in cur.execute("PRAGMA table_info(sbn_leadsheets)")]
has_new = 'is_pro' in cols
print("columns has is_pro/license_status:", has_new, 'license_status' in cols)
print()

sel = "id, slug, title, composer, status"
if has_new:
    sel += ", is_pro, license_status"
rows = cur.execute(f"SELECT {sel} FROM sbn_leadsheets ORDER BY status, title").fetchall()

pub = [r for r in rows if r['status'] == 'publish']
draft = [r for r in rows if r['status'] != 'publish']
print(f"TOTAL: {len(rows)}  |  publish: {len(pub)}  |  draft/other: {len(draft)}")
print()
print("=== PUBLISHED ===")
for r in pub:
    extra = f"  is_pro={r['is_pro']} lic={r['license_status']}" if has_new else ""
    print(f"  [{r['id']:>3}] {r['slug']:<35} {r['title']}{extra}")
print()
print("=== DRAFT / OTHER ===")
for r in draft:
    extra = f"  is_pro={r['is_pro']} lic={r['license_status']}" if has_new else ""
    print(f"  [{r['id']:>3}] ({r['status']}) {r['slug']:<30} {r['title']}{extra}")
con.close()
