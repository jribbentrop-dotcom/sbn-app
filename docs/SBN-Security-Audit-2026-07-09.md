# Code & Security Audit — Soul Bossa Nova

**Date:** 2026-07-09 (updated 2026-07-15)
**Scope:** Code & architecture, security & config (Laravel 13 / PHP 8.3, Vue + Inertia)
**Method:** Static review of routes, controllers, services, middleware, models, config, and repo state. The PHPUnit suite was **not** executed (no PHP runtime in the audit sandbox); test observations come from the result cache and file inventory.

**Status legend:** ✅ Fixed · 🟡 Partial · ⬜ Open (for follow-up)

---

## Summary

| # | Finding | Area | Severity | Status |
|---|---|---|---|---|
| 1 | `api/admin/*` gated by `auth` only — missing `instructor` | Security | **High** | ✅ Fixed |
| 2 | Dead `routes/admin.php` with `auth`-only admin CRUD | Security | Medium | ✅ Fixed |
| 3 | `APP_DEBUG=true` in `.env` — confirm production is `false` | Config | Low | ⬜ Open (verify prod) |
| 4 | `.env.example` missing config keys | Config | Low | ✅ Fixed |
| 5 | God controller: `LeadsheetController` (4,728 lines / 80 methods) | Architecture | Medium | ✅ Fixed |
| 6 | Harmonic scorer duplicated across two services | Architecture | Medium | ⬜ Open |
| 7 | Thin request validation (only 3 FormRequests) | Code quality | Medium | 🟡 Partial (all 22 Leadsheet* write endpoints now use FormRequests; other admin controllers — Chord/Progression/RhythmPattern/Voicing/ProgressionDetection/ProgressionBuilder — still open) |
| 8 | `UserProfile` uses `$guarded = []` (open mass assignment) | Code quality | Low | ✅ Fixed |
| 9 | Tracked/stray files that should be ignored or removed | Repo hygiene | Low | 🟡 Partial (the two tracked-but-ignored files untracked; local-machine stray files unreachable here) |
| 10 | Test suite state unverified; leftover `console.log`/TODOs | Code quality | Low | 🟡 Partial (baseline established; console.log/TODO sweep not done) |

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

### 5. God controller — `Admin/LeadsheetController.php` — Medium — ✅ Fixed 2026-07-15

> **Resolved:** split by responsibility into four classes, all still gated by the same `['auth', 'instructor']` route group re-secured in #1:
> - `LeadsheetController` (CRUD, versions/merge, cover/description/status/is-pro, `apiShow`) — 4,728 → ~1,940 lines
> - `LeadsheetTranscriptionController` — stem separation/audition, downbeat/detection re-tuning, redetect/transcribe-stem
> - `LeadsheetVoicingController` — voicing search/identify, apply-progression, fill-voicings, remove-voicing
> - `LeadsheetRhythmController` — apply-rhythm (leadsheet + exercise)
>
> Two traits carry the logic genuinely shared across controllers rather than duplicating it: `Concerns/SerializesLeadsheets` (chord-name normalization, the public leadsheet payload shape, and the finger-backfill/fret-matching cluster) and `Concerns/AssemblesTranscriptions` (the basic-pitch → Analysis assembly pipeline, needed by both the initial import in `LeadsheetController::createFromLookup` and every re-derive endpoint in `LeadsheetTranscriptionController`). Route names and URLs are unchanged — only the bound controller class moved — so no frontend changes were needed. Verified via `php artisan route:list`, container resolution of all four controllers, and the full PHPUnit suite (identical 277/152/6/15/31 pass/error/fail/skip/risky baseline before and after; the pre-existing errors are environment-only, e.g. tests hardcoding a Windows DB path).

Originally 4,728 lines and 80 methods spanning CRUD, audio transcription, progression detection, YouTube search, and voicing identification — the largest maintainability liability in the codebase and a concentrator of the blast radius of #1.

Related oversized files still worth decomposing: `ProgressionBuilder.php` (4,369), `VoicingCrossref.php` (3,335), `resources/js/tab-editor/TabEditor.vue` (3,547).

### 6. Duplicated harmonic scorer — Medium

`ProgressionBuilder` duplicates `qualityToSuffix` and scorer logic from `ProgressionDetector`, flagged by three in-code `TODO(harmonic-scorer)` notes (`ProgressionBuilder.php:1628,1694,1729`). Divergence means the detector and the builder can silently disagree on the same harmony.

**Fix:** extract a shared harmonic-scoring module both services depend on.

### 7. Thin request validation — Medium — 🟡 Partial 2026-07-15

> **Progress:** on closer look, most Leadsheet write endpoints (`updateIsPro`, `updateStatus`, `uploadBackingTrack`, `updateDescription`, etc.) already run an inline `$request->validate([...])` — the original finding's examples were about the raw-`Request` *pattern*, not a total absence of validation. The endpoints with an actual, unguarded gap were `LeadsheetVoicingController::applyProgression`/`fillVoicings` and `LeadsheetRhythmController::applyRhythm`/`applyRhythmToExercise`, which pulled `selections`, `rhythm_pattern_slug`, `extension_mode`, etc. straight off `$request->input()` with no rules at all. Added three FormRequests for those — `ApplyProgressionRequest`, `FillVoicingsRequest`, `ApplyRhythmRequest` (each `authorize(): true`, since the route-level `instructor` middleware is the actual gate) — and a new `tests/Feature/LeadsheetWriteValidationTest.php` (8 tests) that hits the real routes and confirms both the 422-on-bad-input and the 200-on-good-input paths, plus the 403 for a non-instructor.
> Getting that test running also surfaced that this sandbox had no `.env`/`APP_KEY` at all, so any feature test making a real HTTP request (session/cookie encryption) errored before reaching its own logic; added `APP_KEY` to `phpunit.xml`'s test env. This is a real, low-risk portability fix — confirmed via full-suite diff that it didn't flip any previously-passing test to failing, only let latent errors resolve to either pass or a real (pre-existing, unrelated) failure now visible for the first time.
> **Follow-up (2026-07-15, later same day):** converted every remaining inline `$request->validate([...])` across the Leadsheet* family to a FormRequest class — 18 more endpoints: `LeadsheetController` (`store`/`update` share one `LeadsheetRequest`; plus `convertMscz`, `createBlank`, `createFromSequence`, `createFromLookup`, `transpose`, `mergeVersions`, `mergeSong`, `resolveNumerals`, `updateDescription`, `updateCoverImage`, `uploadBackingTrack`, `updateIsPro`, `updateStatus`), `LeadsheetTranscriptionController` (`separateStems`, `persistStemAsSync`, `reshiftDownbeat`, `retuneDetection`, `redetect`, `transcribeStem`), and `LeadsheetVoicingController::removeVoicing`. The old `validateLeadsheet()` private helper (superseded by `LeadsheetRequest`) was deleted rather than left as dead code. Extended `LeadsheetWriteValidationTest.php` with 8 more cases spot-checking a representative sample (`updateIsPro`, `updateStatus`, `transpose`, `mergeSong`, `removeVoicing`, `createBlank`) — 16 tests total, all green, full-suite error/failure counts unchanged (149/8) confirming no regressions.
> **Still open:** `ChordController`, `ProgressionController`, `RhythmPatternController`, `VoicingController`, `ProgressionDetectionController`, and `ProgressionBuilderController` weren't touched in either pass — the finding covers "the admin write surface" broadly, and both passes only reached the Leadsheet* family (now fully converted).

Only 3 `FormRequest` classes exist for a large admin write surface. Write endpoints such as `updateIsPro`, `updateStatus`, and `uploadBackingTrack` accept a raw `Request`. This pairs naturally with fixing #1.

**Fix:** introduce FormRequests (with authorization + validation rules) for the admin write endpoints.

### 8. `UserProfile` mass assignment — Low — ✅ Fixed 2026-07-15

> **Resolved:** replaced `$guarded = []` with an explicit `$fillable` allowlist (`user_id, display_name, avatar_path, bio, public, last_seen_at`) matching the table's actual columns. `user_id` had to stay fillable — `AccountController`/`BackfillCustomerBackend` create profiles via `firstOrCreate(['user_id' => ...], [...])`, which merges both arrays into a single `create()` call — dropping it would have broken profile creation (verified against a real migrated DB: `firstOrCreate` + `fill()->save()` both round-trip correctly with the new allowlist).

`app/Models/UserProfile.php` uses `protected $guarded = []`, leaving every attribute mass-assignable. 17 of 29 models correctly use `$fillable`.

### 9. Repo hygiene — Low — Partially fixed 2026-07-15

> **Resolved:** `git rm --cached` on both tracked-but-ignored files (`resources/js/tab-editor.zip`, `public/images/mega-menu/featured-collection.png` — confirmed unreferenced by any code first). They stay on disk, matched by the existing `*.zip`/`*.png` ignore rules going forward.
> **Not reproducible here:** the stray uncommitted files (`# SBN Homepage — Skill Path Section.txt`, `ssr-test-tmp.mjs`, `scripts/probe_*.php`, `database/sbn.db.bak-*`) and the root-level `ffmpeg.exe`/`yt-dlp.exe` are local-machine artifacts that don't exist in this checkout — still need clearing on the Windows dev machine directly.

- Stray uncommitted files to remove or ignore: `# SBN Homepage — Skill Path Section.txt`, `ssr-test-tmp.mjs`, `scripts/probe_665785.php`, `scripts/probe_regress.php`, `database/sbn.db.bak-20260702-precleanup`.
- `ffmpeg.exe` (~100 MB) and `yt-dlp.exe` (~18 MB) sit in the repo root — correctly gitignored, but heavy working-tree clutter; consider relocating outside the project.

### 10. Tests & residue — Low — Partially fixed 2026-07-15

> **Baseline established:** `./vendor/bin/phpunit tests/Unit tests/Feature tests/Integration` → **277 tests, 6261 assertions, 152 errors, 6 failures, 15 skipped, 31 risky**, identical before and after the #5 refactor (confirmed via `git stash`). The 152 errors are environment-only, from two distinct causes, not one: (a) several test classes (`AuthTest`, `LeadsheetLookupTest`, `LeadsheetProgressionTest`, `PaymentWebhookTest`, ...) deliberately connect to the real `sbn.db` instead of the `:memory:` test DB — via a hardcoded Windows path or `database_path('sbn.db')` — because, per `AuthTest`'s own comment, "the schema is not fully migration-defined"; that DB doesn't exist in this sandbox at all. (b) no `.env`/`APP_KEY` existed in this checkout, so *any* feature test making a real HTTP request (session/cookie encryption needs a key) errored before reaching its own logic — fixed by adding `APP_KEY` to `phpunit.xml` (see #7), which dropped the error count by 3 without flipping any previously-passing test to failing (full before/after diff checked). The remaining `:memory:`-incompatible tests in (a) still need a real fix — either a from-scratch migration path that fully matches production schema, or seeded fixtures — before they can run portably.
> **Not done:** the `console.log`/`TODO` sweep — no fix was prescribed beyond noting the counts, and re-verifying 68 markers wasn't in scope for this pass.

47 test files across Unit/Feature/Integration. `tests/Unit/IdentifierRegressionCases.php` had uncommitted changes as of the original audit — not present in this checkout, so unverified here.

---

## Recommended order

Completed: **#1** (instructor guard), **#2** (deleted dead route file), **#4** (synced `.env.example`), **#5** (split `LeadsheetController`), **#8** (`UserProfile` fillable allowlist).

Partially done (as far as this sandbox reaches): **#7** (all 22 Leadsheet* write endpoints now have FormRequests — both the real validation gap and the inline-`validate()` consistency cleanup — plus the `phpunit.xml` `APP_KEY` fix; other admin controllers not yet touched), **#9** (the two tracked-but-ignored files untracked; local-machine stray files need clearing directly on the Windows box), **#10** (PHPUnit baseline established and its two distinct environment-only root causes identified — see §10).

Remaining follow-up work:

1. **#3** — verify the production `.env` sets `APP_DEBUG=false`. **Cannot be checked from this sandbox** — it has no access to the production server/`.env`; this needs a human (or a session with production access) to confirm.
2. **#6** — the "duplicated harmonic scorer" needs a human call, not a mechanical merge: `ProgressionBuilder::qualityToSuffix`/`normalizeQuality` and `ProgressionDetector::qualityToSuffix`/`normalizeQualityForDetection` look like copies of each other but have already diverged *by design* — Detector collapses extended qualities (m9, maj11, ...) to base harmonic function for progression-pattern matching, while Builder preserves them for chord-building. Merging them naively would break one or the other. One concrete, likely-accidental divergence found along the way: augmented chords serialize as `'+'`/`'7+'` in Builder's roman-numeral suffix but `'aug'`/`'aug7'` in Detector's (and in Builder's own separate chord-*name* suffix map) — currently harmless since no canonical progression pattern references augmented chords, so it's never been exercised, but worth a decision on which spelling is canonical before touching it.
3. **#7 remainder** — extend the FormRequest sweep to `ChordController`, `ProgressionController`, `RhythmPatternController`, `VoicingController`, `ProgressionDetectionController`, `ProgressionBuilderController` (the Leadsheet* family is now fully converted).
4. **#9, #10 cleanup** — clear the stray local files on the dev machine directly, and either give the DB-dependent tests (`AuthTest`, `LeadsheetLookupTest`, `LeadsheetProgressionTest`, `PaymentWebhookTest`, ...) a migration path that matches production schema, or seed fixtures, so they can run against `:memory:` instead of requiring the real `sbn.db`.
