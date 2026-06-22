# -*- coding: utf-8 -*-
import sqlite3, os
db = os.path.join(os.path.dirname(__file__), '..', 'database', 'sbn.db')
con = sqlite3.connect(db); con.row_factory = sqlite3.Row; cur = con.cursor()

def n(sql): return cur.execute(sql).fetchone()[0]

print("=== sbn_leadsheets license/pro summary ===")
print("total                      :", n("SELECT COUNT(*) FROM sbn_leadsheets"))
print("published                  :", n("SELECT COUNT(*) FROM sbn_leadsheets WHERE status='publish'"))
print("draft                      :", n("SELECT COUNT(*) FROM sbn_leadsheets WHERE status!='publish'"))
print("is_pro=1                   :", n("SELECT COUNT(*) FROM sbn_leadsheets WHERE is_pro=1"))
print()
print("by license_status:")
for r in cur.execute("SELECT license_status, COUNT(*) c FROM sbn_leadsheets GROUP BY license_status ORDER BY c DESC"):
    print(f"   {r['license_status']:<14} {r['c']}")
print()
# Invariant: is_pro should NEVER be true on a non-PD row.
bad = cur.execute("SELECT slug,license_status FROM sbn_leadsheets WHERE is_pro=1 AND license_status!='public_domain'").fetchall()
print("INVARIANT is_pro=1 only on public_domain:", "OK" if not bad else "VIOLATED")
for b in bad: print("   !!", b['slug'], b['license_status'])
# Invariant: nothing published should be is_pro=1 unless PD
print()
print("is_pro=1 titles:")
for r in cur.execute("SELECT slug,title FROM sbn_leadsheets WHERE is_pro=1 ORDER BY title"):
    print("   ", r['slug'])
print()
print("still draft:")
for r in cur.execute("SELECT slug,title,license_status FROM sbn_leadsheets WHERE status!='publish'"):
    print("   ", r['slug'], '-', r['license_status'])
con.close()
