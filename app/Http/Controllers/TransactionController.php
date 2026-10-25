<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\LogTransaction;
use App\Actions\ReverseTransaction;
use App\Http\Requests\LogTransactionRequest;
use App\Models\Portfolio;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Thin: the Actions authorize and enforce R3, R4 and R5. There is no update or delete here (R5).
 */
class TransactionController extends Controller
{
    public function store(LogTransactionRequest $request, Portfolio $portfolio, LogTransaction $logTransaction): RedirectResponse
    {
        $logTransaction->execute($request->user(), $portfolio, $request->toData());

        return back()->with('success', 'Transaction logged.');
    }

    /**
     * Scoped binding guarantees $transaction belongs to $portfolio (404 otherwise).
     */
    public function reverse(Request $request, Portfolio $portfolio, Transaction $transaction, ReverseTransaction $reverseTransaction): RedirectResponse
    {
        $reverseTransaction->execute($request->user(), $portfolio, $transaction);

        return back()->with('success', 'Transaction reversed.');
    }
}
