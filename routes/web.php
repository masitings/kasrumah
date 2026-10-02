<?php

use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CatatController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SummaryController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    // 1. Catat
    Route::get('/', [CatatController::class, 'index'])->name('home');
    Route::post('/catat/text', [CatatController::class, 'storeText'])->name('catat.text');
    Route::post('/catat/image', [CatatController::class, 'storeImage'])->name('catat.image');

    // 2. Review
    Route::get('/review', [ReviewController::class, 'index'])->name('review.index');
    Route::patch('/review/{expense}', [ReviewController::class, 'update'])->name('review.update');
    Route::post('/review/{expense}/confirm', [ReviewController::class, 'confirm'])->name('review.confirm');
    Route::post('/review/confirm-all', [ReviewController::class, 'confirmAll'])->name('review.confirm-all');
    Route::delete('/review/{expense}', [ReviewController::class, 'destroy'])->name('review.destroy');

    // 3. Pengeluaran
    Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');

    // 4. Ringkasan
    Route::get('/summary', [SummaryController::class, 'index'])->name('summary.index');
    Route::post('/summary/regenerate', [SummaryController::class, 'regenerate'])->name('summary.regenerate');

    // 5. Budget
    Route::get('/budgets', [BudgetController::class, 'index'])->name('budgets.index');
    Route::post('/budgets', [BudgetController::class, 'update'])->name('budgets.update');

    // Receipt image thumbnail (auth protected)
    Route::get('/receipts/{expense}', [ReceiptController::class, 'show'])->name('receipts.show');

    // Keep dashboard for starter kit compatibility
    Route::inertia('/dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
