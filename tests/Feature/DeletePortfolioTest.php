<?php

declare(strict_types=1);

use App\Actions\DeletePortfolio;
use App\Actions\LogTransaction;
use App\Data\LogTransactionData;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Portfolio;
use Illuminate\Auth\Access\AuthorizationException;

test('owners can soft-delete a portfolio and its ledger stays', function () {
    [$owner, $portfolio] = portfolioWithRole(Role::Owner);
    $transaction = app(LogTransaction::class)->execute(
        $owner, $portfolio, new LogTransactionData(globalAsset()->id, TransactionType::Buy, '1', '100'),
    );

    app(DeletePortfolio::class)->execute($owner, $portfolio);

    $this->assertSoftDeleted($portfolio);
    $this->assertDatabaseHas('transactions', ['id' => $transaction->id]);
    $this->assertDatabaseCount('portfolio_members', 1);
    expect(Portfolio::find($portfolio->id))->toBeNull();
});

test('editors and viewers cannot delete a portfolio', function (Role $role) {
    [$user, $portfolio] = portfolioWithRole($role);

    expect(fn () => app(DeletePortfolio::class)->execute($user, $portfolio))
        ->toThrow(AuthorizationException::class);

    $this->assertNotSoftDeleted($portfolio);
})->with([Role::Editor, Role::Viewer]);
