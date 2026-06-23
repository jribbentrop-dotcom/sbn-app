import sqlite3

conn = sqlite3.connect(r'C:\Users\info\sbn-app\database\sbn_work.db')
cur = conn.cursor()

# Check sbn_course_skill_node schema
cur.execute('PRAGMA table_info(sbn_course_skill_node)')
print('sbn_course_skill_node schema:', cur.fetchall())

# Check sbn_skill_nodes for the slugs we need
cur.execute("SELECT id, slug, title FROM sbn_skill_nodes WHERE slug IN ('caged-system','scale-patterns','arpeggio-shapes')")
print('skill nodes:', cur.fetchall())

# Last courses
cur.execute('SELECT id, slug, title FROM sbn_courses ORDER BY id DESC LIMIT 5')
print('last courses:', cur.fetchall())

# Last lessons
cur.execute('SELECT id, course_id, slug FROM sbn_lessons ORDER BY id DESC LIMIT 5')
print('last lessons:', cur.fetchall())

# Sort order for courses
cur.execute('SELECT MAX(sort_order) FROM sbn_courses')
print('max course sort_order:', cur.fetchone())

conn.close()
