<?php

use App\Http\Controllers\Admin\RhythmPatternController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Routes (auth-protected)
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->prefix('admin')->name('admin.')->group(function () {

    // Dashboard
    Route::get('/', function () {
        return view('admin.dashboard');
    })->name('dashboard');

    // ── Rhythm Patterns ──
    Route::get('rhythms', [RhythmPatternController::class, 'index'])->name('rhythms.index');
    Route::get('rhythms/create', [RhythmPatternController::class, 'create'])->name('rhythms.create');
    Route::post('rhythms', [RhythmPatternController::class, 'store'])->name('rhythms.store');
    Route::get('rhythms/{rhythm}/edit', [RhythmPatternController::class, 'edit'])->name('rhythms.edit');
    Route::put('rhythms/{rhythm}', [RhythmPatternController::class, 'update'])->name('rhythms.update');
    Route::delete('rhythms/{rhythm}', [RhythmPatternController::class, 'destroy'])->name('rhythms.destroy');
});

/*
|--------------------------------------------------------------------------
| API Routes (admin, auth-protected)
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->prefix('api/admin')->name('api.admin.')->group(function () {
    Route::get('rhythms', [RhythmPatternController::class, 'apiIndex'])->name('rhythms.index');
    Route::get('rhythms/songs', [RhythmPatternController::class, 'apiSongs'])->name('rhythms.songs');
});
