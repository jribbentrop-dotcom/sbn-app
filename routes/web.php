<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\NewPasswordController;
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
use App\Http\Controllers\Library\TheoryController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Account\MessageController;
use App\Http\Controllers\CommunityController;
use App\Http\Controllers\Admin\MessageController as AdminMessageController;
use App\Http\Controllers\Admin\CommunityController as AdminCommunityController;
use Illuminate\Support\Facades\Broadcast;
use App\Http\Controllers\Admin\CourseController as AdminCourseController;
use App\Http\Controllers\Admin\CourseGrantController;
use App\Http\Controllers\Admin\LessonController as AdminLessonController;
use App\Http\Controllers\Admin\ExerciseController as AdminExerciseController;
use App\Http\Controllers\Library\ExerciseController as ExerciseLibraryController;
use App\Http\Controllers\Admin\AdminFretboardController;
use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Broadcasting auth (Reverb private channels)
|--------------------------------------------------------------------------
*/
Broadcast::routes(['middleware' => ['web', 'auth']]);

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);

    Route::get('/register', [RegisterController::class, 'show'])->name('register');
    Route::post('/register', [RegisterController::class, 'register']);

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'show'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');

    Route::get('/reset-password/{token}', [NewPasswordController::class, 'show'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.update');
});

Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

/*
|--------------------------------------------------------------------------
| Account (logged-in customer area)
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->prefix('account')->name('account.')->group(function () {
    Route::get('/', [AccountController::class, 'dashboard'])->name('dashboard');
    Route::get('/courses', [AccountController::class, 'courses'])->name('courses');
    Route::get('/orders', [AccountController::class, 'orders'])->name('orders');
    Route::get('/orders/{token}', [AccountController::class, 'order'])->name('orders.show');
    Route::get('/profile', [AccountController::class, 'profile'])->name('profile');
    Route::patch('/profile', [AccountController::class, 'updateProfile'])->name('profile.update');
    Route::post('/profile/avatar', [AccountController::class, 'uploadAvatar'])->name('profile.avatar');

    Route::get('/messages', [MessageController::class, 'index'])->name('messages');
    Route::post('/messages/start-dm', [MessageController::class, 'startDm'])->name('messages.start-dm');
    Route::get('/messages/{conversation}', [MessageController::class, 'show'])->name('messages.show');
    Route::get('/messages/{conversation}/fetch', [MessageController::class, 'fetch'])->name('messages.fetch');
    Route::post('/messages/{conversation}', [MessageController::class, 'store'])->name('messages.store');
    Route::delete('/messages/{conversation}/{message}', [MessageController::class, 'destroy'])->name('messages.destroy');
    Route::patch('/messages/{conversation}/read', [MessageController::class, 'markRead'])->name('messages.read');
});

/*
|--------------------------------------------------------------------------
| Community (auth required)
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->prefix('community')->name('community.')->group(function () {
    Route::get('/', [CommunityController::class, 'show'])->name('show');
    Route::post('/', [CommunityController::class, 'store'])->name('store');
    Route::get('/fetch', [CommunityController::class, 'fetch'])->name('fetch');
    Route::patch('/read', [CommunityController::class, 'markRead'])->name('read');
    Route::delete('/messages/{message}', [CommunityController::class, 'destroyMessage'])->name('messages.destroy');
    Route::post('/read-only', [CommunityController::class, 'toggleReadOnly'])->name('read-only');
    Route::post('/mute', [CommunityController::class, 'toggleMute'])->name('mute');
});

/*
|--------------------------------------------------------------------------
| Admin (requires authentication)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'instructor'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/course-grants', [CourseGrantController::class, 'index'])->name('course-grants.index');
    Route::post('/course-grants', [CourseGrantController::class, 'store'])->name('course-grants.store');
    Route::delete('/course-grants/{id}', [CourseGrantController::class, 'destroy'])->name('course-grants.destroy');

    Route::get('/messages', [AdminMessageController::class, 'index'])->name('messages.index');
    Route::post('/messages/{conversation}', [AdminMessageController::class, 'store'])->name('messages.store');
    Route::delete('/messages/{conversation}/{message}', [AdminMessageController::class, 'destroy'])->name('messages.destroy');

    Route::get('/community', [AdminCommunityController::class, 'show'])->name('community.show');
    Route::post('/community', [AdminCommunityController::class, 'store'])->name('community.store');
    Route::post('/community/read-only', [AdminCommunityController::class, 'toggleReadOnly'])->name('community.read-only');
    Route::delete('/community/messages/{message}', [AdminCommunityController::class, 'destroyMessage'])->name('community.message.destroy');

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
    Route::post('/progressions/{progression}/description', [ProgressionController::class, 'updateDescription'])->name('progressions.updateDescription');

    // Phase 2 — Rhythm Patterns (full CRUD)
    Route::get('/rhythms', [RhythmPatternController::class, 'index'])->name('rhythms.index');
    Route::get('/rhythms/create', [RhythmPatternController::class, 'create'])->name('rhythms.create');
    Route::post('/rhythms', [RhythmPatternController::class, 'store'])->name('rhythms.store');
    Route::get('/rhythms/{rhythm}/edit', [RhythmPatternController::class, 'edit'])->name('rhythms.edit');
    Route::put('/rhythms/{rhythm}', [RhythmPatternController::class, 'update'])->name('rhythms.update');
    Route::delete('/rhythms/{rhythm}', [RhythmPatternController::class, 'destroy'])->name('rhythms.destroy');
    Route::post('/rhythms/{rhythm}/description', [RhythmPatternController::class, 'updateDescription'])->name('rhythms.updateDescription');

    // Phase 12c — Products (admin CRUD)
    Route::get('/products', [AdminProductController::class, 'index'])->name('products.index');
    Route::get('/products/create', [AdminProductController::class, 'create'])->name('products.create');
    Route::post('/products', [AdminProductController::class, 'store'])->name('products.store');
    Route::get('/products/{product}/edit', [AdminProductController::class, 'edit'])->name('products.edit');
    Route::put('/products/{product}', [AdminProductController::class, 'update'])->name('products.update');
    Route::delete('/products/{product}', [AdminProductController::class, 'destroy'])->name('products.destroy');
    Route::post('/products/{product}/status', [AdminProductController::class, 'updateStatus'])->name('products.updateStatus');

    // Phase 11b — Courses + Lessons (admin CRUD)
    Route::get('/courses', [AdminCourseController::class, 'index'])->name('courses.index');
    Route::get('/courses/create', [AdminCourseController::class, 'create'])->name('courses.create');
    Route::post('/courses', [AdminCourseController::class, 'store'])->name('courses.store');
    Route::get('/courses/{course}/edit', [AdminCourseController::class, 'edit'])->name('courses.edit');
    Route::put('/courses/{course}', [AdminCourseController::class, 'update'])->name('courses.update');
    Route::delete('/courses/{course}', [AdminCourseController::class, 'destroy'])->name('courses.destroy');
    Route::post('/courses/{course}/status', [AdminCourseController::class, 'updateStatus'])->name('courses.updateStatus');
    Route::post('/courses/{course}/description', [AdminCourseController::class, 'updateDescription'])->name('courses.updateDescription');

    Route::get('/courses/{course}/lessons/create', [AdminLessonController::class, 'create'])->name('courses.lessons.create');
    Route::post('/courses/{course}/lessons', [AdminLessonController::class, 'store'])->name('courses.lessons.store');
    Route::get('/lessons/{lesson}/edit', [AdminLessonController::class, 'edit'])->name('lessons.edit');
    Route::put('/lessons/{lesson}', [AdminLessonController::class, 'update'])->name('lessons.update');
    Route::delete('/lessons/{lesson}', [AdminLessonController::class, 'destroy'])->name('lessons.destroy');
    Route::post('/lessons/{lesson}/status', [AdminLessonController::class, 'updateStatus'])->name('lessons.updateStatus');
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
    Route::post('/exercises/from-leadsheet/{leadsheet}/slice', [AdminExerciseController::class, 'createFromLeadsheetSlice'])->name('exercises.from-leadsheet-slice');

    // Fretboard Diagrams (admin CRUD)
    Route::get('/fretboards', [AdminFretboardController::class, 'index'])->name('fretboards.index');
    Route::get('/fretboards/create', [AdminFretboardController::class, 'create'])->name('fretboards.create');
    Route::post('/fretboards', [AdminFretboardController::class, 'store'])->name('fretboards.store');
    Route::get('/fretboards/{fretboard}/edit', [AdminFretboardController::class, 'edit'])->name('fretboards.edit');
    Route::put('/fretboards/{fretboard}', [AdminFretboardController::class, 'update'])->name('fretboards.update');
    Route::delete('/fretboards/{fretboard}', [AdminFretboardController::class, 'destroy'])->name('fretboards.destroy');

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
    Route::post('/leadsheets/{leadsheet}/cover-image', [LeadsheetController::class, 'updateCoverImage'])->name('leadsheets.updateCoverImage');
    Route::post('/leadsheets/{leadsheet}/status', [LeadsheetController::class, 'updateStatus'])->name('leadsheets.updateStatus');
    Route::post('/leadsheets/{leadsheet}/reshift-downbeat', [LeadsheetController::class, 'reshiftDownbeat'])->name('leadsheets.reshiftDownbeat');
    Route::get('/leadsheets/{leadsheet}/data', [LeadsheetController::class, 'apiShow'])->name('leadsheets.show');
    Route::post('/leadsheets/identify-voicings', [LeadsheetController::class, 'identifyVoicings'])->name('leadsheets.identifyVoicings');
    Route::post('/leadsheets/identify-single', [LeadsheetController::class, 'identifySingle']);
    Route::post('/leadsheets/{leadsheet}/apply-progression', [LeadsheetController::class, 'applyProgression'])->name('leadsheets.applyProgression');
    Route::post('/leadsheets/{leadsheet}/fill-voicings', [LeadsheetController::class, 'fillVoicings'])->name('leadsheets.fillVoicings');
    Route::post('/leadsheets/{leadsheet}/apply-rhythm', [LeadsheetController::class, 'applyRhythm'])->name('leadsheets.applyRhythm');
    Route::post('/exercises/{exercise}/apply-rhythm', [LeadsheetController::class, 'applyRhythmToExercise'])->name('exercises.applyRhythm');


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
    Route::post('/chords/{chord}/description', [ChordController::class, 'updateDescription'])->name('chords.updateDescription');
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
Route::get('/library/exercises/{exercise:slug}/cinema', [ExerciseLibraryController::class, 'cinema'])->name('library.exercises.cinema');

Route::get('/theory', [TheoryController::class, 'index'])->name('theory.index');

// Phase 11b — JSON endpoints for mountSbnNodes.ts + palette search (public, no auth)
Route::prefix('api/sbn')->name('api.sbn.')->group(function () {
    // Show (used by mountSbnNodes)
    Route::get('/chords/{slug}',       [ChordLibraryController::class,       'apiShow'])->name('chords.show');
    Route::get('/rhythms/{slug}',      [RhythmLibraryController::class,      'apiShow'])->name('rhythms.show');
    Route::get('/progressions/{slug}', [ProgressionLibraryController::class, 'apiShow'])->name('progressions.show');
    Route::get('/exercises/{slug}',    [ExerciseLibraryController::class,    'apiShow'])->name('exercises.show');
    Route::get('/fretboards',          [AdminFretboardController::class,     'apiSearch'])->name('fretboards.search');
    Route::get('/fretboards/{slug}',   [AdminFretboardController::class,     'apiShow'])->name('fretboards.show');
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
| Payments — provider-agnostic webhook + dev fake checkout
|--------------------------------------------------------------------------
*/
Route::post('/webhooks/payments', \App\Http\Controllers\Webhooks\PaymentWebhookController::class)
    ->name('payments.webhook');

// Local fake-provider checkout simulation (never registered in production).
if (! app()->environment('production')) {
    Route::get('/payments/fake/checkout/{token}', [\App\Http\Controllers\Payments\FakeCheckoutController::class, 'show'])
        ->name('payments.fake.checkout');
    Route::post('/payments/fake/checkout/{token}/pay', [\App\Http\Controllers\Payments\FakeCheckoutController::class, 'pay'])
        ->name('payments.fake.pay');
}

/*
|--------------------------------------------------------------------------
| Root redirect
|--------------------------------------------------------------------------
*/
Route::get('/', [HomeController::class, 'index'])->name('home');

Route::get('/hello', function () {
    return \Inertia\Inertia::render('Hello');
});

/*
|--------------------------------------------------------------------------
| Edu Content System — dev harness (local + testing only)
|--------------------------------------------------------------------------
| Renders an edu topic's body_html through mountSbnNodes so the <sbn-widget>
| pipeline can be verified end to end. Not a product surface — real
| consumption (EduPanel, Course Practice Panel) is wired in a later task.
| Registered in local + testing only (testing so the Feature test can reach
| it); never in production.
*/
if (app()->environment(['local', 'testing'])) {
    Route::get('/dev/edu/{type}/{slug}', function (string $type, string $slug, \App\Services\EduContentService $edu) {
        $topic = $edu->topic($type, $slug);
        abort_if($topic === null, 404, "No edu topic {$type}/{$slug}");

        return \Inertia\Inertia::render('Dev/EduHarness', [
            'topic' => $topic->toArray(),
        ]);
    })->name('dev.edu.harness');
}
