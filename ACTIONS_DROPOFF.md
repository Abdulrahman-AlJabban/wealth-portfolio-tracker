# Policy and Actions Implementation - Dropoff

**Project:** Wealth Portfolio Tracker
**Date:** October 25, 2026
**Status:** Complete (Task 11, the optional database triggers, was skipped)

---

## Overview

This document summarizes the write path built from `docs/actions_tasks.md`: the portfolio policy, the transaction DTO, the position service and the seven Actions. Controllers, routes, the UI and the agent are not part of it. They come in the next task list.

**Results at handoff**
- `./vendor/bin/pest`: 156 tests pass (361 assertions)
- `vendor/bin/pint --test`: passes
- `php artisan migrate:fresh --seed`: works, demo users alice, bob and carol

---

## Where Each Rule Is Enforced and Proven

| Rule | Enforced at | Proven by |
|---|---|---|
| **R1** Global assets are priced only by the sync job | `app/Actions/SetAssetPrice.php:35`, `isGlobal()` leads to `AuthorizationException` | `tests/Feature/RulesTest.php:26` `r1_users_cannot_price_global_assets` |
| **R2** Only an Editor or Owner of the owning portfolio values a private asset | `app/Actions/SetAssetPrice.php:31` (Gate) and `:35` (`portfolio_id` and `price_source` checks) | `tests/Feature/RulesTest.php:37` `r2_viewer_cannot_value_private_asset` |
| **R3** The asset must be global or this portfolio's own | `app/Actions/LogTransaction.php:71`, the `usableAssets()` lookup, same message whether missing or foreign | `tests/Feature/RulesTest.php:54` `r3_cannot_log_against_other_portfolios_asset` |
| **R4** A position is never negative at any point in time | `app/Actions/LogTransaction.php:49` (lock), `:102` (check after the insert, then rollback), `app/Services/PositionService.php:42` (ledger replay in time order) | `tests/Feature/RulesTest.php:73` `r4_cannot_oversell` and `:86` `r4_cannot_oversell_with_a_backdated_sell` |
| **R5** The ledger is append-only | `app/Models/Transaction.php:27-28` (model guards), unique `reverses_id` in the transactions migration (line 25), no edit or delete routes | `tests/Feature/RulesTest.php:100` `r5_ledger_is_append_only`; known gap at `:122` |

Every Action calls `Gate::forUser(...)->authorize(...)` as its first statement, except `CreatePortfolio`, which any signed-in user may call.

### Permission matrix

Proven in `tests/Feature/PolicyTest.php` through `Gate::forUser()`, which also proves the policy is auto-discovered.

| Ability | Owner | Editor | Viewer | Non-member |
|---|---|---|---|---|
| `view` | yes | yes | yes | no |
| `write` | yes | yes | no | no |
| `manage` | yes | no | no | no |

---

## What Was Implemented

### Policy
- **`app/Policies/PortfolioPolicy.php`**: `view` (any member), `write` (Editor or higher), `manage` (Owner only). All three go through `Portfolio::roleFor()`.

### DTO
- **`app/Data/LogTransactionData.php`**: `final readonly` class. Money and quantities are decimal strings, never floats.

### Service
- **`app/Services/PositionService.php`**
  - `quantity()`: net quantity from one portable `CASE`/`COALESCE` query, returned as an 8-decimal string
  - `assertNeverNegative()`: replays the ledger ordered by `occurred_at` then `id`, throws if the running balance ever drops below zero

### Actions (all `final`, all in `app/Actions/`)

| Action | Authorizes | What it does |
|---|---|---|
| `LogTransaction` | `write` | Validates input, locks the portfolio, checks idempotency, R3 asset lookup, currency check, inserts, R4 check |
| `ReverseTransaction` | `write` | Appends the opposite row with `reverses_id`. Refuses double reversals, reversing a reversal, and foreign rows |
| `SetAssetPrice` | `write` | Manual valuation of a private asset (R1, R2) |
| `CreatePortfolio` | none (any user) | Portfolio and Owner membership in one transaction |
| `AddMember` | `manage` | Refuses unknown users and existing members |
| `ChangeMemberRole` | `manage` | Never leaves zero owners |
| `RemoveMember` | `manage` | Never removes the last owner |

### Tests

| File | Tests |
|---|---|
| `tests/Feature/PolicyTest.php` | 4 |
| `tests/Unit/Data/LogTransactionDataTest.php` | 3 |
| `tests/Feature/PositionServiceTest.php` | 7 |
| `tests/Feature/LogTransactionTest.php` | 41 |
| `tests/Feature/ReverseTransactionTest.php` | 8 |
| `tests/Feature/SetAssetPriceTest.php` | 16 |
| `tests/Feature/CreatePortfolioTest.php` | 15 |
| `tests/Feature/AddMemberTest.php`, `ChangeMemberRoleTest.php`, `RemoveMemberTest.php` | 21 |
| `tests/Feature/RulesTest.php` | 7 (one named test per rule) |
| `tests/Architecture/WritePathTest.php` | 3 |

Helpers in `tests/Pest.php`: `portfolioWithRole()`, `memberWithRole()`, `globalAsset()`.

---

## Where the Code Differs From BUILD_GUIDE.md

**Changes required by `actions_tasks.md` (marked CHANGED there)**
1. R4 is time-aware: it replays the ledger instead of comparing against today's total, so a backdated oversell is caught.
2. R4 is checked after the insert, so the new row is part of the replay and a failure rolls the insert back.
3. Input validation lives in the Actions, because the agent never goes through a Form Request.
4. Idempotency keys are scoped per portfolio: `sha256(portfolio_id:key)`.
5. Single currency is enforced: assets in another currency are rejected.
6. `ReverseTransaction` uses the same time-aware check.

**Changes made during implementation, with the reason**
7. The idempotency lookup runs after the portfolio lock, so two same-key requests cannot both miss it and the second fail on the unique index (a 500).
8. Reusing an idempotency key with a different asset, type, quantity, price or fee is rejected instead of quietly returning the old row.
9. `SetAssetPrice` rejects future `as_of` dates. The latest `as_of` is the current price, so a future date would override every real valuation until then.
10. `SetAssetPrice` uses `updateOrCreate` on the unique key, so two valuations in the same second replace each other instead of failing.
11. The last-owner check reads the locked owner rows, not the possibly stale `$member->role`. A test proves it, and fails if the check is swapped back to `$member->role`.
12. `AddMember` locks the portfolio row, so two adds of the same user cannot race.
13. Tests run on MySQL (`wealth_test`) with InnoDB forced, instead of SQLite. See the next section.

---

## Problems Found and Fixed Along the Way

1. **Every table was MyISAM.** The local MySQL server's default engine is MyISAM, which silently ignores transactions, row locks and foreign keys. The R4 rollback design would have looked fine and not worked. `config/database.php` now forces `'engine' => 'InnoDB'`, and both databases were rebuilt.
2. **SQLite was the wrong test database.** It stores decimals as floats, so `1 - 0.99999999` summed to `1.0E-8`, which `bcmath` rejects. It also ignores row locks. Tests now run on the MySQL `wealth_test` database, as `database_tasks.md` originally asked.
3. **`Portfolio::roleFor()` crashed for every member.** The `PortfolioMember` cast already returns the `Role` enum, so the extra `Role::from()` threw. Every policy check depends on this method.
4. **The frontend did not build.** `@vitejs/plugin-react` and `@types/node` were too old for Vite 8, and `app.tsx` imported a `./bootstrap` file that was never committed. Fixed so the Breeze page tests pass.
5. **Unused import in `DatabaseSeeder.php`**, flagged by Pint in the final review.

These are good examples for the "agentic execution" part of the video: problems that were invisible until a test or a probe exposed them.

---

## Known Gaps

- **No concurrency test.** The locks are reasoned about, not exercised with parallel processes.
- **R5 query-builder gap.** `Transaction::whereKey(...)->update()` succeeds, because the optional database triggers (Task 11) were skipped. `r5_known_gap_query_builder_update_bypasses_model_guard` documents it.
- **R1 is half-enforced.** Users are refused, but the sync job does not exist yet, so "only the job writes market snapshots" is a convention for now.
- **Single currency only.** No conversion; other-currency assets are rejected.
- **Average-cost valuation is not built yet.** There is no `ValuationService`.
- **Cash is not debited on buys.**
- **Agent writes will be immediate.** There is no confirmation step.
- **The architecture test is a text scan.** It is a guardrail against the obvious mistake, not a proof.
- **The owner guard is duplicated** in `ChangeMemberRole` and `RemoveMember` (about five lines each).
- **Valuation corrections overwrite.** A second manual valuation for the same moment replaces the earlier snapshot row.
- **`npm audit` reports 3 moderate vulnerabilities**, left alone because the fix requires breaking upgrades.

---

## Test Database Notes

- Tests use the MySQL database `wealth_test`, configured only in `phpunit.xml`.
- Create it once: `CREATE DATABASE wealth_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
- There is no `.env.testing`, so `php artisan ... --env=testing` still targets the dev `wealth` database. Touch the test database only through Pest.

---

## Git Commits

1. `Install Laravel Boost and fix frontend build`
2. `Prepare test helpers for Actions`
3. `Add portfolio policy`
4. `Add LogTransactionData`
5. `Force InnoDB and run tests on a MySQL test database`
6. `Add PositionService with time-aware position check`
7. `Update database dropoff with post-handoff fixes`
8. `Add LogTransaction action`
9. `Harden LogTransaction idempotency`
10. `Add ReverseTransaction action`
11. `Add SetAssetPrice action`
12. `Add CreatePortfolio action`
13. `Add member management actions`
14. `Add rule tests`
15. `Add write-path architecture test`
16. `Fix issues found in actions review`

---

## Next Steps (Not Part of This Task)

- Controllers, Form Requests and routes (no edit or delete routes for transactions; the R5 test checks this)
- `ValuationService` (average cost) and the dashboard pages
- Agent integration (tools call these same Actions)
- Scheduled price sync job (completes R1)
- Optional: database triggers for the R5 gap (`actions_tasks.md` Task 11)

---

## Contact

For questions about this implementation, refer to:
- Spec: `docs/actions_tasks.md`
- Build Guide: `BUILD_GUIDE.md`
- Database layer: `DATABASE_DROPOFF.md`
