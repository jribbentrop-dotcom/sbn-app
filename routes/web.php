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

    // Shop Orders (admin view)
    Route::get('/orders', [AdminOrderController::class, 'index'])->name('orders.index');
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
    Route::post('/leadsheets/{leadsheet}/description', [LeadsheetController::class, 'updateDescription'])->name('leadsheets.updateDescription');
    Route::get('/leadsheets/{leadsheet}/data', [LeadsheetController::class, 'apiShow'])->name('leadsheets.show');
    Route::post('/leadsheets/identify-voicings', [LeadsheetController::class, 'identifyVoicings'])->name('leadsheets.identifyVoicings');
    Route::post('/leadsheets/identify-single', [LeadsheetController::class, 'identifySingle']);
    Route::post('/leadsheets/{leadsheet}/apply-progression', [LeadsheetController::class, 'applyProgression'])->name('leadsheets.applyProgression');


    // Phase 5d — Progression Detection
    Route::post('/leadsheets/{leadsheet}/detect-progressions', [ProgressionDetectionController::class, 'detect'])->name('leadsheets.detect-progressions');
    Route::get('/leadsheets/{leadsheet}/analyse-progressions', [ProgressionDetectionController::class, 'analyse'])->name('leadsheets.analyse-progressions');
    Route::post('/leadsheets/reprocess-progressions', [ProgressionDetectionController::class, 'reprocessAll'])->name('leadsheets.reprocess-progressions');

    // Rhythm Patterns
    Route::get('/rhythms', [RhythmPatternController::class, 'apiIndex'])->name('rhythms.index');
    Route::get('/rhythms/songs', [RhythmPatternController::class, 'apiSongs'])->name('rhythms.songs');

    // Chord Progressions
    Route::post('/progressions/reprocess', [ProgressionController::class, 'reprocess'])->name('progressions.reprocess');
    Route::post('/progressions/{progression}/toggle-featured', [ProgressionController::class, 'toggleFeatured'])->name('progressions.toggleFeatured');

    // Phase 6d — Progression Builder API
    Route::post('/progressions/build-voicings', [ProgressionBuilderController::class, 'buildVoicings'])->name('progressions.buildVoicings');

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

Route::get('/top10/bossa-nova-chords', [\App\Http\Controllers\Top10Controller::class, 'bossaNovaChords'])
    ->name('top10.bossa-nova-chords');

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
