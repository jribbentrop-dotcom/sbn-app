#!/usr/bin/env bash
#
# deploy_db.sh — push code + copy the local SQLite DB up to production.
#
# Content tables are replaced with the local copy.
# User tables (users, orders, enrollments, sessions) are preserved from prod.

set -euo pipefail

HOST="root@87.106.196.232"
APP_DIR="/var/www/sbn-app"
REMOTE_DB="$APP_DIR/database/sbn.db"
LOCAL_DB="database/sbn.db"
GIT_REMOTE="production"
GIT_BRANCH="main"

cd "$(dirname "$0")/.."   # repo root

if [[ ! -f "$LOCAL_DB" ]]; then
  echo "!! $LOCAL_DB not found." >&2; exit 1
fi

# ── Write helper scripts locally, scp them up ────────────────────────────────

cat > /tmp/sbn_dump_users.py << 'PYEOF'
import sqlite3, sys

# User/state tables preserved from prod (NOT overwritten by the local copy).
# Everything else is content and gets replaced. Note the quiz split: the quiz
# DEFINITIONS (sbn_quizzes, sbn_quiz_skill_node) are content and ship from
# local, but a student's ATTEMPTS are their data and must be preserved — as
# must sbn_user_skill_progress, which records quiz-earned skills.
TABLES = [
    'users', 'user_profiles', 'orders', 'order_items',
    'download_grants', 'course_user', 'sessions',
    'password_reset_tokens', 'jobs',
    'sbn_user_skill_progress',
    'sbn_quiz_attempts',
]

db_path = sys.argv[1]
out_path = sys.argv[2]

src = sqlite3.connect(db_path)
lines = []
count = 0
for t in TABLES:
    exists = src.execute(
        "SELECT name FROM sqlite_master WHERE type='table' AND name=?", (t,)
    ).fetchone()
    if not exists:
        continue
    lines.append('DELETE FROM {};'.format(t))
    cur = src.execute('SELECT * FROM {}'.format(t))
    for row in cur:
        def sql_val(v):
            if v is None:
                return 'NULL'
            return repr(v)
        vals = ', '.join(sql_val(v) for v in row)
        lines.append('INSERT OR REPLACE INTO {} VALUES ({});'.format(t, vals))
        count += 1
src.close()

with open(out_path, 'w', encoding='utf-8') as f:
    f.write('\n'.join(lines))

print('dumped {} user rows'.format(count))
PYEOF

cat > /tmp/sbn_restore_users.py << 'PYEOF'
import sqlite3, sys

db_path = sys.argv[1]
sql_path = sys.argv[2]

with open(sql_path, encoding='utf-8') as f:
    sql = f.read()

if not sql.strip():
    print('no user data to restore')
    sys.exit(0)

db = sqlite3.connect(db_path)
db.executescript(sql)
db.close()

count = sql.count('INSERT OR REPLACE')
print('restored {} user rows'.format(count))
PYEOF

echo "==> [1/9] Pushing code: git push $GIT_REMOTE $GIT_BRANCH"
git push "$GIT_REMOTE" "$GIT_BRANCH"

echo "==> [2/9] Checkpointing local DB"
python3 -c "import sqlite3; c=sqlite3.connect('$LOCAL_DB'); c.execute('PRAGMA wal_checkpoint(TRUNCATE)'); c.close(); print('   checkpointed')"

echo "==> [3/9] Detecting web user on server"
WEB_USER="$(ssh "$HOST" "ps -o user= -C php-fpm 2>/dev/null | sort -u | grep -v root | head -1" || true)"
if [[ -z "${WEB_USER:-}" ]]; then
  WEB_USER="$(ssh "$HOST" "ps -o user= -C nginx 2>/dev/null | sort -u | grep -v root | head -1" || true)"
fi
WEB_USER="${WEB_USER:-nginx}"
echo "   web user = $WEB_USER"

echo "==> [4/9] Backing up server DB"
# The timestamp must be expanded by the REMOTE shell, so $(date) stays OUTSIDE
# the single-quoted path (single quotes would make it literal). Result: a dated
# backup per deploy, not one file overwritten each time.
ssh "$HOST" "cp '$REMOTE_DB' \"${REMOTE_DB}.bak-\$(date +%Y%m%d-%H%M%S)\" && ls -la '${REMOTE_DB}'.bak-* | tail -1"

echo "==> [5/9] Copying helper scripts to server"
scp /tmp/sbn_dump_users.py "$HOST:/tmp/sbn_dump_users.py"
scp /tmp/sbn_restore_users.py "$HOST:/tmp/sbn_restore_users.py"

echo "==> [6/9] Dumping user tables from prod"
ssh "$HOST" "python3 /tmp/sbn_dump_users.py '$REMOTE_DB' /tmp/sbn_user_data.sql"

echo "==> [7/9] Maintenance mode ON"
ssh "$HOST" "cd '$APP_DIR' && php artisan down --render='errors::503' 2>/dev/null || php artisan down"

echo "==> [8/9] Copying local DB up"
scp "$LOCAL_DB" "$HOST:$REMOTE_DB"

echo "==> [9/9] Restoring users + ownership + migrations + bringing up"
ssh "$HOST" "
set -e
python3 /tmp/sbn_restore_users.py '$REMOTE_DB' /tmp/sbn_user_data.sql
chown $WEB_USER:$WEB_USER '$REMOTE_DB'
chmod 664 '$REMOTE_DB'
chmod 775 '$APP_DIR/database'
cd '$APP_DIR'
php artisan migrate --force
php artisan optimize:clear >/dev/null 2>&1 || true
php artisan up
# Count via DB::table (no namespace separators, so no backslash-quoting hell
# through the nested ssh/tinker shells — the App\\Models\\User form parse-errored).
echo 'users on prod:' \$(php artisan tinker --execute=\"echo DB::table('users')->count();\" 2>/dev/null || echo '?')
"

echo
echo "==> Done."
echo "    https://soulbossanova.com/            -> loads"
echo "    https://soulbossanova.com/library/songs -> redirects to /register"
echo
echo "    Rollback: ssh $HOST \"cp \$(ls -t ${REMOTE_DB}.bak-* | head -1) $REMOTE_DB && cd $APP_DIR && php artisan up\""
