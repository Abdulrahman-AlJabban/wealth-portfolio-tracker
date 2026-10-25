<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
 * "All writes go through Actions" as something the build checks.
 *
 * The last two tests are a plain text scan of app/Http and app/Agent. They cannot catch every
 * bypass (an alias, a variable class name, a helper in another folder), so they are a guardrail
 * against the obvious mistake, not a proof. The real enforcement is in the Actions themselves.
 */

/**
 * Relative paths of the PHP files under app/Http and app/Agent whose contents match $pattern.
 * Folders that do not exist yet (app/Agent arrives with the agent work) are skipped.
 *
 * @return list<string>
 */
function writePathOffenders(string $pattern): array
{
    $root = dirname(__DIR__, 2);
    $folders = array_filter(
        ["{$root}/app/Http", "{$root}/app/Agent"],
        fn (string $folder) => is_dir($folder),
    );

    $offenders = [];

    foreach (Finder::create()->files()->name('*.php')->in($folders) as $file) {
        if (preg_match($pattern, $file->getContents()) === 1) {
            $offenders[] = str_replace('\\', '/', substr($file->getRealPath(), strlen($root) + 1));
        }
    }

    sort($offenders);

    return $offenders;
}

arch('actions are final')
    ->expect('App\Actions')
    ->toBeFinal();

test('controllers and agent code never write domain rows directly', function () {
    $offenders = writePathOffenders(
        '/Transaction::(create|insert|upsert|firstOrCreate|updateOrCreate)|PriceSnapshot::(create|insert|upsert)|Asset::(create|insert|upsert)|Portfolio::(create|insert)|->forceDelete\(|DB::table\(\s*[\'"]transactions[\'"]\s*\)->(insert|update|delete)/',
    );

    expect($offenders)->toBe([], 'Write through an Action instead. Offending files: '.implode(', ', $offenders));
});

test('controllers and agent code never delete records directly', function () {
    $offenders = writePathOffenders('/->delete\(\)/');

    expect($offenders)->toBe([], 'Use DeletePortfolio, RemoveMember or another Action instead. Offending files: '.implode(', ', $offenders));
});

test('controllers and agent code never create memberships directly', function () {
    $offenders = writePathOffenders('/PortfolioMember::create|->members\(\)->create/');

    expect($offenders)->toBe([], 'Use AddMember or CreatePortfolio instead. Offending files: '.implode(', ', $offenders));
});
