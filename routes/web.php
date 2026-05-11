<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\LeadsheetController;
use App\Http\Controllers\Admin\ChordController;
use App\Http\Controllers\Admin\ProgressionController;
use App\Http\Controllers\Admin\RhythmPatternController;
use App\Http\Controllers\Admin\VoicingController;
use App\Http\Controllers\Admin\ProgressionDetectionController;
use App\Http\Controllers\Admin\ProgressionBuilderController;
use App\Http\Controllers\Shop\CartController;
use App\Http\Controllers\Shop\CheckoutController;
use App\Http\Controllers\Shop\DownloadController;
use App\Http\Controllers\Shop\OrderController;
use App\Http\Controllers\Shop\ShopController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Library\ChordLibraryController;
use App\Http\Controllers\Library\RhythmLibraryController;
use App\Http\Controllers\Library\ProgressionLibraryController;
use App\Http\Controllers\Library\SongLibraryController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\Admin\CourseController as AdminCourseController;
use App\Http\Controllers\Admin\LessonController as AdminLessonController;
use App\Http\Controllers\Admin\ExerciseController as AdminExerciseController;
use App\Http\Controllers\Library\ExerciseController as ExerciseLibraryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/
Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'login']);
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

/*
|--------------------------------------------------------------------------
| Admin (requires authentication)
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Phase 5 — Leadsheets (full CRUD)
    Route::get('/leadsheets', [LeadsheetController::class, 'index'])->name('leadsheets.index');
    Route::get('/leadsheets/create', [LeadsheetController::class, 'create'])->name('leadsheets.create');
    Route::post('/leadsheets', [LeadsheetController::class, 'store'])->name('leadsheets.store');
    Route::post('/leadsheets/create-blank', [LeadsheetController::class, 'createBlank'])->name('leadsheets.create-blank');
    Route::post('/leadsheets/create-from-sequence', [LeadsheetController::class, 'createFromSequence'])->name('leadsheets.create-from-sequence');
    Route::post('/leadsheets/create-from-lookup', [LeadsheetController::class, 'createFromLookup'])->name('leadsheets.create-from-lookup');

    Route::get('/leadsheets/{leadsheet}/edit', [LeadsheetController::class, 'edit'])->name('leadsheets.edit');
    Route::put('/leadsheets/{leadsheet}', [LeadsheetController::class, 'update'])->name('leadsheets.update');

    // Phase 4 — Chord Diagrams (full CRUD + voicing crossref tab)
    Route::get('/chords', [ChordController::class, 'index'])->name('chords.index');
    Route::get('/chords/create', [ChordController::class, 'create'])->name('chords.create');
    Route::post('/chords', [ChordController::class, 'store'])->name('chords.store');
    Route::get('/chords/{chord}/edit', [ChordController::class, 'edit'])->name('chords.edit');
    Route::put('/chords/{chord}', [ChordController::class, 'update'])->name('chords.update');

    // Phase 3 — Chord Progressions (full CRUD)
    Route::get('/progressions', [ProgressionController::class, 'index'])->name('progressions.index');
    Route::get('/progressions/create', [ProgressionController::class, 'create'])->name('progressions.create');
    Route::post('/progressions', [ProgressionController::class, 'store'])->name('progressions.store');
    // Phase 6d-ui — Progression Builder (must be before {progression} wildcard)
    Route::get('/progressions/builder', [ProgressionBuilderController::class, 'index'])->name('progressions.builder');
    Route::get('/progressions/{progression}/edit', [ProgressionController::class, 'edit'])->name('progressions.edit');
    Route::put('/progressions/{progression}', [ProgressionController::class, 'update'])->name('progressions.update');
    Route::delete('/progressions/{progression}', [ProgressionController::class, 'destroy'])->name('progressions.destroy');

    // Phase 2 — Rhythm Patterns (full CRUD)
    Route::get('/rhythms', [RhythmPatternController::class, 'index'])->name('rhythms.index');
    Route::get('/rhythms/create', [RhythmPatternController::class, 'create'])->name('rhythms.create');
    Route::post('/rhythms', [RhythmPatternController::class, 'store'])->name('rhythms.store');
    Route::get('/rhythms/{rhythm}/edit', [RhythmPatternController::class, 'edit'])->name('rhythms.edit');
    Route::put('/rhythms/{rhythm}', [RhythmPatternController::class, 'update'])->name('rhythms.update');
    Route::delete('/rhythms/{rhythm}', [RhythmPatternController::class, 'destroy'])->name('rhythms.destroy');

    // Phase 11b — Courses + Lessons (admin CRUD)
    Route::get('/courses', [AdminCourseController::class, 'index'])->name('courses.index');
    Route::get('/courses/create', [AdminCourseController::class, 'create'])->name('courses.create');
    Route::post('/courses', [AdminCourseController::class, 'store'])->name('courses.store');
    Route::get('/courses/{course}/edit', [AdminCourseController::class, 'edit'])->name('courses.edit');
    Route::put('/courses/{course}', [AdminCourseController::class, 'update'])->name('courses.update');
    Route::delete('/courses/{course}', [AdminCourseController::class, 'destroy'])->name('courses.destroy');

    Route::get('/courses/{course}/lessons/create', [AdminLessonController::class, 'create'])->name('courses.lessons.create');
    Route::post('/courses/{course}/lessons', [AdminLessonController::class, 'store'])->name('courses.lessons.store');
    Route::get('/lessons/{lesson}/edit', [AdminLessonController::class, 'edit'])->name('lessons.edit');
    Route::put('/lessons/{lesson}', [AdminLessonController::class, 'update'])->name('lessons.update');
    Route::delete('/lessons/{lesson}', [AdminLessonController::class, 'destroy'])->name('lessons.destroy');
    Route::post('/courses/{course}/lessons/reorder', [AdminLessonController::class, 'reorder'])->name('courses.lessons.reorder');
    Route::post('/lessons/{lesson}/update-field', [AdminLessonController::class, 'updateField'])->name('lessons.update-field');
    Route::post('/lessons/{lesson}/upload-image', [AdminLessonController::class, 'uploadImage'])->name('lessons.upload-image');
    Route::get('/lessons/{lesson}/images', [AdminLessonController::class, 'getImages'])->name('lessons.get-images');

    // Phase 8.1-A - Exercises
    Route::get('/exercises', [AdminExerciseController::class, 'index'])->name('exercises.index');
    Route::get('/exercises/create', [AdminExerciseController::class, 'create'])->name('exercises.create');
    Route::post('/exercises', [AdminExerciseController::class, 'store'])->name('exercises.store');
    Route::get('/exercises/{exercise}/edit', [AdminExerciseController::class, 'edit'])->name('exercises.edit');
    Route::put('/exercises/{exercise}', [AdminExerciseController::class, 'update'])->name('exercises.update');
    Route::delete('/exercises/{exercise}', [AdminExerciseController::class, 'destroy'])->name('exercises.destroy');
    Route::get('/exercises/{exercise}/data', [AdminExerciseController::class, 'apiData'])->name('exercises.data');
    Route::post('/exercises/from-leadsheet/{leadsheet}', [AdminExerciseController::class, 'createFromLeadsheet'])->name('exercises.from-leadsheet');

    // Shop Orders (admin view)
    Route::get('/orders', [AdminOrderController::class, 'index'])->name('orders.index');
    // AI Assistant
    Route::post('/ai/process', [\App\Http\Controllers\Admin\AIController::class, 'process'])->name('ai.process');
});

/*
|--------------------------------------------------------------------------
| API Routes (admin, auth-protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->prefix('api/admin')->name('api.admin.')->group(function () {
    // Leadsheets
    Route::get('/leadsheets/search-voicings', [LeadsheetController::class, 'searchVoicings'])->name('leadsheets.searchVoicings');
    Route::get('/leadsheets/search-voicings-advanced', [LeadsheetController::class, 'searchVoicingsAdvanced'])->name('leadsheets.searchVoicingsAdvanced');
    Route::delete('/leadsheets/{leadsheet}', [LeadsheetController::class, 'destroy'])->name('leadsheets.destroy');
    Route::post('/leadsheets/{leadsheet}/remove-voicing', [LeadsheetController::class, 'removeVoicing'])->name('leadsheets.removeVoicing');
    Route::post('/leadsheets/{leadsheet}/description', [LeadsheetController::class, 'updateDescription'])->name('leadsheets.updateDescription');
    Route::get('/leadsheets/{leadsheet}/data', [LeadsheetController::class, 'apiShow'])->name('leadsheets.show');
    Route::post('/leadsheets/identify-voicings', [LeadsheetController::class, 'identifyVoicings'])->name('leadsheets.identifyVoicings');
    Route::post('/leadsheets/identify-single', [LeadsheetController::class, 'identifySingle']);
    Route::post('/leadsheets/{leadsheet}/apply-progression', [LeadsheetController::class, 'applyProgression'])->name('leadsheets.applyProgression');


    // Phase 5d — Progression Detection
    Route::post('/leadsheets/{leadsheet}/detect-progressions', [ProgressionDetectionController::class, 'detect'])->name('leadsheets.detect-progressions');
    Route::get('/leadsheets/{leadsheet}/analyse-progressions', [ProgressionDetectionController::class, 'analyse'])->name('leadsheets.analyse-progressions');
    Route::post('/leadsheets/reprocess-progressions', [ProgressionDetectionController::class, 'reprocessAll'])->name('leadsheets.reprocess-progressions');

    // YouTube Search
    Route::get('/youtube/search', [LeadsheetController::class, 'youtubeSearch'])->name('youtube.search');

    // Exercises
    Route::delete('/exercises/{exercise}', [AdminExerciseController::class, 'destroy'])->name('exercises.destroy');
    Route::post('/exercises/{exercise}/description', [AdminExerciseController::class, 'updateDescription'])->name('exercises.updateDescription');

    // Rhythm Patterns
    Route::get('/rhythms', [RhythmPatternController::class, 'apiIndex'])->name('rhythms.index');
    Route::get('/rhythms/songs', [RhythmPatternController::class, 'apiSongs'])->name('rhythms.songs');

    // Chord Progressions
    Route::post('/progressions/reprocess', [ProgressionController::class, 'reprocess'])->name('progressions.reprocess');
    Route::post('/progressions/reseed-fragments', [ProgressionController::class, 'reseedFragments'])->name('progressions.reseedFragments');
    Route::post('/progressions/{progression}/toggle-featured', [ProgressionController::class, 'toggleFeatured'])->name('progressions.toggleFeatured');
    Route::post('/progressions/resolve-numerals', [LeadsheetController::class, 'resolveNumerals'])->name('progressions.resolveNumerals');


    // Phase 6d — Progression Builder API
    Route::post('/progressions/build-voicings', [ProgressionBuilderController::class, 'buildVoicings'])->name('progressions.buildVoicings');
    
    // Machine Room APIs
    Route::get('/progressions/builder/settings', [ProgressionBuilderController::class, 'getSettings'])->name('progressions.builder.settings');
    Route::post('/progressions/builder/settings', [ProgressionBuilderController::class, 'updateSetting'])->name('progressions.builder.updateSetting');
    Route::get('/progressions/builder/archetypes', [ProgressionBuilderController::class, 'getArchetypes'])->name('progressions.builder.archetypes');
    Route::post('/progressions/builder/archetypes', [ProgressionBuilderController::class, 'saveArchetype'])->name('progressions.builder.saveArchetype');
    Route::post('/progressions/builder/archetypes/load', [ProgressionBuilderController::class, 'loadArchetype'])->name('progressions.builder.loadArchetype');
    Route::post('/progressions/builder/restore-defaults', [ProgressionBuilderController::class, 'restoreDefaults'])->name('progressions.builder.restoreDefaults');
    Route::get('/progressions/builder/preview', [ProgressionBuilderController::class, 'previewCorpus'])->name('progressions.builder.preview');

    // Chord Diagrams
    Route::delete('/chords/{chord}', [ChordController::class, 'destroy'])->name('chords.destroy');
    Route::post('/chords/{chord}/duplicate', [ChordController::class, 'duplicate'])->name('chords.duplicate');
    Route::post('/chords/recompute', [ChordController::class, 'recomputeIntervals'])->name('chords.recompute');

    // Chord Diagram Aliases
    Route::get('/chords/{chord}/aliases', [ChordController::class, 'getAliases'])->name('chords.aliases.index');
    Route::post('/chords/{chord}/aliases', [ChordController::class, 'storeAlias'])->name('chords.aliases.store');
    Route::delete('/chords/aliases/{alias}', [ChordController::class, 'destroyAlias'])->name('chords.aliases.destroy');

    // Voicing Crossref (API only — UI merged into chord diagrams page)
    Route::post('/voicings/{draft}/dismiss', [VoicingController::class, 'dismiss'])->name('voicings.dismiss');
    Route::post('/voicings/{draft}/promote', [VoicingController::class, 'promote'])->name('voicings.promote');
    Route::post('/voicings/clear-all', [VoicingController::class, 'clearAll'])->name('voicings.clearAll');
    Route::post('/voicings/reprocess', [VoicingController::class, 'reprocess'])->name('voicings.reprocess');

    // AI Assistant API
    Route::post('/ai/process', [\App\Http\Controllers\Admin\AIController::class, 'process'])->name('ai.process');
});

/*
|--------------------------------------------------------------------------
| Public Shop
|--------------------------------------------------------------------------
*/
// Public library routes
Route::get('/library/chords', [ChordLibraryController::class, 'index'])->name('library.chords.index');
Route::get('/library/chords/search', [ChordLibraryController::class, 'search'])->name('library.chords.search');
Route::get('/library/chords/{slug}', [ChordLibraryController::class, 'show'])->name('library.chords.show');

Route::get('/library/rhythms', [RhythmLibraryController::class, 'index'])->name('library.rhythms.index');
Route::get('/library/rhythms/{slug}', [RhythmLibraryController::class, 'show'])->name('library.rhythms.show');

Route::get('/library/progressions', [ProgressionLibraryController::class, 'index'])->name('library.progressions.index');
Route::get('/library/progressions/{slug}', [ProgressionLibraryController::class, 'show'])->name('library.progressions.show');

Route::get('/library/songs', [SongLibraryController::class, 'index'])->name('library.songs.index');
Route::get('/library/songs/{leadsheet:slug}', [SongLibraryController::class, 'show'])->name('library.songs.show');
Route::get('/library/songs/{leadsheet:slug}/viewer', [SongLibraryController::class, 'viewer'])->name('library.songs.viewer');
Route::get('/library/songs/{leadsheet:slug}/cinema', [SongLibraryController::class, 'cinema'])->name('library.songs.cinema');

// Phase 11b — JSON endpoints for mountSbnNodes.ts + palette search (public, no auth)
Route::prefix('api/sbn')->name('api.sbn.')->group(function () {
    // Show (used by mountSbnNodes)
    Route::get('/chords/{slug}',       [ChordLibraryController::class,       'apiShow'])->name('chords.show');
    Route::get('/rhythms/{slug}',      [RhythmLibraryController::class,      'apiShow'])->name('rhythms.show');
    Route::get('/progressions/{slug}', [ProgressionLibraryController::class, 'apiShow'])->name('progressions.show');
    Route::get('/exercises/{slug}',    [ExerciseLibraryController::class,    'apiShow'])->name('exercises.show');
    Route::get('/songs/{leadsheet:slug}/viewer-data', [SongLibraryController::class, 'apiViewerData'])->name('songs.viewer-data');

    // Search (used by admin palette — must be before /{slug} wildcards)
    Route::get('/chords',       [ChordLibraryController::class,       'search'])->name('chords.search');
    Route::get('/rhythms',      [RhythmLibraryController::class,      'apiSearch'])->name('rhythms.search');
    Route::get('/progressions', [ProgressionLibraryController::class, 'apiSearch'])->name('progressions.search');
    Route::get('/exercises',    [ExerciseLibraryController::class,    'apiSearch'])->name('exercises.search');
    Route::get('/songs',        [SongLibraryController::class,        'apiSearch'])->name('songs.search');
});

Route::get('/top10/bossa-nova-chords', [\App\Http\Controllers\Top10Controller::class, 'bossaNovaChords'])
    ->name('top10.bossa-nova-chords');

Route::get('/top10/latin-jazz-standards', [\App\Http\Controllers\Top10Controller::class, 'latinJazzStandards'])
    ->name('top10.latin-jazz-standards');
Route::get('/top10/bossa-nova-songs', [\App\Http\Controllers\Top10Controller::class, 'bossaNovaSongs'])
    ->name('top10.bossa-nova-songs');

Route::get('/learn', [CourseController::class, 'index'])->name('courses.index');
Route::get('/learn/{course:slug}', [CourseController::class, 'show'])->name('courses.show');
Route::get('/learn/{course:slug}/play', [CourseController::class, 'player'])->name('courses.player');
Route::get('/learn/{course:slug}/play/{lesson:slug}', [CourseController::class, 'player'])->name('courses.lesson');

Route::get('/shop', [ShopController::class, 'index'])->name('shop.index');
Route::get('/shop/category/{slug}', [ShopController::class, 'category'])->name('shop.category');
Route::get('/shop/product/{slug}', [ShopController::class, 'show'])->name('shop.show');
Route::get('/shop/cart', [CartController::class, 'show'])->name('cart.show');
Route::get('/shop/checkout', [CheckoutController::class, 'show'])->name('checkout.show');
Route::post('/shop/checkout', [CheckoutController::class, 'store'])->name('checkout.store');
Route::get('/shop/order/{token}', [OrderController::class, 'success'])->name('order.success');
Route::get('/shop/download/{token}/{productId}', [DownloadController::class, 'download'])
    ->name('download.file');

/*
|--------------------------------------------------------------------------
| Root redirect
|--------------------------------------------------------------------------
*/
Route::get('/', function () {
    return redirect()->route('admin.dashboard');
});

Route::get('/hello', function () {
    return \Inertia\Inertia::render('Hello');
});
