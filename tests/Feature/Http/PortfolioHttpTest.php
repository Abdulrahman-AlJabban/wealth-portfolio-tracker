<?php

declare(strict_types=1);

use App\Actions\LogTransaction;
use App\Data\LogTransactionData;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Asset;
use App\Models\Portfolio;
use App\Models\PortfolioMember;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * One portfolio with an owner, editor and viewer, a private asset, and one ledger row.
 *
 * @return array{portfolio: Portfolio, owner: User, editor: User, viewer: User, viewerMember: PortfolioMember, btc: Asset, flat: Asset, transaction: Transaction}
 */
function httpFixture(): array
{
    [$owner, $portfolio] = portfolioWithRole(Role::Owner);
    $editorMember = memberWithRole($portfolio, Role::Editor);
    $viewerMember = memberWithRole($portfolio, Role::Viewer);
    $btc = globalAsset();
    $flat = Asset::create(['portfolio_id' => $portfolio->id, 'type' => 'real_estate', 'name' => 'Flat', 'currency' => 'USD', 'price_source' => 'manual']);
    $transaction = app(LogTransaction::class)->execute($owner, $portfolio, new LogTransactionData($btc->id, TransactionType::Buy, '2', '100'));

    return [
        'portfolio' => $portfolio,
        'owner' => $owner,
        'editor' => $editorMember->user,
        'viewer' => $viewerMember->user,
        'viewerMember' => $viewerMember,
        'btc' => $btc,
        'flat' => $flat,
        'transaction' => $transaction,
    ];
}

/**
 * Every endpoint that writes, with a valid payload, as [method, url, data].
 *
 * @param  array<string, mixed>  $fixture
 * @return array{0: string, 1: string, 2: array<string, mixed>}
 */
function writeEndpoint(string $name, array $fixture): array
{
    $portfolio = $fixture['portfolio'];

    return match ($name) {
        'log transaction' => ['POST', route('transactions.store', $portfolio), ['asset_id' => $fixture['btc']->id, 'type' => 'buy', 'quantity' => '1', 'price' => '100']],
        'reverse transaction' => ['POST', route('transactions.reverse', [$portfolio, $fixture['transaction']]), []],
        'add asset' => ['POST', route('assets.store', $portfolio), ['name' => 'Bank account', 'type' => 'cash']],
        'set valuation' => ['POST', route('valuations.store', [$portfolio, $fixture['flat']]), ['price' => '85000']],
        'add member' => ['POST', route('members.store', $portfolio), ['email' => User::factory()->create()->email, 'role' => 'viewer']],
        'change role' => ['PATCH', route('members.update', [$portfolio, $fixture['viewerMember']]), ['role' => 'editor']],
        'remove member' => ['DELETE', route('members.destroy', [$portfolio, $fixture['viewerMember']]), []],
        'delete portfolio' => ['DELETE', route('portfolios.destroy', $portfolio), []],
    };
}

/**
 * Counts that must not change when a write is refused.
 *
 * @return array<string, int>
 */
function writableState(Portfolio $portfolio): array
{
    return [
        'transactions' => Transaction::count(),
        'assets' => Asset::count(),
        'snapshots' => DB::table('price_snapshots')->count(),
        'members' => $portfolio->members()->count(),
        'editors' => $portfolio->members()->where('role', 'editor')->count(),
        'portfolios' => Portfolio::count(),
    ];
}

dataset('write endpoints', [
    'log transaction', 'reverse transaction', 'add asset', 'set valuation',
    'add member', 'change role', 'remove member', 'delete portfolio',
]);

describe('guests', function () {
    test('are redirected to login', function (string $endpoint) {
        $fixture = httpFixture();
        [$method, $url, $data] = writeEndpoint($endpoint, $fixture);

        $this->call($method, $url, $data)->assertRedirect(route('login'));
    })->with('write endpoints');

    test('are redirected to login from the read pages', function () {
        $fixture = httpFixture();

        $this->get(route('portfolios.index'))->assertRedirect(route('login'));
        $this->get(route('portfolios.show', $fixture['portfolio']))->assertRedirect(route('login'));
        $this->post(route('portfolios.store'), ['name' => 'X'])->assertRedirect(route('login'));
    });
});

describe('non-members', function () {
    test('get 403 on show', function () {
        $fixture = httpFixture();

        $this->actingAs(User::factory()->create())
            ->get(route('portfolios.show', $fixture['portfolio']))
            ->assertForbidden();
    });

    test('get 403 on every write endpoint and change nothing', function (string $endpoint) {
        $fixture = httpFixture();
        [$method, $url, $data] = writeEndpoint($endpoint, $fixture);
        $before = writableState($fixture['portfolio']);

        $this->actingAs(User::factory()->create())->call($method, $url, $data)->assertForbidden();

        expect(writableState($fixture['portfolio']))->toBe($before);
    })->with('write endpoints');
});

describe('viewers', function () {
    test('can open the portfolio', function () {
        $fixture = httpFixture();

        $this->actingAs($fixture['viewer'])
            ->get(route('portfolios.show', $fixture['portfolio']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('role', 'viewer')
                ->where('abilities', ['write' => false, 'manage' => false]));
    });

    test('get 403 on every write endpoint and change nothing', function (string $endpoint) {
        $fixture = httpFixture();
        [$method, $url, $data] = writeEndpoint($endpoint, $fixture);
        $before = writableState($fixture['portfolio']);

        $this->actingAs($fixture['viewer'])->call($method, $url, $data)->assertForbidden();

        expect(writableState($fixture['portfolio']))->toBe($before);
    })->with('write endpoints');
});

describe('editors', function () {
    test('can log a transaction recorded with the user source', function () {
        $fixture = httpFixture();
        [$method, $url, $data] = writeEndpoint('log transaction', $fixture);

        $this->actingAs($fixture['editor'])
            ->from(route('portfolios.show', $fixture['portfolio']))
            ->call($method, $url, $data)
            ->assertRedirect(route('portfolios.show', $fixture['portfolio']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Transaction logged.');

        $this->assertDatabaseHas('transactions', [
            'portfolio_id' => $fixture['portfolio']->id,
            'asset_id' => $fixture['btc']->id,
            'quantity' => '1.00000000',
            'created_by' => $fixture['editor']->id,
            'source' => 'user',
        ]);
    });

    test('see the documented show props', function () {
        $fixture = httpFixture();

        $this->actingAs($fixture['editor'])
            ->get(route('portfolios.show', $fixture['portfolio']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Portfolios/Show')
                ->has('summary.holdings', 1)
                ->where('role', 'editor')
                ->where('abilities.write', true)
                ->where('abilities.manage', false));
    });

    test('cannot manage members', function (string $endpoint) {
        $fixture = httpFixture();
        [$method, $url, $data] = writeEndpoint($endpoint, $fixture);
        $before = writableState($fixture['portfolio']);

        $this->actingAs($fixture['editor'])->call($method, $url, $data)->assertForbidden();

        expect(writableState($fixture['portfolio']))->toBe($before);
    })->with(['add member', 'change role', 'remove member', 'delete portfolio']);
});

describe('owners', function () {
    test('can add, change and remove members', function () {
        $fixture = httpFixture();
        $portfolio = $fixture['portfolio'];
        $newcomer = User::factory()->create();
        $this->actingAs($fixture['owner'])->from(route('portfolios.show', $portfolio));

        $this->post(route('members.store', $portfolio), ['email' => $newcomer->email, 'role' => 'viewer'])->assertSessionHasNoErrors();
        $member = $portfolio->members()->where('user_id', $newcomer->id)->firstOrFail();
        $this->patch(route('members.update', [$portfolio, $member]), ['role' => 'editor'])->assertSessionHasNoErrors();
        expect($member->fresh()->role)->toBe(Role::Editor);
        $this->delete(route('members.destroy', [$portfolio, $member]))->assertSessionHasNoErrors();

        $this->assertModelMissing($member);
    });

    test('see an unknown email as a field error', function () {
        $fixture = httpFixture();
        $portfolio = $fixture['portfolio'];

        $this->actingAs($fixture['owner'])
            ->from(route('portfolios.show', $portfolio))
            ->post(route('members.store', $portfolio), ['email' => 'nobody@example.com', 'role' => 'viewer'])
            ->assertSessionHasErrors(['email' => 'No user with that email.']);
    });

    test('see the last-owner rule as a field error', function () {
        $fixture = httpFixture();
        $portfolio = $fixture['portfolio'];
        $ownMembership = $portfolio->members()->where('user_id', $fixture['owner']->id)->firstOrFail();

        $this->actingAs($fixture['owner'])
            ->from(route('portfolios.show', $portfolio))
            ->patch(route('members.update', [$portfolio, $ownMembership]), ['role' => 'viewer'])
            ->assertRedirect(route('portfolios.show', $portfolio))
            ->assertSessionHasErrors(['role' => 'A portfolio needs at least one owner.']);
    });

    test('can add a private asset, value it, buy it and see it in holdings', function () {
        $fixture = httpFixture();
        $portfolio = $fixture['portfolio'];
        $this->actingAs($fixture['owner'])->from(route('portfolios.show', $portfolio));

        $this->post(route('assets.store', $portfolio), ['name' => 'Rental flat', 'type' => 'real_estate'])->assertSessionHasNoErrors();
        $rentalFlat = Asset::where('name', 'Rental flat')->firstOrFail();
        $this->post(route('valuations.store', [$portfolio, $rentalFlat]), ['price' => '120000'])->assertSessionHasNoErrors();
        $this->post(route('transactions.store', $portfolio), ['asset_id' => $rentalFlat->id, 'type' => 'buy', 'quantity' => '1', 'price' => '110000'])
            ->assertSessionHasNoErrors();

        $this->get(route('portfolios.show', $portfolio))->assertInertia(fn (Assert $page) => $page
            ->where('summary.holdings', fn ($holdings) => collect($holdings)->contains(fn ($holding) => $holding['name'] === 'Rental flat'
                && $holding['value'] === '120000.00000000'
                && $holding['pnl'] === '10000.00000000')));
    });

    test('can delete the portfolio', function () {
        $fixture = httpFixture();

        $this->actingAs($fixture['owner'])
            ->delete(route('portfolios.destroy', $fixture['portfolio']))
            ->assertRedirect(route('portfolios.index'));

        $this->assertSoftDeleted($fixture['portfolio']);
    });
});

describe('validation', function () {
    test('bad quantities give a session error and insert nothing', function (string $quantity) {
        $fixture = httpFixture();
        [, $url, $data] = writeEndpoint('log transaction', $fixture);

        $this->actingAs($fixture['editor'])
            ->from(route('portfolios.show', $fixture['portfolio']))
            ->post($url, [...$data, 'quantity' => $quantity])
            ->assertSessionHasErrors('quantity');

        $this->assertDatabaseCount('transactions', 1);
    })->with(['abc', '-1', '0', '1.123456789']);

    test('a missing asset gives a session error and inserts nothing', function () {
        $fixture = httpFixture();
        [, $url, $data] = writeEndpoint('log transaction', $fixture);
        unset($data['asset_id']);

        $this->actingAs($fixture['editor'])
            ->from(route('portfolios.show', $fixture['portfolio']))
            ->post($url, $data)
            ->assertSessionHasErrors('asset_id');

        $this->assertDatabaseCount('transactions', 1);
    });

    test('the form\'s ISO timestamp is accepted and stored in UTC', function () {
        $this->travelTo('2026-10-25 12:00:00');
        $fixture = httpFixture();
        [, $url, $data] = writeEndpoint('log transaction', $fixture);

        // TransactionForm sends new Date(localValue).toISOString(), e.g. 14:00 in Damascus (UTC+3).
        $this->actingAs($fixture['editor'])
            ->post($url, [...$data, 'occurred_at' => '2026-10-25T11:00:00.000Z'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('transactions', ['created_by' => $fixture['editor']->id, 'occurred_at' => '2026-10-25 11:00:00']);
    });

    test('a refused reversal comes back under the quantity key', function () {
        $fixture = httpFixture();
        $portfolio = $fixture['portfolio'];
        app(LogTransaction::class)->execute($fixture['owner'], $portfolio, new LogTransactionData($fixture['btc']->id, TransactionType::Sell, '2', '100'));

        // TransactionList reads errors.transaction or errors.quantity from the reverse request.
        $this->actingAs($fixture['owner'])
            ->from(route('portfolios.show', $portfolio))
            ->post(route('transactions.reverse', [$portfolio, $fixture['transaction']]))
            ->assertSessionHasErrors(['quantity' => 'This would make the position negative at some point in time.']);
    });

    test('an oversell shows the R4 message under the quantity field', function () {
        $fixture = httpFixture();
        [, $url, $data] = writeEndpoint('log transaction', $fixture);

        $this->actingAs($fixture['editor'])
            ->from(route('portfolios.show', $fixture['portfolio']))
            ->post($url, [...$data, 'type' => 'sell', 'quantity' => '5'])
            ->assertSessionHasErrors(['quantity' => 'This would make the position negative at some point in time.']);

        $this->assertDatabaseCount('transactions', 1);
    });
});

describe('ledger protection', function () {
    test('reversing another portfolio\'s transaction through this portfolio\'s URL returns 404', function () {
        $fixture = httpFixture();
        [$otherOwner, $otherPortfolio] = portfolioWithRole(Role::Owner);
        $foreign = app(LogTransaction::class)->execute(
            $otherOwner, $otherPortfolio, new LogTransactionData($fixture['btc']->id, TransactionType::Buy, '1', '100'),
        );

        $this->actingAs($fixture['owner'])
            ->post(route('transactions.reverse', [$fixture['portfolio'], $foreign]))
            ->assertNotFound();

        $this->assertDatabaseMissing('transactions', ['reverses_id' => $foreign->id]);
    });

    test('PUT, PATCH and DELETE on a transaction URL are not routes', function (string $method) {
        $fixture = httpFixture();
        $portfolio = $fixture['portfolio'];
        $transaction = $fixture['transaction'];
        $this->actingAs($fixture['owner']);

        $statuses = [
            $this->call($method, "/portfolios/{$portfolio->id}/transactions/{$transaction->id}", ['quantity' => '9'])->status(),
            $this->call($method, "/portfolios/{$portfolio->id}/transactions", ['quantity' => '9'])->status(),
        ];

        expect($statuses)->each->toBeIn([404, 405]);
        $this->assertDatabaseHas('transactions', ['id' => $transaction->id, 'quantity' => '2.00000000']);
    })->with(['PUT', 'PATCH', 'DELETE']);

    test('the same idempotency key submitted twice creates one row', function () {
        $fixture = httpFixture();
        [, $url, $data] = writeEndpoint('log transaction', $fixture);
        $this->actingAs($fixture['editor'])->from(route('portfolios.show', $fixture['portfolio']));

        $this->post($url, [...$data, 'idempotency_key' => 'form-123'])->assertSessionHasNoErrors();
        $this->post($url, [...$data, 'idempotency_key' => 'form-123'])->assertSessionHasNoErrors();

        // The fixture's own row plus exactly one from the two submits.
        $this->assertDatabaseCount('transactions', 2);
        expect(Transaction::whereNotNull('idempotency_key')->count())->toBe(1);
    });
});
