<?php

use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\GenerationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn (Request $request) => $request->user()
    ? to_route('generations.create')
    : Inertia::render('Welcome'))->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // Fortify sends users here after signing in or registering.
    Route::redirect('dashboard', '/generate')->name('dashboard');

    Route::get('generate', [GenerationController::class, 'create'])->name('generations.create');
    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::post('generations', [GenerationController::class, 'store'])->name('generations.store');

    Route::get('admin/report', ReportController::class)
        ->middleware('can:viewAdminReport')
        ->name('admin.report');
});

require __DIR__.'/settings.php';
