<?php

use App\Http\Controllers\AssetController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\ValuationController;
use Illuminate\Support\Facades\Route;

// Breeze's auth controllers redirect to "dashboard" after login and registration; send them to the portfolios.
Route::get('/dashboard', fn () => redirect()->route('portfolios.index'))
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/', fn () => redirect()->route('portfolios.index'));

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::resource('portfolios', PortfolioController::class)->only(['index', 'store', 'show', 'destroy']);

    // scopeBindings: {transaction}, {asset} and {member} must belong to the {portfolio} in the URL,
    // otherwise the route returns 404. There is deliberately no PUT, PATCH or DELETE for transactions (R5).
    Route::scopeBindings()->prefix('portfolios/{portfolio}')->group(function () {
        Route::post('transactions', [TransactionController::class, 'store'])->name('transactions.store');
        Route::post('transactions/{transaction}/reverse', [TransactionController::class, 'reverse'])->name('transactions.reverse');
        Route::post('assets', [AssetController::class, 'store'])->name('assets.store');
        Route::post('assets/{asset}/valuations', [ValuationController::class, 'store'])->name('valuations.store');
        Route::post('members', [MemberController::class, 'store'])->name('members.store');
        Route::patch('members/{member}', [MemberController::class, 'update'])->name('members.update');
        Route::delete('members/{member}', [MemberController::class, 'destroy'])->name('members.destroy');
    });
});

require __DIR__.'/auth.php';
