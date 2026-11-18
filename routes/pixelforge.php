<?php

use App\Http\Controllers\AdminReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use Illuminate\Support\Facades\Route;

// Loaded from routes/web.php, so these get sessions, CSRF and login.

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::get('generations/create', [GenerationController::class, 'create'])->name('generations.create');
    Route::post('generations', [GenerationController::class, 'store'])->name('generations.store');
    Route::post('generations/estimate', [GenerationController::class, 'estimate'])->name('generations.estimate');
    Route::get('generations/{generation}/image', [GenerationController::class, 'image'])->name('generations.image')->whereUuid('generation');

    Route::post('credits', [CreditController::class, 'store'])->name('credits.store');

    Route::get('admin/report', AdminReportController::class)->name('admin.report');
});
