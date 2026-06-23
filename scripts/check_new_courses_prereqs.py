import sqlite3

conn = sqlite3.connect(r'C:\Users\info\sbn-app\database\sbn.db')
cur = conn.cursor()

cur.execute('PRAGMA integrity_check')
print('integrity_check:', cur.fetchone())

cur.execute('SELECT COUNT(*), MAX(id) FROM sbn_courses')
print('sbn_courses:', cur.fetchone())

cur.execute('SELECT COUNT(*), MAX(id) FROM sbn_lessons')
print('sbn_lessons:', cur.fetchone())

cur.execute('SELECT MAX(sort_order) FROM sbn_courses')
print('max sort_order:', cur.fetchone())

slugs = ['nashville-number-system', 'leadsheet-reading', 'arpeggio-shapes', 'motivic-development', 'improvisation-over-changes']
cur.execute(f"SELECT id, slug FROM sbn_skill_nodes WHERE slug IN ({','.join(['?']*len(slugs))})", slugs)
print('skill nodes:', cur.fetchall())

conn.close()
