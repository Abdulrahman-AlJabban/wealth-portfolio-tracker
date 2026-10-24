# Database Layer: Agent Task List

**Spec:** `docs/database_plan.md` (the source of truth for schema, enums, models, seeder, tests).
**Project:** Laravel + Breeze (React, TypeScript, Pest) + MySQL 8. Wealth Portfolio Tracker.

## Rules for the agent (read first)

1. Do **one task at a time**, in order. After each task, **stop** and report. Do not start the next task until the user says "next".
2. Follow the spec exactly. If the spec is ambiguous or you think it is wrong, **ask**. Do not silently change the schema, names, column types, or FK policy.
3. Do not add packages. Do not create files that are not named in the task.
4. Schema builder only. No raw SQL in migrations. No `float` for money. No MySQL `ENUM` columns.
5. Keep code plain and readable. The user must be able to explain every line, so no clever abstractions and no unused code.
6. After each task, report in this format:
   - **Changed:** files created or edited
   - **Why:** 2 to 3 sentences on the decisions in this task
   - **Verified:** the exact commands you ran and their result
   - **Open questions:** anything you were unsure about
7. Commit after each task using the commit message given. Never commit failing migrations.

---

## Task 0: Prepare

**Goal:** the project is ready for database work.

**Steps**
- Copy `database_plan.md` and this file into `docs/` if they are not there.
- Confirm a git repo exists (`git init` if not) and the working tree is clean.
- Confirm `.env` points at MySQL (`DB_CONNECTION=mysql`, database `wealth`) and `docker compose up -d` is running MySQL. Create `docker-compose.yml` from the plan's Docker section in `BUILD_GUIDE.md` if it is missing.
- Confirm PHP extensions `bcmath` and `pdo_mysql` are loaded.

**Done when**
- `php artisan migrate:status` connects and shows the Breeze default migrations as ran (or pending).
- `php -m | grep -E "bcmath|pdo_mysql"` prints both.

**Commit:** `Add docs and local MySQL setup`

---

## Task 1: Enums

**Goal:** the five backed enums exist.

**Spec:** plan section 4.

**Files:** `app/Enums/Role.php`, `TransactionType.php`, `AssetType.php`, `PriceSource.php`, `TransactionSource.php` (one file each, `namespace App\Enums;`).

**Done when**
- `php artisan tinker --execute="echo App\Enums\Role::Editor->atLeast(App\Enums\Role::Viewer) ? 'yes' : 'no';"` prints `yes`.
- `TransactionType::Buy->opposite()` returns `Sell`, and `Sell->isOutflow()` is true.

**Commit:** `Add domain enums`

---

## Task 2: Migrations for portfolios and portfolio_members

**Goal:** the first two tables.

**Spec:** plan sections 5.1 and 5.2, and the FK policy in 2.3.

**Steps**
- Create with `php artisan make:migration create_portfolios_table`, then `create_portfolio_members_table`.
- Check `ls database/migrations`: the portfolios file must sort **before** the members file. Rename the timestamp prefix if they tie.
- Write `up()` and `down()` exactly as the spec shows.

**Done when**
- `php artisan migrate` succeeds.
- `php artisan db:table portfolio_members` shows the unique index on `(portfolio_id, user_id)`.
- `php artisan migrate:rollback` then `php artisan migrate` both succeed.

**Commit:** `Add portfolios and portfolio_members tables`

---

## Task 3: Migration for assets

**Goal:** the assets table with a nullable `portfolio_id`.

**Spec:** plan section 5.3 and section 2.5.

**Notes:** `portfolio_id` is nullable and restricts on delete. Do **not** try to make global symbols unique with a DB constraint; the spec explains why.

**Done when**
- `php artisan migrate` succeeds.
- `php artisan db:table assets` shows `portfolio_id` as nullable and the index on `(portfolio_id, symbol)`.

**Commit:** `Add assets table`

---

## Task 4: Migration for transactions

**Goal:** the append-only ledger table.

**Spec:** plan section 5.4.

**Check carefully**
- `quantity`, `price`, `fee` are `decimal(24, 8)`.
- `reverses_id` is nullable, **unique**, and references `transactions.id`.
- `idempotency_key` is nullable and unique.
- All FKs use `restrictOnDelete()`.
- Composite index on `(portfolio_id, asset_id, occurred_at)`.

**Done when**
- `php artisan migrate` succeeds.
- `php artisan db:table transactions` shows the decimal types, both unique indexes, and the composite index.

**Commit:** `Add transactions ledger table`

---

## Task 5: Migrations for price_snapshots and agent_runs

**Goal:** the last two tables.

**Spec:** plan sections 5.5 and 5.6.

**Check carefully:** unique index `(asset_id, as_of, source)` on `price_snapshots`. `created_by` there is nullable with `nullOnDelete()`.

**Done when**
- `php artisan migrate:fresh` succeeds with all tables.
- `php artisan db:show` lists: users, portfolios, portfolio_members, assets, transactions, price_snapshots, agent_runs (plus Laravel's own tables).

**Commit:** `Add price_snapshots and agent_runs tables`

---

## Task 6: Models

**Goal:** six models with casts and relations, plus `User` additions.

**Spec:** plan sections 6.1 to 6.6.

**Files:** `app/Models/Portfolio.php`, `PortfolioMember.php`, `Asset.php`, `Transaction.php`, `PriceSnapshot.php`, `AgentRun.php`, and edit `app/Models/User.php`.

**Check carefully**
- `Portfolio` uses `SoftDeletes`.
- `Portfolio::usableAssets()` returns global assets plus that portfolio's private ones.
- `Transaction` throws `LogicException` on update and delete.
- Money columns cast to `decimal:8`. Enum columns cast to their enum classes.
- Use imports properly (`use Illuminate\Database\Eloquent\Relations\...`). No unused imports.

**Done when**
- `php artisan tinker --execute="App\Models\Portfolio::count();"` runs without error.
- `./vendor/bin/pint --test` (or the project's formatter) passes, if Pint is installed.

**Commit:** `Add models with casts and relations`

---

## Task 7: Seeder

**Goal:** demo data matching the spec.

**Spec:** plan section 7.

**Check carefully**
- Users: alice (owner), bob (editor), carol (viewer), all with password `password`.
- Global assets BTC, ETH, AAPL created with `firstOrCreate`, each with one market snapshot.
- Private asset "Damascus flat" in the Family portfolio with one manual snapshot.
- A second portfolio owned by Carol, used for isolation tests later.

**Done when**
- `php artisan migrate:fresh --seed` succeeds from an empty database.
- Tinker: `App\Models\Asset::whereNull('portfolio_id')->count()` is `3`.
- Tinker: `App\Models\Transaction::count()` is `4`.

**Commit:** `Add demo seeder`

---

## Task 8: Verification and schema tests

**Goal:** prove the schema behaves as designed.

**Spec:** plan section 8.

**Steps**
- Create `tests/Feature/SchemaTest.php` with the four tests from the spec, fully implemented (the spec leaves three as outlines):
  1. membership is unique per user and portfolio
  2. a transaction can only be reversed once (two rows with the same `reverses_id` throw `QueryException`)
  3. price snapshot is unique per asset, time and source
  4. money keeps eight decimal places (`0.00000001` round-trips as the string `'0.00000001'` after the 8-place cast)
- Use a **separate test database**: set `DB_DATABASE=wealth_test` in `phpunit.xml`, and create it in MySQL, so `RefreshDatabase` never wipes the demo data.

**Done when**
- `./vendor/bin/pest` passes, including the Breeze default tests.
- Running the tests does not change the data in the `wealth` database.

**Commit:** `Add schema tests`

---

## Task 9: Final review

**Goal:** a short report the user can use for the video.

**Steps**
- List every file created or changed since Task 0.
- For each item below, point to the file and line that implements it: DECIMAL money, append-only guard, soft-deleted portfolios, nullable `portfolio_id` on assets, unique `reverses_id`, unique snapshot key.
- List anything that deviates from `database_plan.md`, with the reason. If there is nothing, say so.
- Do **not** start on Policies or Actions. That is a separate task list.

**Commit:** none unless the review found fixes. Then: `Fix issues found in database review`.
