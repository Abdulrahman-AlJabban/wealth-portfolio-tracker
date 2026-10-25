<?php

declare(strict_types=1);

use App\Actions\LogTransaction;
use App\Agent\AgentContext;
use App\Agent\AssistantTools;
use App\Data\LogTransactionData;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\AgentRun;
use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ValuationService;

function writeToolsFor(User $user, Portfolio $portfolio, string $runId = 'run-1'): AssistantTools
{
    return new AssistantTools(new AgentContext($user, $portfolio, $runId), app(ValuationService::class), app(LogTransaction::class));
}

/**
 * Calls log_transaction and returns the decoded JSON result.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function agentLogs(AssistantTools $tools, array $overrides = []): array
{
    $args = [...['asset_symbol' => 'BTC', 'type' => 'buy', 'quantity' => '0.1', 'price' => '65000'], ...$overrides];

    return json_decode($tools->execute('log_transaction', $args), true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function () {
    $this->travelTo('2026-10-25 12:00:00');
    globalAsset('BTC');
});

test('editors and owners log a transaction recorded with the agent source', function (Role $role) {
    [$user, $portfolio] = portfolioWithRole($role);

    $result = agentLogs(writeToolsFor($user, $portfolio));

    expect($result['ok'])->toBeTrue()
        ->and($result['data'])->toMatchArray([
            'type' => 'buy',
            'symbol' => 'BTC',
            'quantity' => '0.10000000',
            'price' => '65000.00000000',
            'date' => '2026-10-25T12:00:00+00:00',
        ]);
    $this->assertDatabaseHas('transactions', [
        'id' => $result['data']['transaction_id'],
        'portfolio_id' => $portfolio->id,
        'created_by' => $user->id,
        'source' => 'agent',
    ]);
    $this->assertDatabaseHas('agent_runs', ['tool' => 'log_transaction', 'outcome' => 'ok', 'user_id' => $user->id]);
})->with([Role::Editor, Role::Owner]);

test('a viewer calling the tool directly is denied by the Action, and nothing is written', function () {
    [$viewer, $portfolio] = portfolioWithRole(Role::Viewer);

    // The viewer is never offered this tool. Calling it anyway proves the list is not the boundary.
    $result = agentLogs(writeToolsFor($viewer, $portfolio));

    expect($result)->toBe(['ok' => false, 'error' => 'This action is unauthorized.']);
    expect(Transaction::count())->toBe(0)
        ->and(AgentRun::sole()->only(['tool', 'outcome']))->toBe(['tool' => 'log_transaction', 'outcome' => 'denied']);
});

test('an oversell is invalid and writes nothing', function () {
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);

    $result = agentLogs(writeToolsFor($editor, $portfolio), ['type' => 'sell', 'quantity' => '1']);

    expect($result)->toBe(['ok' => false, 'error' => 'This would make the position negative at some point in time.']);
    expect(Transaction::count())->toBe(0);
    $this->assertDatabaseHas('agent_runs', ['tool' => 'log_transaction', 'outcome' => 'invalid']);
});

test('a symbol that only exists as another portfolio\'s private asset is invalid', function () {
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);
    [, $otherPortfolio] = portfolioWithRole(Role::Owner);
    Asset::create(['portfolio_id' => $otherPortfolio->id, 'type' => 'cash', 'symbol' => 'VAULT', 'name' => 'Other vault', 'currency' => 'USD', 'price_source' => 'manual']);

    $result = agentLogs(writeToolsFor($editor, $portfolio), ['asset_symbol' => 'VAULT']);

    expect($result)->toBe(['ok' => false, 'error' => 'Asset not found in this portfolio.']);
    expect(Transaction::count())->toBe(0);
});

test('an unknown type such as transfer is invalid, not an exception', function () {
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);

    $result = agentLogs(writeToolsFor($editor, $portfolio), ['type' => 'transfer']);

    expect($result)->toBe(['ok' => false, 'error' => 'The type must be buy, sell, deposit or withdrawal.']);
    $this->assertDatabaseHas('agent_runs', ['tool' => 'log_transaction', 'outcome' => 'invalid']);
});

test('bad quantities are rejected by the Action', function (string $quantity) {
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);

    $result = agentLogs(writeToolsFor($editor, $portfolio), ['quantity' => $quantity]);

    expect($result)->toBe(['ok' => false, 'error' => 'Quantity must be a positive decimal with at most 8 decimal places.']);
    expect(Transaction::count())->toBe(0);
})->with(['-1', '0', 'abc', '1e3']);

test('money sent as a JSON number instead of a string is invalid, not a crash', function () {
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);

    $result = agentLogs(writeToolsFor($editor, $portfolio), ['quantity' => 0.1]);

    expect($result)->toBe(['ok' => false, 'error' => 'quantity is required as a string, for example "0.5" or "BTC".']);
    $this->assertDatabaseHas('agent_runs', ['tool' => 'log_transaction', 'outcome' => 'invalid']);
});

test('missing arguments are invalid', function (string $missing) {
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);
    $args = ['asset_symbol' => 'BTC', 'type' => 'buy', 'quantity' => '0.1', 'price' => '65000'];
    unset($args[$missing]);

    $result = json_decode(writeToolsFor($editor, $portfolio)->execute('log_transaction', $args), true);

    expect($result['ok'])->toBeFalse()->and($result['error'])->toContain($missing);
    expect(Transaction::count())->toBe(0);
})->with(['asset_symbol', 'type', 'quantity', 'price']);

test('symbol and type are case-insensitive', function () {
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);

    $result = agentLogs(writeToolsFor($editor, $portfolio), ['asset_symbol' => 'btc', 'type' => 'BUY']);

    expect($result['ok'])->toBeTrue();
});

test('two identical trades in one run both log', function () {
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);
    $tools = writeToolsFor($editor, $portfolio, 'run-7');

    $first = agentLogs($tools);
    $second = agentLogs($tools);

    expect($first['data']['transaction_id'])->not->toBe($second['data']['transaction_id']);
    expect(Transaction::where('source', 'agent')->count())->toBe(2);
});

test('the note is stored and capped at 200 characters', function () {
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);

    $result = agentLogs(writeToolsFor($editor, $portfolio), ['note' => str_repeat('n', 300)]);

    expect(Transaction::findOrFail($result['data']['transaction_id'])->note)->toBe(str_repeat('n', 200));
});

test('the agent tool has no date argument, so a backdated oversell cannot be requested', function () {
    [$editor, $portfolio] = portfolioWithRole(Role::Editor);
    app(LogTransaction::class)->execute($editor, $portfolio, new LogTransactionData(Asset::where('symbol', 'BTC')->value('id'), TransactionType::Buy, '1', '100'));

    // An unknown "occurred_at" argument is ignored; the sell is dated now and is allowed by R4.
    $result = agentLogs(writeToolsFor($editor, $portfolio), ['type' => 'sell', 'quantity' => '1', 'occurred_at' => '2020-01-01']);

    expect($result['ok'])->toBeTrue()
        ->and($result['data']['date'])->toBe('2026-10-25T12:00:00+00:00');
});
