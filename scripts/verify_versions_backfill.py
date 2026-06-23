import sqlite3
db = sqlite3.connect('database/sbn.db')
def one(s): return db.execute(s).fetchone()[0]

print('leadsheets                :', one('SELECT COUNT(*) FROM sbn_leadsheets'))
print('versions (basic)          :', one("SELECT COUNT(*) FROM sbn_leadsheet_versions WHERE version_slug='basic'"))
print('leadsheets w/ default set  :', one('SELECT COUNT(*) FROM sbn_leadsheets WHERE default_version_id IS NOT NULL'))
print('leadsheets w/ NULL default :', one('SELECT COUNT(*) FROM sbn_leadsheets WHERE default_version_id IS NULL'))
print('broken default pointers    :', one('SELECT COUNT(*) FROM sbn_leadsheets l LEFT JOIN sbn_leadsheet_versions v ON l.default_version_id=v.id WHERE l.default_version_id IS NOT NULL AND v.id IS NULL'))

print('--- caches ---')
for t in ['sbn_progression_occurrences', 'sbn_voicing_usage', 'sbn_voicing_drafts']:
    tot = one(f'SELECT COUNT(*) FROM {t}')
    nullv = one(f'SELECT COUNT(*) FROM {t} WHERE version_id IS NULL')
    orph = one(f'SELECT COUNT(*) FROM {t} c LEFT JOIN sbn_leadsheets l ON c.leadsheet_id=l.id WHERE l.id IS NULL')
    print(f'  {t}: rows={tot} null_version={nullv} orphans={orph}')

print('--- spot check: data copied across ---')
rows = db.execute(
    "SELECT l.title, v.song_key, length(v.json_data) jd, length(v.melody_tab_xml) mx "
    "FROM sbn_leadsheets l JOIN sbn_leadsheet_versions v ON l.default_version_id=v.id LIMIT 3"
).fetchall()
for r in rows:
    print('  ', r)
db.close()
