# HTTP Layer and UI: Agent Task List

**Depends on:** `database_tasks.md` and `actions_tasks.md` fully done (tables, models, policy, Actions, rule tests, architecture test, all passing).
**Specs:** `docs/BUILD_GUIDE.md` sections 9, 12 and 13 for the original design, and **this file where it differs** (marked **CHANGED**). This file wins on conflict.
**Scope:** read model (`ValuationService`), controllers, routes, Form Requests, Inertia props, and the React pages for portfolios, holdings, transactions, assets and members. **Not in scope:** the AI agent, price sync job, realtime. Leave a visible slot for the agent panel only.

## Rules for the agent (read first)

1. Do **one task at a time**, in order. After each task, **stop** and report. Do not start the next task until the user says "next".
2. Follow the spec. If it is ambiguous or you think it is wrong, **ask**. Do not silently change names, routes, or props.
3. **Controllers are thin:** parse input, call one Action (or one read service), return a response. No business rules, no `Model::create/update/delete` in `app/Http`. Anything that writes must be an Action. The architecture test enforces this.
4. Money is always a **decimal string**. In PHP use `bcmath`. In TypeScript never do arithmetic on money; only format it for display.
5. The UI hides controls using server-provided booleans (`abilities.write`, `abilities.manage`). The server still enforces everything. Never trust a hidden button.
6. No new packages (PHP or npm). Use what Breeze installed: Inertia, React, TypeScript, Tailwind. No shadcn, no chart libraries, no state libraries.
7. Add `declare(strict_types=1);` to every new PHP file. Keep TypeScript strict. No `any`.
8. Keep code plain. The user must be able to explain every line. No unused code, no clever abstractions.
9. After each task, report in this format:
   - **Changed:** files created or edited
   - **Why:** 2 to 3 sentences on the decisions in this task
   - **Verified:** the exact commands you ran and their result
   - **Open questions:** anything you were unsure about
10. Commit after each task with the message given. Never commit failing tests.

---

## Task 0: Prepare

**Goal:** confirm the base works end to end.

**Steps**
- Run `php artisan migrate:fresh --seed`, `./vendor/bin/pest`, and `npm run build` (or `npm run dev`).
- Start the app (`PHP_CLI_SERVER_WORKERS=4 php artisan serve` and `npm run dev`). Log in as `alice@example.com` / `password`.
- Copy this file into `docs/`.

**Done when:** tests pass, the Breeze dashboard loads after login, no console errors.

**Commit:** `Prepare for HTTP and UI work`

---

## Task 1: ValuationService (read model)

**Goal:** one place that computes holdings, cost basis and profit, used by the dashboard now and the agent later.

**File:** `app/Services/ValuationService.php`

**Spec:** `summary(Portfolio $portfolio): array` as in `BUILD_GUIDE.md` 8.7. Returns:

```
['holdings' => [ ['asset_id','symbol','name','type','quantity','price','price_as_of','value','cost','pnl'], ... ],
 'total_value' => string, 'total_cost' => string]
```

- Group the portfolio's transactions by asset, ordered by `occurred_at` then `id`.
- **Average-cost method** with `bcmath` scale 8: buys and deposits add `quantity * price + fee` to cost and `quantity` to quantity; outflows remove `avg * quantity` from cost.
- Skip assets whose final quantity is zero or less.
- Price comes from `Asset::latestSnapshot`. If there is no snapshot, price is `'0.00000000'` and `price_as_of` is `null`.
- Query efficiently: load transactions once, load assets with `latestSnapshot` once (no query inside the per-asset loop).
- All amounts are strings with 8 decimals.

**Done when:** `tests/Feature/ValuationServiceTest.php` proves:
- buy 2 at 100, buy 2 at 200, sell 1: quantity `3`, cost `450` (average 150, so 600 minus 150)
- price from the latest snapshot, not the oldest
- an asset with no snapshot is valued at `0` with `price_as_of` null
- fully sold assets are omitted
- totals equal the sum of the rows
- an empty portfolio returns empty holdings and totals `0.00000000`

**Commit:** `Add ValuationService`

---

## Task 2: Extra Actions for portfolio deletion and private assets

**Goal:** the remaining writes the UI needs, as Actions, so controllers never write.

**Files:** `app/Actions/DeletePortfolio.php`, `app/Actions/CreatePrivateAsset.php`

**Spec**
- `DeletePortfolio::execute(User, Portfolio): void` authorizes `manage`, then soft-deletes (`$portfolio->delete()`). No cascade, the ledger stays.
- `CreatePrivateAsset::execute(User, Portfolio, string $name, AssetType $type): Asset` authorizes `write`. Allowed types: `RealEstate` and `Cash` only (throw `ValidationException` otherwise). Validate `name` is 1 to 100 characters after trimming. Creates the asset with `portfolio_id` = the portfolio, `price_source` = `Manual`, `currency` = the portfolio's base currency, `symbol` null.
- **CHANGED:** extend the regex in `tests/Architecture/WritePathTest.php` to also fail on `Asset::(create|insert|upsert)`, `Portfolio::(create|insert)` and `->delete()` calls inside `app/Http` and `app/Agent`. Keep the failure message listing the files.

**Done when:** tests prove: Owner can delete and the row is soft-deleted (still in the DB with `deleted_at`), Editor cannot delete, Viewer cannot create assets, invalid types and names are rejected, a global asset can never be created through this Action, and the architecture test still passes.

**Commit:** `Add DeletePortfolio and CreatePrivateAsset actions`

---

## Task 3: Inertia shared props, Form Requests, routes

**Goal:** the skeleton of the HTTP layer.

**Files**
- `app/Http/Middleware/HandleInertiaRequests.php` (edit)
- `app/Http/Requests/StorePortfolioRequest.php`, `LogTransactionRequest.php`, `StoreAssetRequest.php`, `StoreValuationRequest.php`, `StoreMemberRequest.php`, `UpdateMemberRequest.php`
- `routes/web.php` (edit)

**Spec**
- Shared props: `auth.user` (`id`, `name`, `email`) and `flash.success`, `flash.error`.
- Form Requests validate **shape only**. `authorize()` returns true because authorization happens inside the Actions. Money fields use the regex `/^\d{1,16}(\.\d{1,8})?$/` and are strings. `type` and `role` use `Rule::enum`.
- `LogTransactionRequest` has a `toData(): LogTransactionData` method. `occurred_at` is optional and parsed with `CarbonImmutable::parse`.
- Routes exactly as `BUILD_GUIDE.md` 9.1, inside `Route::middleware('auth')`, with `Route::scopeBindings()` for the nested routes. Remove any Breeze routes you do not need? **No**, leave Breeze auth routes alone.
- There must be **no** PUT, PATCH or DELETE route for transactions.
- Controller classes may be empty stubs in this task, but each route must point at an existing class and method.

**Done when:** `php artisan route:list` shows the expected routes with the `auth` middleware, and no transaction route allows PUT, PATCH or DELETE.

**Commit:** `Add form requests, routes and shared props`

---

## Task 4: PortfolioController

**Goal:** list, create, show and delete portfolios.

**File:** `app/Http/Controllers/PortfolioController.php`, plus minimal Inertia pages `resources/js/Pages/Portfolios/Index.tsx` and `Show.tsx` that just print their props as JSON for now.

**Spec**
- `index`: the user's portfolios, each as `{id, name, base_currency, role}`.
- `store`: `StorePortfolioRequest` then `CreatePortfolio`, redirect to `portfolios.show` with a flash message.
- `show`: `Gate::authorize('view', $portfolio)`, then render `Portfolios/Show` with these props (**exact names**):
  - `portfolio`: `{id, name, base_currency}`
  - `role`: string
  - `abilities`: `{write: bool, manage: bool}` from `Gate::allows`
  - `summary`: `ValuationService::summary()`
  - `transactions`: the **latest 50** by `occurred_at` desc then `id` desc, each `{id, type, quantity, price, fee, occurred_at, source, note, asset: {id, symbol, name}, author: {id, name}, reverses_id, reversed: bool}`. Eager load `asset`, `author`, `reversal` (avoid N+1).
  - `assets`: `$portfolio->usableAssets()` as `{id, symbol, name, type, is_private: bool}`
  - `members`: `{id, role, user: {id, name, email}}`
- `destroy`: `DeletePortfolio`, redirect to the index.
- **CHANGED (security):** a non-member opening `show` gets 403. Soft-deleted portfolios return 404 (default route binding).

**Done when:** logging in as alice and opening a seeded portfolio shows the JSON props with the shape above, and N+1 is avoided (check with `DB::listen` or Telescope-free logging in a test: assert the query count for `show` stays constant when adding more transactions).

**Commit:** `Add PortfolioController`

---

## Task 5: Transaction, asset, valuation and member controllers

**Goal:** all remaining endpoints, thin.

**Files:** `TransactionController`, `AssetController`, `ValuationController`, `MemberController` in `app/Http/Controllers/`.

**Spec**
- `TransactionController@store`: validated request, `LogTransaction::execute($user, $portfolio, $request->toData())`, `back()->with('success', ...)`.
- `TransactionController@reverse`: `ReverseTransaction`, back with flash.
- `AssetController@store`: `CreatePrivateAsset`, back.
- `ValuationController@store`: `SetAssetPrice` for the route's `{asset}`, back.
- `MemberController@store/update/destroy`: the three member Actions, back.
- No controller method contains `if` statements about roles. If you feel the need, the logic belongs in an Action.

**Done when:** each endpoint can be exercised with a Pest test (next task), and `./vendor/bin/pest --filter=WritePath` still passes.

**Commit:** `Add transaction, asset, valuation and member controllers`

---

## Task 6: HTTP feature tests

**Goal:** prove the HTTP layer respects the rules.

**File:** `tests/Feature/Http/PortfolioHttpTest.php` (split into more files if large)

**Spec (all required)**
- Guests are redirected to login for every route.
- A non-member gets 403 on `show` and on every write endpoint.
- Viewer: `show` works, every write endpoint returns 403.
- Editor: can log a transaction (row exists, `source` is `user`), cannot add or remove members (403).
- Owner: can add, change and remove members, and delete the portfolio.
- Validation: `quantity` of `abc`, `-1`, `0`, `1.123456789`, and a missing `asset_id` give session errors (use `assertSessionHasErrors`), and no row is inserted.
- Scoped bindings: reversing a transaction that belongs to **another** portfolio through this portfolio's URL returns 404.
- `PUT`, `PATCH` and `DELETE` on a transaction URL return 405 or 404.
- `show` passes the props from Task 4 (`assertInertia` with `has('summary.holdings')`, `where('role', 'editor')`, `where('abilities.write', true)`).
- The same idempotency key submitted twice in a row creates one row.

**Done when:** all pass, and the full suite is green.

**Commit:** `Add HTTP feature tests`

---

## Task 7: TypeScript types and formatting helpers

**Goal:** a typed contract for the props, and safe display helpers.

**Files:** `resources/js/types/portfolio.d.ts` (or `.ts`), `resources/js/lib/format.ts`

**Spec**
- Types for every prop in Task 4: `Role`, `Abilities`, `Holding`, `Summary`, `TransactionRow`, `AssetOption`, `MemberRow`, `PortfolioListItem`, and the page props `ShowProps`, `IndexProps`. Money fields are typed `string`.
- `format.ts`:
  - `formatAmount(value: string, maxFractionDigits = 2): string` using `Intl.NumberFormat`. It is for **display only**, say so in a comment.
  - `formatQuantity(value: string): string` trims trailing zeros but keeps up to 8 decimals.
  - `formatSigned(value: string): string` adds `+` for positives.
  - `isNegative(value: string): boolean` using a string check (starts with `-`), no `parseFloat` for logic.
- Type the pages with `usePage<...>()` or the page prop generics so there are no `any`s.

**Done when:** `npx tsc --noEmit` passes.

**Commit:** `Add TypeScript types and format helpers`

---

## Task 8: Portfolios index page

**File:** `resources/js/Pages/Portfolios/Index.tsx`

**Spec**
- List portfolios as cards or rows with name, currency, and a **role badge**.
- A create form (name, currency select defaulting to USD) using Inertia `useForm`: inline field errors, submit disabled while `processing`, input preserved on error.
- Empty state: "You do not have a portfolio yet. Create your first one below."
- Flash messages shown in a dismissible banner from the layout (add to the authenticated layout once, reuse everywhere).

**Done when:** creating a portfolio works, the empty state appears for a user with none (register a new user to see it), and keyboard users can reach every control.

**Commit:** `Add portfolios index page`

---

## Task 9: Show page shell and holdings

**Files:** `resources/js/Pages/Portfolios/Show.tsx`, `resources/js/Components/HoldingsTable.tsx`

**Spec**
- Header: portfolio name, role badge, **total value**, total cost, profit with sign and color **plus** a `+`/`-` sign (never color alone).
- Tabs (simple state, no router): Holdings, Transactions, Members. The tab is kept in the URL hash (`#transactions`) so a refresh keeps it.
- `HoldingsTable`: symbol and name, quantity, price with "as of" time (or "no price yet"), value, profit, and an allocation bar (percentage of total value, computed server-side or with `bcmath`-safe logic: if you compute in JS, only for the bar width and clearly commented as display only).
- Numeric columns right-aligned with `font-variant-numeric: tabular-nums`. Wrap the table in `overflow-x-auto` for mobile.
- Empty state for no holdings: "No holdings yet. Log your first buy in the Transactions tab."
- Reserve a clearly marked, empty `<aside>` or section for the future `AgentPanel` with the comment `// AgentPanel goes here (agent task list)`.

**Done when:** seeded data renders correctly, totals match the `ValuationService` test values, and the page is usable at 375 px width.

**Commit:** `Add portfolio show page and holdings table`

---

## Task 10: Transactions tab

**Files:** `resources/js/Components/TransactionForm.tsx`, `TransactionList.tsx`

**Spec**
- `TransactionForm` is **rendered only if `abilities.write`**.
- Fields: asset (select from `assets`, private ones labelled), type (buy, sell, deposit, withdrawal), quantity, price, optional fee, optional date, optional note.
- Money inputs are `type="text"` with `inputMode="decimal"`, never `type="number"` (avoids exponent notation and float parsing).
- **CHANGED (double-submit protection):** generate an `idempotency_key` with `crypto.randomUUID()` when the form mounts, send it with the request, and generate a new one after a successful submit. A double click then cannot log twice.
- Show server errors inline per field (`form.errors`), disable submit while `processing`, keep input on error, clear it on success.
- `TransactionList`: newest first, columns for date, type, asset, quantity, price, source badge (`user` or `agent`), author. A **Reverse** button only when `abilities.write` and the row is not itself a reversal and not already reversed (`reversed` is false). Reversed rows appear struck through with a "Reversed" label, and reversal rows show "Reverses #id". Confirm before reversing (a small inline confirm, not `window.confirm`).
- Empty state: "No transactions yet."
- Show the note that the list shows the latest 50.

**Done when:** as Bob (editor) you can log and reverse; as Carol (viewer) the form and Reverse buttons are absent; submitting an oversell shows the server error under the quantity field.

**Commit:** `Add transactions tab`

---

## Task 11: Assets and valuations

**Files:** `resources/js/Components/AssetsPanel.tsx` (inside the Holdings tab or its own section)

**Spec**
- List the private assets of this portfolio with their latest manual value and date.
- If `abilities.write`: a small form to add a private asset (name, type real estate or cash) and, per private asset, a "Set value" form (price string, optional date) that posts to the valuation endpoint.
- Global assets are shown as read-only market assets with no value form (R1).
- Same input rules as Task 10 (text inputs, inline errors, disabled while processing).

**Done when:** Alice can add "Rental flat" and set a value, which then shows in holdings after she logs a buy of it. Carol sees the list but no forms.

**Commit:** `Add private assets and manual valuations UI`

---

## Task 12: Members tab

**File:** `resources/js/Components/MembersPanel.tsx`

**Spec**
- Everyone sees the member list (name, email, role).
- If `abilities.manage`: add member by **email** (**CHANGED:** the form takes an email, and `StoreMemberRequest` + `AddMember` look up the user by email and return a validation error "No user with that email" when missing), change role via a select, remove with an inline confirm.
- Server errors (for example "A portfolio needs at least one owner.") are shown inline next to the row.
- A member cannot see manage controls when `abilities.manage` is false.

If this requires `AddMember` to accept an email, adjust the Action's signature (`string $email` instead of `int $userId`), update its tests from `actions_tasks.md` Task 8, and mention the change in the report.

**Done when:** Alice can add Bob by email, promote and demote him, and cannot remove the last owner.

**Commit:** `Add members tab`

---

## Task 13: UX and accessibility pass

**Goal:** remove generic-looking or fragile UI.

**Checklist (verify each, fix what fails)**
- One font family, two weights, one accent color, one neutral scale. No gradients, no emoji icons, no stock illustrations.
- Every button and input has a visible focus ring. Forms have `<label>`s tied to inputs. Errors use `aria-describedby`.
- Loading: `processing` states on every submit, a skeleton or "Loading" state if you defer any prop.
- Flash and error banners use `role="status"` or `role="alert"`.
- No horizontal page scroll at 375 px. Tables scroll inside their container.
- 403 and 404 pages are readable (a short message and a link back), not the framework default if time allows.
- No `console.log`, no unused imports, `npx tsc --noEmit` and `npm run build` pass, ESLint and Prettier pass if configured.

**Done when:** the checklist is walked through and the report states pass or fail for each item.

**Commit:** `Polish UI states and accessibility`

---

## Task 14: Final review

**Steps**
- Run `./vendor/bin/pest`, `npx tsc --noEmit`, `npm run build`. Report results.
- List every file created or changed since Task 0.
- Walk through the three demo scripts and report any step that fails:
  1. Alice logs a buy and then reverses it. Holdings update.
  2. Bob (editor) logs a transaction. Carol (viewer) sees no write controls and gets 403 when posting directly (use `curl` or a test).
  3. Alice adds Dave by email as a viewer, then tries to demote herself as the last owner and is refused.
- List known gaps: transactions list limited to 50 with no pagination, no realtime updates, no dark mode, single currency, agent panel not built, price sync not built.
- Do **not** start the agent or the sync job. Those are the next lists.

**Commit:** none unless the review found fixes. Then: `Fix issues found in HTTP and UI review`.
