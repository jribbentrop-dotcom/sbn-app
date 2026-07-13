# Code & Security Audit — Soul Bossa Nova

**Date:** 2026-07-09 (updated 2026-07-09)
**Scope:** Code & architecture, security & config (Laravel 13 / PHP 8.3, Vue + Inertia)
**Method:** Static review of routes, controllers, services, middleware, models, config, and repo state. The PHPUnit suite was **not** executed (no PHP runtime in the audit sandbox); test observations come from the result cache and file inventory.

**Status legend:** ✅ Fixed · ⬜ Open (for follow-up)

---

## Summary

| # | Finding | Area | Severity | Status |
|---|---|---|---|---|
| 1 | `api/admin/*` gated by `auth` only — missing `instructor` | Security | **High** | ✅ Fixed |
| 2 | Dead `routes/admin.php` with `auth`-only admin CRUD | Security | Medium | ✅ Fixed |
| 3 | `APP_DEBUG=true` in `.env` — confirm production is `false` | Config | Low | ⬜ Open (verify prod) |
| 4 | `.env.example` missing config keys | Config | Low | ✅ Fixed |
| 5 | God controller: `LeadsheetController` (4,728 lines / 80 methods) | Architecture | Medium | ⬜ Open |
| 6 | Harmonic scorer duplicated across two services | Architecture | Medium | ⬜ Open |
| 7 | Thin request validation (only 3 FormRequests) | Code quality | Medium | ⬜ Open |
| 8 | `UserProfile` uses `$guarded = []` (open mass assignment) | Code quality | Low | ✅ Fixed |
| 9 | Tracked/stray files that should be ignored or removed | Repo hygiene | Low | ⬜ Open |
| 10 | Test suite state unverified; leftover `console.log`/TODOs | Code quality | Low | ⬜ Open |

**What's already solid:** `.env` and `sbn.db` are correctly untracked; the Stripe webhook verifies its signature and is idempotent; the CSRF exception is narrowly scoped to the webhook route; the beta auth gate (`redirectGuestsTo → register`) is coherent.

---

## Security

### 1. Broken access control on `api/admin/*` — **High** — ✅ Fixed 2026-07-09

> **Resolved:** `routes/web.php:274` now uses `Route::middleware(['auth', 'instructor'])`. The `instructor` alias is registered in `bootstrap/app.php:19`. All admin JSON endpoints now reject non-instructor accounts.

`routes/web.php:274` opens the entire admin JSON API with `auth` middleware only, omitting the `instructor` guard that its sibling `/admin` web group carries (`routes/web.php:122`). The controllers perform **no internal authorization** — e.g. `LeadsheetController::destroy()` deletes immediately with no role check.

**Impact:** any authenticated (free) account that knows the URLs can:

- `DELETE /api/admin/leadsheets/{id}` and `DELETE /api/admin/exercises/{id}` — destroy content
- `POST /api/admin/leadsheets/{id}/is-pro` and `.../status` — flip `is_pro`/publish state. Per project rules this drives monetization **and** can expose copyrighted songs through the pro Viewer/Cinema path.
- Invoke `redetect`, `transcribe-stem`, `youtube/search`, `reprocess-progressions` — expensive operations that incur YouTube-API and transcription cost and enable denial-of-service.

This is a classic broken-access-control gap (OWASP A01). Client-side UI hiding does not protect the endpoints.

**Fix:** add the role guard to the group:

```php
// routes/web.php:274
Route::middleware(['auth', 'instructor'])->prefix('api/admin')->name('api.admin.')->group(function () {
```

Then confirm no legitimate non-instructor caller depends on these routes (they shouldn't). Consider adding `authorize()`/policy checks in the controllers as defense in depth.

### 2. Dead `routes/admin.php` — Medium — ✅ Fixed 2026-07-09

> **Resolved:** file deleted. Confirmed it was never loaded (`bootstrap/app.php` registers only `web.php`, `console.php`, `channels.php`) and referenced nowhere, so removal has no functional impact.

`routes/admin.php` defined admin rhythm CRUD behind `auth` only (no `instructor`), but the file was **never loaded**. It was misleading and repeated the mistake in #1 if anyone wired it in.

### 3. Debug flag — Low

`.env` has `APP_ENV=local` and `APP_DEBUG=true`. Correct for local work, but if the production `.env` inherits `APP_DEBUG=true`, stack traces and environment details leak on errors. Verify production sets `APP_DEBUG=false`. (Production config is not visible in this repo, so this is a checklist item, not a confirmed defect.)

### 4. `.env.example` drift — Low — ✅ Fixed 2026-07-09

> **Resolved:** added the Reverb broadcasting block (`REVERB_*` + `VITE_REVERB_*`) and the missing LLM/API keys (`DEEPSEEK_*`, `GROQ_*`, `COHERE_API_KEY`, `YOUTUBE_API_KEY`). Stripe/`PAYMENTS_PROVIDER` were already present as intentional production-only comments; `DB_*` remain commented for the default SQLite setup.

Several keys the application reads were absent from `.env.example`, so a fresh deploy or new developer would boot with silently missing config.

---

## Code & architecture

### 5. God controller — `Admin/LeadsheetController.php` — Medium

4,728 lines and 80 methods spanning CRUD, audio transcription, progression detection, YouTube search, and voicing identification. It is the largest maintainability liability in the codebase and concentrates the blast radius of #1.

**Fix:** split by responsibility — e.g. `LeadsheetCrudController`, `LeadsheetTranscriptionController`, `LeadsheetVoicingController` — pushing logic into services. Related oversized files worth decomposing: `ProgressionBuilder.php` (4,369), `VoicingCrossref.php` (3,335), `resources/js/tab-editor/TabEditor.vue` (3,547).

### 6. Duplicated harmonic scorer — Medium

`ProgressionBuilder` duplicates `qualityToSuffix` and scorer logic from `ProgressionDetector`, flagged by three in-code `TODO(harmonic-scorer)` notes (`ProgressionBuilder.php:1628,1694,1729`). Divergence means the detector and the builder can silently disagree on the same harmony.

**Fix:** extract a shared harmonic-scoring module both services depend on.

### 7. Thin request validation — Medium

Only 3 `FormRequest` classes exist for a large admin write surface. Write endpoints such as `updateIsPro`, `updateStatus`, and `uploadBackingTrack` accept a raw `Request`. This pairs naturally with fixing #1.

**Fix:** introduce FormRequests (with authorization + validation rules) for the admin write endpoints.

### 8. `UserProfile` mass assignment — Low — ✅ Fixed 2026-07-13

> **Resolved:** `app/Models/UserProfile.php` now uses `protected $fillable = ['display_name', 'bio', 'public']`. Verified against all call sites (`AccountController::updateProfile`/`profile`, `BackfillCustomerBackend`) — none mass-assign beyond those three fields; `avatar_path`/`last_seen_at` are set via direct property assignment and are unaffected.

`app/Models/UserProfile.php` used `protected $guarded = []`, leaving every attribute mass-assignable. 17 of 29 models correctly use `$fillable`.

### 9. Repo hygiene — Low

- Tracked despite now being in `.gitignore` (committed before the rules): `resources/js/tab-editor.zip` (80 KB) and `public/images/mega-menu/featured-collection.png`. Run `git rm --cached` on both.
- Stray uncommitted files to remove or ignore: `# SBN Homepage — Skill Path Section.txt`, `ssr-test-tmp.mjs`, `scripts/probe_665785.php`, `scripts/probe_regress.php`, `database/sbn.db.bak-20260702-precleanup`.
- `ffmpeg.exe` (~100 MB) and `yt-dlp.exe` (~18 MB) sit in the repo root — correctly gitignored, but heavy working-tree clutter; consider relocating outside the project.

### 10. Tests & residue — Low

47 test files across Unit/Feature/Integration. The PHPUnit result cache records ~105 non-passing entries against 258 timed tests, and `tests/Unit/IdentifierRegressionCases.php` has uncommitted changes. The suite was not run here.

**Fix:** run `php artisan test` to establish the current green/red baseline before relying on it. Also: 21 `console.log` calls remain in shipped JS and there are 68 `TODO/FIXME/HACK` markers across `app/` and `resources/js/`.

---

## Recommended order

Completed in this pass: **#1** (instructor guard), **#2** (deleted dead route file), **#4** (synced `.env.example`), **#8** (`UserProfile` mass-assignment fix).

Remaining follow-up work:

1. **#7** — add validation/authorization (FormRequests) to the admin write endpoints. Do this first; it hardens the surface just re-gated in #1.
2. **#3** — verify the production `.env` sets `APP_DEBUG=false`.
3. **#5, #6** — decompose `LeadsheetController`; extract the shared harmonic scorer.
4. **#9, #10** — repo cleanup (`git rm --cached` the two tracked-but-ignored files, remove stray probe/tmp files), and re-baseline the test suite with `php artisan test`.
