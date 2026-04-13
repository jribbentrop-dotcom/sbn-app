# Phase 1 — Admin Shell Deployment

Your project: `C:\Users\info\sbn-app`
Your database: `C:\Users\info\sbn-app\database\sbn.db` (SQLite, 25 leadsheets confirmed)

---

## Step 1: Delete Laravel's default migration files

Laravel ships with migration files you don't need. Delete them so they
don't conflict when we run `php artisan migrate`:

```powershell
cd C:\Users\info\sbn-app
Remove-Item database\migrations\*.php
```

Then copy the single migration file from this delivery:
- `database/migrations/2026_03_19_000001_create_auth_tables.php`

This adds `users`, `sessions`, and `cache` tables alongside your existing
sbn_ tables in the same sbn.db file.

---

## Step 2: Copy all files

Copy these into your project, overwriting where needed:

```
routes/web.php                              ← REPLACE (overwrites Laravel default)
app/Models/User.php                         ← REPLACE (overwrites Laravel default)
app/Http/Controllers/Auth/LoginController.php
app/Http/Controllers/Admin/DashboardController.php
app/Http/Controllers/Admin/LeadsheetController.php
app/Http/Controllers/Admin/ChordController.php
app/Http/Controllers/Admin/ProgressionController.php
app/Http/Controllers/Admin/RhythmController.php
app/Http/Controllers/Admin/VoicingController.php
database/migrations/2026_03_19_000001_create_auth_tables.php
database/seeders/AdminUserSeeder.php
public/css/admin.css
resources/views/layouts/admin.blade.php
resources/views/auth/login.blade.php
resources/views/components/admin/nav-item.blade.php
resources/views/admin/dashboard/index.blade.php
resources/views/admin/leadsheets/index.blade.php
resources/views/admin/chords/index.blade.php
resources/views/admin/progressions/index.blade.php
resources/views/admin/rhythms/index.blade.php
resources/views/admin/voicings/index.blade.php
```

You'll need to create these folders if they don't exist:
```powershell
mkdir app\Http\Controllers\Admin -Force
mkdir app\Http\Controllers\Auth -Force
mkdir resources\views\layouts -Force
mkdir resources\views\auth -Force
mkdir resources\views\components\admin -Force
mkdir resources\views\admin\dashboard -Force
mkdir resources\views\admin\leadsheets -Force
mkdir resources\views\admin\chords -Force
mkdir resources\views\admin\progressions -Force
mkdir resources\views\admin\rhythms -Force
mkdir resources\views\admin\voicings -Force
```

---

## Step 3: Run migration + seed

```powershell
php artisan migrate
php artisan db:seed --class=AdminUserSeeder
```

The migration creates `users`, `sessions`, and `cache` tables in your
sbn.db. The seeder creates your admin account.

---

## Step 4: Verify .env settings

Your .env should already have this from Phase 0:

```env
DB_CONNECTION=sqlite
DB_DATABASE=C:/Users/info/sbn-app/database/sbn.db
SESSION_DRIVER=database
```

If `SESSION_DRIVER` is set to `file` instead of `database`, change it to
`database` — we just created the sessions table for that.

Actually, `file` sessions work fine too. Either is OK for local dev.

---

## Step 5: Start and test

```powershell
php artisan serve
```

1. Go to `http://localhost:8000` → should redirect to `/login`
2. Login: `lucas@soulbossanova.com` / `changeme123`
3. Dashboard should show your real counts (25 leadsheets, etc.)
4. Click through sidebar: Leadsheets, Chords, Progressions, Rhythms, Voicings

---

## Troubleshooting

**"Route [admin.dashboard] not defined"**
→ You didn't replace `routes/web.php`. Make sure it's the new file.

**"View [layouts.admin] not found"**
→ The views folder structure is missing. Run the mkdir commands from Step 2.

**"Table users already exists"**
→ The migration has `if (!Schema::hasTable(...))` guards, so this
shouldn't happen. But if it does: `php artisan migrate:fresh` will
recreate all Laravel tables (your sbn_ tables are untouched since
they're not managed by migrations).

**Login works but dashboard shows 0 for everything**
→ Your sbn_ tables might have the `wp_sbn_` prefix still. Check in
tinker: `DB::select("SELECT name FROM sqlite_master WHERE type='table'")`
All tables should be `sbn_leadsheets`, `sbn_chord_diagrams`, etc.
(no `wp_` prefix).

**Page loads but looks unstyled**
→ Make sure `public/css/admin.css` exists. The layout loads it via
`{{ asset('css/admin.css') }}`.
