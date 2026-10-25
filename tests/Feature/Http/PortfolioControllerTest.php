<?php

declare(strict_types=1);

use App\Actions\LogTransaction;
use App\Actions\ReverseTransaction;
use App\Data\LogTransactionData;
use App\Enums\Role;
use App\Enums\TransactionSource;
use App\Enums\TransactionType;
use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\PriceSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo('2026-10-25 12:00:00');
});

describe('index', function () {
    test('lists only the user\'s portfolios with their role', function () {
        [$editor, $portfolio] = portfolioWithRole(Role::Editor);
        portfolioWithRole(Role::Owner);

        $this->actingAs($editor)
            ->get(route('portfolios.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Portfolios/Index')
                ->has('portfolios', 1)
                ->where('portfolios.0', [
                    'id' => $portfolio->id,
                    'name' => 'Test Portfolio',
                    'base_currency' => 'USD',
                    'role' => 'editor',
                ]));
    });

    test('does not list soft-deleted portfolios', function () {
        [$owner, $portfolio] = portfolioWithRole(Role::Owner);
        $portfolio->delete();

        $this->actingAs($owner)
            ->get(route('portfolios.index'))
            ->assertInertia(fn (Assert $page) => $page->has('portfolios', 0));
    });
});

describe('store', function () {
    test('creates the portfolio and redirects to it with a flash message', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('portfolios.store'), ['name' => 'Family wealth', 'base_currency' => 'EUR']);

        $portfolio = Portfolio::where('name', 'Family wealth')->firstOrFail();
        $response->assertRedirect(route('portfolios.show', $portfolio))->assertSessionHas('success', 'Portfolio created.');
        expect($portfolio->base_currency)->toBe('EUR')
            ->and($portfolio->roleFor($user))->toBe(Role::Owner);
    });

    test('defaults the currency to USD', function () {
        $this->actingAs(User::factory()->create())->post(route('portfolios.store'), ['name' => 'Savings']);

        $this->assertDatabaseHas('portfolios', ['name' => 'Savings', 'base_currency' => 'USD']);
    });
});

describe('show', function () {
    test('passes the documented props', function () {
        [$editor, $portfolio] = portfolioWithRole(Role::Editor);
        $btc = globalAsset();
        $flat = Asset::create(['portfolio_id' => $portfolio->id, 'type' => 'real_estate', 'name' => 'Flat', 'currency' => 'USD', 'price_source' => 'manual']);
        $log = app(LogTransaction::class);
        $buy = $log->execute($editor, $portfolio, new LogTransactionData($btc->id, TransactionType::Buy, '2', '100', note: 'First'));
        $reversal = app(ReverseTransaction::class)->execute($editor, $portfolio, $buy);
        $log->execute($editor, $portfolio, new LogTransactionData($flat->id, TransactionType::Buy, '1', '80000'), TransactionSource::Agent);

        $this->actingAs($editor)
            ->get(route('portfolios.show', $portfolio))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Portfolios/Show')
                ->where('portfolio', ['id' => $portfolio->id, 'name' => 'Test Portfolio', 'base_currency' => 'USD'])
                ->where('role', 'editor')
                ->where('abilities', ['write' => true, 'manage' => false])
                ->has('summary.holdings', 1)
                ->has('summary.total_value')
                ->has('summary.total_cost')
                ->has('transactions', 3)
                ->where('transactions.2', [
                    'id' => $buy->id,
                    'type' => 'buy',
                    'quantity' => '2.00000000',
                    'price' => '100.00000000',
                    'fee' => '0.00000000',
                    'occurred_at' => '2026-10-25T12:00:00+00:00',
                    'source' => 'user',
                    'note' => 'First',
                    'asset' => ['id' => $btc->id, 'symbol' => 'BTC', 'name' => 'BTC'],
                    'author' => ['id' => $editor->id, 'name' => $editor->name],
                    'reverses_id' => null,
                    'reversed' => true,
                ])
                ->where('transactions.1.id', $reversal->id)
                ->where('transactions.1.reverses_id', $buy->id)
                ->where('transactions.1.reversed', false)
                ->where('transactions.0.source', 'agent')
                ->has('assets', 2)
                ->where('assets', fn ($assets) => collect($assets)->firstWhere('id', $flat->id)['is_private'] === true
                    && collect($assets)->firstWhere('id', $btc->id)['is_private'] === false)
                // Neither asset has a snapshot in this test.
                ->where('assets.0.price', null)
                ->where('assets.0.price_as_of', null)
                ->has('members', 2)
                ->has('members.0', fn (Assert $member) => $member
                    ->where('role', 'owner')
                    ->has('id')
                    ->has('user', fn (Assert $user) => $user->hasAll(['id', 'name', 'email']))));
    });

    test('assets carry their latest price and its date', function () {
        [$owner, $portfolio] = portfolioWithRole(Role::Owner);
        $btc = globalAsset();
        PriceSnapshot::create(['asset_id' => $btc->id, 'price' => '60000', 'currency' => 'USD', 'as_of' => '2026-10-20 12:00:00', 'source' => 'market']);
        PriceSnapshot::create(['asset_id' => $btc->id, 'price' => '65000', 'currency' => 'USD', 'as_of' => '2026-10-24 12:00:00', 'source' => 'market']);

        $this->actingAs($owner)
            ->get(route('portfolios.show', $portfolio))
            ->assertInertia(fn (Assert $page) => $page
                ->where('assets.0.price', '65000.00000000')
                ->where('assets.0.price_as_of', '2026-10-24T12:00:00+00:00'));
    });

    test('owners get both abilities and viewers get neither', function (Role $role, bool $write, bool $manage) {
        [$user, $portfolio] = portfolioWithRole($role);

        $this->actingAs($user)
            ->get(route('portfolios.show', $portfolio))
            ->assertInertia(fn (Assert $page) => $page
                ->where('role', $role->value)
                ->where('abilities', ['write' => $write, 'manage' => $manage]));
    })->with([
        'owner' => [Role::Owner, true, true],
        'viewer' => [Role::Viewer, false, false],
    ]);

    test('non-members get 403', function () {
        [, $portfolio] = portfolioWithRole(Role::Owner);

        $this->actingAs(User::factory()->create())
            ->get(route('portfolios.show', $portfolio))
            ->assertForbidden();
    });

    test('403 and 404 render a readable error page and keep their status', function () {
        [$owner, $portfolio] = portfolioWithRole(Role::Owner);

        $this->actingAs(User::factory()->create())
            ->get(route('portfolios.show', $portfolio))
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 403));

        $this->actingAs($owner)
            ->get('/portfolios/999999')
            ->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 404));
    });

    test('JSON requests still get a JSON 403', function () {
        [, $portfolio] = portfolioWithRole(Role::Owner);

        $this->actingAs(User::factory()->create())
            ->getJson(route('portfolios.show', $portfolio))
            ->assertForbidden()
            ->assertJsonStructure(['message']);
    });

    test('soft-deleted portfolios return 404', function () {
        [$owner, $portfolio] = portfolioWithRole(Role::Owner);
        $portfolio->delete();

        $this->actingAs($owner)
            ->get(route('portfolios.show', $portfolio))
            ->assertNotFound();
    });

    test('only the latest 50 transactions are sent', function () {
        [$owner, $portfolio] = portfolioWithRole(Role::Owner);
        $btc = globalAsset();
        $log = app(LogTransaction::class);
        foreach (range(1, 51) as $_) {
            $log->execute($owner, $portfolio, new LogTransactionData($btc->id, TransactionType::Buy, '1', '1'));
        }

        $this->actingAs($owner)
            ->get(route('portfolios.show', $portfolio))
            ->assertInertia(fn (Assert $page) => $page->has('transactions', 50));
    });

    test('the query count does not grow with more transactions', function () {
        [$owner, $portfolio] = portfolioWithRole(Role::Owner);
        $log = app(LogTransaction::class);
        $logBuyOfNewAsset = function (string $symbol) use ($log, $owner, $portfolio) {
            $log->execute($owner, $portfolio, new LogTransactionData(globalAsset($symbol)->id, TransactionType::Buy, '1', '1'));
        };
        $countShowQueries = function () use ($owner, $portfolio): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($owner)->get(route('portfolios.show', $portfolio))->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $logBuyOfNewAsset('BTC');
        $queriesWithOneTransaction = $countShowQueries();

        foreach (['ETH', 'SOL', 'ADA', 'DOT'] as $symbol) {
            $logBuyOfNewAsset($symbol);
        }

        expect($countShowQueries())->toBe($queriesWithOneTransaction);
    });
});

describe('destroy', function () {
    test('owners soft-delete the portfolio and go back to the list', function () {
        [$owner, $portfolio] = portfolioWithRole(Role::Owner);

        $this->actingAs($owner)
            ->delete(route('portfolios.destroy', $portfolio))
            ->assertRedirect(route('portfolios.index'))
            ->assertSessionHas('success', 'Portfolio deleted.');

        $this->assertSoftDeleted($portfolio);
    });

    test('editors get 403', function () {
        [$editor, $portfolio] = portfolioWithRole(Role::Editor);

        $this->actingAs($editor)->delete(route('portfolios.destroy', $portfolio))->assertForbidden();

        $this->assertNotSoftDeleted($portfolio);
    });
});
