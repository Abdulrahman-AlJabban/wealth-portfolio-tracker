<?php

declare(strict_types=1);

use App\Actions\CreatePortfolio;
use App\Enums\Role;
use App\Models\PortfolioMember;
use App\Models\User;
use Illuminate\Validation\ValidationException;

test('the creator becomes the owner of the new portfolio', function () {
    $user = User::factory()->create();

    $portfolio = app(CreatePortfolio::class)->execute($user, 'Family wealth', 'EUR');

    $this->assertDatabaseHas('portfolios', [
        'id' => $portfolio->id,
        'name' => 'Family wealth',
        'base_currency' => 'EUR',
        'created_by' => $user->id,
    ]);
    $this->assertDatabaseHas('portfolio_members', [
        'portfolio_id' => $portfolio->id,
        'user_id' => $user->id,
        'role' => 'owner',
    ]);
    $this->assertDatabaseCount('portfolio_members', 1);
    expect($portfolio->roleFor($user))->toBe(Role::Owner);
});

test('the base currency defaults to USD', function () {
    $portfolio = app(CreatePortfolio::class)->execute(User::factory()->create(), 'Savings');

    expect($portfolio->base_currency)->toBe('USD');
});

test('the name is trimmed', function () {
    $portfolio = app(CreatePortfolio::class)->execute(User::factory()->create(), '  Savings  ');

    $this->assertDatabaseHas('portfolios', ['id' => $portfolio->id, 'name' => 'Savings']);
});

test('names up to 100 characters are accepted, counted as characters', function (string $name) {
    $portfolio = app(CreatePortfolio::class)->execute(User::factory()->create(), $name);

    $this->assertDatabaseHas('portfolios', ['id' => $portfolio->id, 'name' => $name]);
})->with([
    'single character' => 'A',
    '100 latin' => str_repeat('a', 100),
    '100 arabic' => str_repeat('ث', 100),
]);

test('invalid names are rejected', function (string $name) {
    expect(fn () => app(CreatePortfolio::class)->execute(User::factory()->create(), $name))
        ->toThrow(ValidationException::class, 'The name must be between 1 and 100 characters.');

    $this->assertDatabaseCount('portfolios', 0);
})->with([
    'empty' => '',
    'only spaces' => '   ',
    '101 characters' => str_repeat('a', 101),
]);

test('invalid currencies are rejected', function (string $currency) {
    expect(fn () => app(CreatePortfolio::class)->execute(User::factory()->create(), 'Savings', $currency))
        ->toThrow(ValidationException::class, 'The base currency must be a 3-letter uppercase code, such as USD.');

    $this->assertDatabaseCount('portfolios', 0);
})->with(['usd', 'US', 'USDD', 'U1D', '']);

test('a failed owner membership leaves no orphan portfolio', function () {
    PortfolioMember::creating(fn () => throw new RuntimeException('membership insert failed'));

    expect(fn () => app(CreatePortfolio::class)->execute(User::factory()->create(), 'Savings'))
        ->toThrow(RuntimeException::class, 'membership insert failed');

    $this->assertDatabaseCount('portfolios', 0);
    $this->assertDatabaseCount('portfolio_members', 0);
});
