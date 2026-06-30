#!/usr/bin/env python3
"""
db_checkout.py — safe DB checkout/commit for the co-work sandbox.

The mounted Windows path (database/sbn.db) is NOT a reliable filesystem for
SQLite: reads through the mount intermittently return half-written pages and
SQLite reports "database disk image is malformed" even though the file on disk
is fine. Never open the mounted file directly. Use this script to:

  1. checkout  -> copy mount DB to a native local path, with retries + verify
  2. (work against the local copy only)
  3. commit    -> copy the local copy back to the mount, with verify

Usage:
    python3 scripts/db_checkout.py checkout   # prints the local work path
    python3 scripts/db_checkout.py commit      # writes local copy back to mount
    python3 scripts/db_checkout.py status      # diagnose without copying

The local work path is stable across calls in a session: $HOME/sbn_work/sbn.db
"""
import os, sys, struct, shutil, sqlite3, time, hashlib

# Resolve the mounted DB. In the sandbox this is under /sessions/.../mnt/...;
# fall back to the env/relative path so it also works locally.
def find_mount_db():
    for cand in (
        os.environ.get("SBN_DB"),
        # sandbox mount (glob the session dir)
        *__import__("glob").glob("/sessions/*/mnt/sbn-app/database/sbn.db"),
        os.path.join(os.getcwd(), "database", "sbn.db"),
    ):
        if cand and os.path.exists(cand):
            return cand
    sys.exit("ERROR: could not locate mounted sbn.db (set SBN_DB env var)")

def work_path():
    d = os.path.join(os.path.expanduser("~"), "sbn_work")
    os.makedirs(d, exist_ok=True)
    return os.path.join(d, "sbn.db")

def header_expected_bytes(path):
    """Return (expected_bytes, actual_bytes) from the SQLite header."""
    with open(path, "rb") as f:
        data = f.read(100)
    if len(data) < 32 or data[:16] != b"SQLite format 3\x00":
        return (None, os.path.getsize(path))  # not a sqlite header at all
    page_size = struct.unpack(">H", data[16:18])[0]
    page_size = 65536 if page_size == 1 else page_size
    header_pages = struct.unpack(">I", data[28:32])[0]
    return (page_size * header_pages, os.path.getsize(path))

def truncated(path):
    exp, act = header_expected_bytes(path)
    if exp is None:
        return True  # no valid header -> treat as broken
    return act < exp

def integrity_ok(path):
    try:
        db = sqlite3.connect(f"file:{path}?mode=ro", uri=True)
        row = db.execute("PRAGMA quick_check").fetchone()
        db.close()
        return row and row[0] == "ok"
    except sqlite3.DatabaseError:
        return False

def md5(path):
    h = hashlib.md5()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()

def robust_copy(src, dst, label, retries=4):
    """Copy with retries; verify the COPY is a valid sqlite DB, not the source.

    A flaky mount read produces a bad copy that retry fixes. A genuinely
    truncated source never gets better -> bail with a clear message so the
    agent stops retrying and asks the user for a backup.
    """
    # First, decide if the SOURCE is genuinely truncated (host-side damage).
    if truncated(src):
        exp, act = header_expected_bytes(src)
        sys.exit(
            f"FATAL: source {src} is genuinely truncated "
            f"(header expects {exp} bytes, file is {act}). "
            f"This is host-side damage, not a mount read glitch. "
            f"Restore sbn.db from a backup on the Windows host before continuing."
        )
    last_err = None
    for attempt in range(1, retries + 1):
        try:
            shutil.copy2(src, dst)
            if not truncated(dst) and integrity_ok(dst):
                return
            last_err = "copy passed but integrity/quick_check failed"
        except OSError as e:
            last_err = str(e)
        wait = 0.5 * attempt
        print(f"  {label} attempt {attempt} failed ({last_err}); retrying in {wait}s",
              file=sys.stderr)
        time.sleep(wait)
    sys.exit(
        f"FATAL: {label} failed after {retries} attempts ({last_err}). "
        f"The mount is flaky right now; ask the user to retry, or proceed "
        f"without DB access if the task allows it. Do NOT keep retrying."
    )

def cmd_checkout():
    src, dst = find_mount_db(), work_path()
    robust_copy(src, dst, "checkout")
    print(dst)  # stdout = the path to use for ALL reads/writes

BACKUP_KEEP = 10  # how many pre-commit snapshots to retain

def backup_dir():
    d = os.path.join(os.path.expanduser("~"), "sbn_work", "backups")
    os.makedirs(d, exist_ok=True)
    return d

def snapshot_before_commit(mount_src):
    """Copy the CURRENT mount DB (about to be overwritten) into a rotated
    backup dir on native disk, before commit clobbers it.

    Snapshots the mount (the pre-change state we'd want to recover), not the
    work copy. Best-effort: a backup failure must NOT block the commit, so we
    catch everything and only warn. Skips a truncated/unreadable mount.
    """
    try:
        if not os.path.exists(mount_src) or truncated(mount_src):
            print("  (skip backup: mount DB missing/truncated)", file=sys.stderr)
            return
        bdir = backup_dir()
        stamp = time.strftime("%Y%m%d-%H%M%S")
        snap = os.path.join(bdir, f"sbn-{stamp}.db")
        shutil.copy2(mount_src, snap)
        if truncated(snap) or not integrity_ok(snap):
            print(f"  (backup {snap} failed verify; removing)", file=sys.stderr)
            try: os.remove(snap)
            except OSError: pass
            return
        # Rotate: keep the newest BACKUP_KEEP, prune older.
        snaps = sorted(
            (os.path.join(bdir, f) for f in os.listdir(bdir)
             if f.startswith("sbn-") and f.endswith(".db")),
            key=os.path.getmtime, reverse=True,
        )
        for old in snaps[BACKUP_KEEP:]:
            try: os.remove(old)
            except OSError: pass
        print(f"  backed up pre-commit state -> {snap}", file=sys.stderr)
    except Exception as e:  # never let a backup error abort the commit
        print(f"  (backup skipped: {e})", file=sys.stderr)

def cmd_commit():
    src, dst = work_path(), find_mount_db()
    if not os.path.exists(src):
        sys.exit(f"ERROR: no local work DB at {src}; run checkout first")
    if not integrity_ok(src):
        sys.exit(f"ERROR: local work DB {src} fails integrity check; refusing to commit")
    snapshot_before_commit(dst)  # snapshot the mount's current state before overwriting
    robust_copy(src, dst, "commit")
    # verify round-trip
    if md5(src) == md5(dst):
        print(f"committed: {src} -> {dst} (md5 verified)")
    else:
        print(f"WARNING: {dst} md5 differs from {src} after copy — re-run commit",
              file=sys.stderr)

def cmd_status():
    src = find_mount_db()
    exp, act = header_expected_bytes(src)
    print(f"mount db:   {src}")
    print(f"size:       {act} bytes (header expects {exp})")
    print(f"truncated:  {truncated(src)}")
    print(f"integrity:  {'ok' if integrity_ok(src) else 'FAILED (may be mount flakiness — retry)'}")
    print(f"work path:  {work_path()}")

if __name__ == "__main__":
    cmd = sys.argv[1] if len(sys.argv) > 1 else "status"
    {"checkout": cmd_checkout, "commit": cmd_commit, "status": cmd_status}.get(
        cmd, cmd_status)()
