# Policy and Actions: Agent Task List

**Depends on:** `database_tasks.md` fully done (tables, enums, models, seeder, schema tests passing).
**Specs:** `docs/BUILD_GUIDE.md` sections 1, 7 and 8 for the original design, and **this file where it differs** (the differences are marked **CHANGED**). When they disagree, this file wins.
**Scope:** the write path only. No controllers, no routes, no React, no agent code. Those come in the next task list.

## Rules for the agent (read first)

1. Do **one task at a time**, in order. After each task, **stop** and report. Do not start the next task until the user says "next".
2. Follow the spec. If it is ambiguous or you think it is wrong, **ask**. Do not silently change names, rules, or signatures.
3. No new packages. No files that are not named in the task.
4. Money is always a **decimal string** and all math uses `bcmath` at scale 8. Never `float`, never `(float)` casts.
5. Every Action must call `Gate::forUser($user)->authorize(...)` itself, as its **first statement**. Do not rely on a controller or caller to do it.
6. Writes happen only inside `DB::transaction(...)`. Domain errors are `ValidationException` (bad input or broken rule) or `AuthorizationException` (not allowed). Do not invent other exception types.
7. Add `declare(strict_types=1);` to every new file.
8. Keep code plain. The user must be able to explain every line. No unused code, no clever abstractions.
9. After each task, report in this format:
   - **Changed:** files created or edited
   - **Why:** 2 to 3 sentences on the decisions in this task
   - **Verified:** the exact commands you ran and their result
   - **Open questions:** anything you were unsure about
10. Commit after each task with the message given. Never commit failing tests.

---

## Task 0: Prepare

**Goal:** confirm the database layer is healthy.

**Steps**
- Run `php artisan migrate:fresh --seed` and `./vendor/bin/pest`.
- Copy this file into `docs/` if it is not there.
- Create `tests/Pest.php` helper `portfolioWithRole(Role $role): array` (returns `[User, Portfolio]` where the user has that role in a fresh portfolio) if it does not exist. See `BUILD_GUIDE.md` section 14.
- Add a second helper `globalAsset(string $symbol = 'BTC'): Asset` that creates a global market asset with `currency` USD.

**Done when**
- Both commands pass with no errors.
- `portfolioWithRole(Role::Viewer)` works in a throwaway test.

**Commit:** `Prepare test helpers for Actions`

---

## Task 1: PortfolioPolicy

**Goal:** the three permission checks.

**File:** `app/Policies/PortfolioPolicy.php`

**Spec**
- `view(User, Portfolio): bool` is true for any member.
- `write(User, Portfolio): bool` is true for Editor or higher (`Role::atLeast(Role::Editor)`).
- `manage(User, Portfolio): bool` is true for Owner only.
- Non-members always get false. Use `Portfolio::roleFor()`.

**Done when**
- A Pest test `tests/Feature/PolicyTest.php` proves the matrix: for each of owner, editor, viewer, and a non-member, check `view`, `write`, `manage` through `Gate::forUser($user)->allows('write', $portfolio)` (this also proves auto-discovery works).
- All results match the table in `BUILD_GUIDE.md` section 1.

**Commit:** `Add portfolio policy`

---

## Task 2: LogTransactionData DTO

**Goal:** typed input for logging a transaction.

**File:** `app/Data/LogTransactionData.php`

**Spec:** `final readonly class` with constructor-promoted public properties:
`int $assetId`, `TransactionType $type`, `string $quantity`, `string $price`, `string $fee = '0'`, `?CarbonImmutable $occurredAt = null`, `?string $note = null`, `?string $idempotencyKey = null`.

**Done when:** it can be constructed in a test and the properties are readable. No logic inside.

**Commit:** `Add LogTransactionData`

---

## Task 3: PositionService

**Goal:** compute positions from the ledger and verify they never go negative **at any point in time**.

**File:** `app/Services/PositionService.php`

**Spec**
1. `quantity(int $portfolioId, int $assetId): string` returns the net quantity (buys and deposits positive, sells and withdrawals negative) using a portable `CASE` and `COALESCE` query. Return a string with 8 decimals via `bcadd($value, '0', 8)`.
2. **CHANGED:** `assertNeverNegative(int $portfolioId, int $assetId): void` loads that asset's transactions ordered by `occurred_at` then `id`, keeps a running balance with `bcadd`/`bcsub`, and throws `ValidationException::withMessages(['quantity' => 'This would make the position negative at some point in time.'])` if the balance is ever below zero.

**Why this changed:** the old check compared against today's total, so a backdated sell could pass even though the position was zero at that date.

**Done when**
- Tests in `tests/Feature/PositionServiceTest.php`:
  - buy 1, sell 0.4 gives `0.60000000`
  - buy 1 dated today, then a sell of 1 dated last week makes `assertNeverNegative` throw
  - buy 1 dated last week, sell 1 dated today passes
  - no transactions gives `0.00000000`

**Commit:** `Add PositionService with time-aware position check`

---

## Task 4: LogTransaction Action

**Goal:** the single write path for new ledger rows.

**File:** `app/Actions/LogTransaction.php` (`final class`, constructor takes `PositionService`).

**Signature:** `execute(User $user, Portfolio $portfolio, LogTransactionData $data, TransactionSource $source = TransactionSource::User): Transaction`

**Order of operations (do exactly this)**
1. `Gate::forUser($user)->authorize('write', $portfolio);`
2. **CHANGED, validate input here, not only in the controller**, because the agent path never goes through a Form Request. Throw `ValidationException` when:
   - `quantity` does not match `/^\d{1,16}(\.\d{1,8})?$/` or is not greater than zero (`bccomp($q, '0', 8) <= 0`)
   - `price` or `fee` does not match the same pattern, or `price` is less than zero
   - `occurredAt` is in the future
3. `DB::transaction(function () { ... })` containing, in order:
   1. **CHANGED, idempotency per portfolio:** if a key was given, compute `$key = hash('sha256', $portfolio->id . ':' . $data->idempotencyKey)` (64 chars, fits the column). If a row with that `portfolio_id` and `idempotency_key` exists, return it. Store `$key`, not the raw value.
   2. Lock the portfolio row: `Portfolio::whereKey($portfolio->id)->lockForUpdate()->firstOrFail();`
   3. **R3:** `$asset = $portfolio->usableAssets()->whereKey($data->assetId)->first();` If null, throw `ValidationException` with `asset_id => 'Asset not available in this portfolio.'` (same message whether it does not exist or belongs to someone else).
   4. **CHANGED:** if `$asset->currency !== $portfolio->base_currency`, throw `ValidationException` ("single-currency prototype").
   5. Create the `Transaction` with explicit fields: `portfolio_id`, `asset_id`, `type`, `quantity`, `price`, `fee`, `occurred_at` (default `now()`), `created_by`, `source`, `idempotency_key` (the hash), `note`.
   6. **R4, CHANGED:** if `$data->type->isOutflow()`, call `$this->positions->assertNeverNegative($portfolio->id, $asset->id)`. It runs **after** the insert, inside the DB transaction, so a failure rolls the insert back.
4. Return the transaction.

**Do not:** use `$request`, `auth()`, or any global user. Do not catch exceptions inside the Action.

**Done when:** tests in `tests/Feature/LogTransactionTest.php` (use the helpers) prove:
- Editor and Owner can log a buy. Viewer and non-member get `AuthorizationException`.
- R3: logging against another portfolio's private asset throws `ValidationException`.
- R4: selling more than owned throws, and **no row was inserted** (count unchanged).
- R4 backdated: buy 1 today, then a sell of 1 backdated to last week throws.
- Bad money strings are rejected: `'-5'`, `'0'`, `'abc'`, `'1e3'`, `'1.123456789'`.
- A future `occurredAt` is rejected.
- Same idempotency key twice returns the same row (one row in DB). The same key in a different portfolio creates a separate row.
- Precision: logging `'0.00000001'` and reloading gives `'0.00000001'`.

**Commit:** `Add LogTransaction action`

---

## Task 5: ReverseTransaction Action

**Goal:** corrections without editing history.

**File:** `app/Actions/ReverseTransaction.php`
**Signature:** `execute(User $user, Portfolio $portfolio, Transaction $original): Transaction`

**Spec (from `BUILD_GUIDE.md` 8.4, with the R4 change)**
1. Authorize `write` first.
2. Inside `DB::transaction`: lock the portfolio row.
3. Throw `ValidationException` if `$original->portfolio_id !== $portfolio->id`, if `$original->reverses_id !== null` (cannot reverse a reversal), or if a reversal of it already exists.
4. Create the opposite-type row with the same quantity and price, `fee` 0, `occurred_at` now, `reverses_id` = original id, `source` user, note "Reversal of #id".
5. **CHANGED:** if the new row is an outflow, call `assertNeverNegative` after inserting.

**Done when:** tests prove:
- Reversing a buy restores the position (quantity back to what it was).
- A second reversal of the same row fails.
- Reversing a reversal fails.
- Reversing a buy that was already sold fails and inserts nothing.
- A Viewer cannot reverse. A transaction from another portfolio cannot be reversed through this portfolio.

**Commit:** `Add ReverseTransaction action`

---

## Task 6: SetAssetPrice Action

**Goal:** manual valuation for private assets (R1, R2).

**File:** `app/Actions/SetAssetPrice.php`
**Signature:** `execute(User $user, Portfolio $portfolio, Asset $asset, string $price, ?CarbonImmutable $asOf = null): PriceSnapshot`

**Spec**
1. Authorize `write`.
2. Validate `$price` matches the decimal pattern and is greater than zero.
3. Throw `AuthorizationException` if the asset is global, or its `price_source` is not `Manual`, or its `portfolio_id` differs from `$portfolio->id`.
4. Create a snapshot with `source = 'manual'`, `currency` from the asset, `created_by` the user, `as_of` default `now()`.

**Done when:** tests prove:
- Editor can value the portfolio's private asset.
- R1: nobody, including an Owner, can price a global asset (BTC).
- R2: a Viewer cannot. An Editor of another portfolio cannot value this one's asset.
- Invalid prices are rejected.

**Commit:** `Add SetAssetPrice action`

---

## Task 7: CreatePortfolio Action

**Goal:** creating a portfolio and its first Owner together.

**File:** `app/Actions/CreatePortfolio.php`
**Signature:** `execute(User $user, string $name, string $baseCurrency = 'USD'): Portfolio`

**Spec:** any authenticated user may call it (no Gate check needed, but still require a `User`). Validate `name` is 1 to 100 characters after trimming and `baseCurrency` is exactly 3 uppercase letters. Inside one `DB::transaction`, create the portfolio (`created_by` the user) and an Owner `PortfolioMember` for the same user.

**Done when:** tests prove the creator is Owner, an invalid name or currency throws `ValidationException`, and a failure after the portfolio insert leaves no orphan (simulate by making the membership insert fail, or assert both rows exist together on success).

**Commit:** `Add CreatePortfolio action`

---

## Task 8: Member Actions

**Goal:** sharing, with the "at least one owner" rule.

**Files:** `app/Actions/AddMember.php`, `ChangeMemberRole.php`, `RemoveMember.php`

**Spec (all three):** authorize `manage` first, then do the work in `DB::transaction`.
- `AddMember::execute(User $actor, Portfolio $portfolio, int $userId, Role $role): PortfolioMember`: throw `ValidationException` if the user does not exist or is already a member.
- `ChangeMemberRole::execute(User $actor, Portfolio $portfolio, PortfolioMember $member, Role $newRole): PortfolioMember`
- `RemoveMember::execute(User $actor, Portfolio $portfolio, PortfolioMember $member): void`
- For the last two: first check `$member->portfolio_id === $portfolio->id` (else `ValidationException`). Then lock the portfolio's owner rows (`->where('role', 'owner')->lockForUpdate()`) and refuse (`ValidationException`) any change that would leave **zero owners**.

**Done when:** tests prove:
- Only Owner can add, change, or remove. Editor and Viewer get `AuthorizationException`.
- Duplicate membership is rejected.
- The last Owner cannot be demoted or removed, but can be once another Owner exists.
- A member of a different portfolio cannot be targeted.

**Commit:** `Add member management actions`

---

## Task 9: Rule tests (R1 to R5)

**Goal:** one named test per rule, so the video can say "R3 is enforced here and proven there".

**File:** `tests/Feature/RulesTest.php`

**Spec:** these tests may reuse earlier tests, but they must exist under exactly these names, each with a one-line comment stating the rule:
- `r1_users_cannot_price_global_assets`
- `r2_viewer_cannot_value_private_asset`
- `r3_cannot_log_against_other_portfolios_asset`
- `r4_cannot_oversell`
- `r4_cannot_oversell_with_a_backdated_sell`
- `r5_ledger_is_append_only`: assert that `$tx->update([...])` and `$tx->delete()` throw `LogicException`. Add a **second, honest test** `r5_known_gap_query_builder_update_bypasses_model_guard`, which proves `Transaction::where('id', $tx->id)->update(['note' => 'x'])` currently succeeds, with a comment saying the DB-level guard in Task 11 closes this. If Task 11 is done, flip it to expect a `QueryException`.

**Done when:** `./vendor/bin/pest --filter=RulesTest` passes and the output lists all the names above.

**Commit:** `Add rule tests`

---

## Task 10: Write-path architecture test

**Goal:** make "all writes go through Actions" something the build checks.

**File:** `tests/Architecture/WritePathTest.php`

**Spec**
1. `arch('actions are final')->expect('App\Actions')->toBeFinal();`
2. A test that scans every `.php` file under `app/Http` and `app/Agent` (use `Symfony\Component\Finder\Finder`) and fails if any file matches the regex `/Transaction::(create|insert|upsert|firstOrCreate|updateOrCreate)|PriceSnapshot::(create|insert|upsert)|->forceDelete\(|DB::table\(\s*[\'"]transactions[\'"]\s*\)->(insert|update|delete)/`. The failure message must list the offending files.
3. A test that fails if any file under `app/Http` or `app/Agent` contains `PortfolioMember::create` or `->members()->create`.

State in a comment that this is a text scan and cannot catch every bypass, and that it is a guardrail, not a proof.

**Done when**
- It passes now.
- Temporarily add `Transaction::create([]);` to a controller file (or a scratch file under `app/Http`) and confirm the test fails, then remove it.

**Commit:** `Add write-path architecture test`

---

## Task 11 (optional): Database-level append-only guard

**Goal:** close the R5 gap for query-builder updates and deletes. Skip this if time is short.

**Spec:** a new migration `add_append_only_triggers_to_transactions.php`. The schema builder cannot create triggers, so this is the **one allowed use of raw SQL** in the project. Guard it with `if (DB::getDriverName() !== 'mysql') return;` and write the Postgres variant as a comment.

```sql
CREATE TRIGGER transactions_no_update BEFORE UPDATE ON transactions
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger is append-only';

CREATE TRIGGER transactions_no_delete BEFORE DELETE ON transactions
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger is append-only';
```

`down()` drops both triggers.

**Check carefully:** `migrate:fresh --seed` still works (the seeder only inserts), and the whole Pest suite still passes. If any test needs to delete or update transactions, change the test, never the trigger.

**Done when:** `Transaction::where('id', $id)->update([...])` throws `QueryException`, and the known-gap test from Task 9 is flipped accordingly.

**Commit:** `Enforce append-only ledger with database triggers`

---

## Task 12: Final review

**Goal:** a short report the user can use for the video.

**Steps**
- Run `./vendor/bin/pest` and `./vendor/bin/pint --test` (if installed). Report results.
- List every file created or changed since Task 0.
- For each rule R1 to R5, point to the file and line that enforces it and the test that proves it.
- List every place where code differs from `BUILD_GUIDE.md` and the reason (the CHANGED items above).
- List known gaps, honestly: no concurrency test, single currency only, average-cost valuation is not built yet, cash is not debited on buys, writes by the agent are immediate.
- Do **not** start controllers, routes, UI, or the agent. That is the next list.

**Commit:** none unless the review found fixes. Then: `Fix issues found in actions review`.
