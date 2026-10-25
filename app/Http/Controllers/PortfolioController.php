<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreatePortfolio;
use App\Actions\DeletePortfolio;
use App\Http\Requests\StorePortfolioRequest;
use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\PortfolioMember;
use App\Models\Transaction;
use App\Services\ValuationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Thin: reads go through the policy and ValuationService, writes go through Actions.
 */
class PortfolioController extends Controller
{
    public function index(Request $request): Response
    {
        // Soft-deleted portfolios are excluded by the SoftDeletes scope on Portfolio.
        $portfolios = $request->user()->portfolios()
            ->orderBy('name')
            ->get()
            ->map(fn (Portfolio $portfolio) => [
                'id' => $portfolio->id,
                'name' => $portfolio->name,
                'base_currency' => $portfolio->base_currency,
                'role' => $portfolio->pivot->role,
            ]);

        return Inertia::render('Portfolios/Index', ['portfolios' => $portfolios]);
    }

    public function store(StorePortfolioRequest $request, CreatePortfolio $createPortfolio): RedirectResponse
    {
        $portfolio = $createPortfolio->execute(
            $request->user(),
            $request->validated('name'),
            $request->validated('base_currency') ?? 'USD',
        );

        return redirect()->route('portfolios.show', $portfolio)->with('success', 'Portfolio created.');
    }

    public function show(Request $request, Portfolio $portfolio, ValuationService $valuation): Response
    {
        // A non-member gets 403 here. A soft-deleted portfolio never reaches this line (404 from route binding).
        Gate::authorize('view', $portfolio);

        $user = $request->user();

        return Inertia::render('Portfolios/Show', [
            'portfolio' => $portfolio->only(['id', 'name', 'base_currency']),
            'role' => $portfolio->roleFor($user)->value,
            'abilities' => [
                'write' => Gate::allows('write', $portfolio),
                'manage' => Gate::allows('manage', $portfolio),
            ],
            'summary' => $valuation->summary($portfolio),
            'transactions' => $this->latestTransactions($portfolio),
            'assets' => $portfolio->usableAssets()
                ->orderBy('name')
                ->get(['id', 'symbol', 'name', 'type', 'portfolio_id'])
                ->map(fn (Asset $asset) => [
                    'id' => $asset->id,
                    'symbol' => $asset->symbol,
                    'name' => $asset->name,
                    'type' => $asset->type->value,
                    'is_private' => ! $asset->isGlobal(),
                ]),
            'members' => $portfolio->members()
                ->with('user:id,name,email')
                ->orderBy('id')
                ->get()
                ->map(fn (PortfolioMember $member) => [
                    'id' => $member->id,
                    'role' => $member->role->value,
                    'user' => $member->user->only(['id', 'name', 'email']),
                ]),
        ]);
    }

    public function destroy(Request $request, Portfolio $portfolio, DeletePortfolio $deletePortfolio): RedirectResponse
    {
        $deletePortfolio->execute($request->user(), $portfolio);

        return redirect()->route('portfolios.index')->with('success', 'Portfolio deleted.');
    }

    /**
     * The latest 50 ledger rows, newest first. Relations are eager loaded, so the query count
     * does not grow with the number of rows.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function latestTransactions(Portfolio $portfolio): Collection
    {
        return $portfolio->transactions()
            ->with(['asset:id,symbol,name', 'author:id,name', 'reversal:id,reverses_id'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (Transaction $transaction) => [
                'id' => $transaction->id,
                'type' => $transaction->type->value,
                'quantity' => $transaction->quantity,
                'price' => $transaction->price,
                'fee' => $transaction->fee,
                'occurred_at' => $transaction->occurred_at->toIso8601String(),
                'source' => $transaction->source->value,
                'note' => $transaction->note,
                'asset' => $transaction->asset->only(['id', 'symbol', 'name']),
                'author' => $transaction->author->only(['id', 'name']),
                'reverses_id' => $transaction->reverses_id,
                'reversed' => $transaction->reversal !== null,
            ]);
    }
}
