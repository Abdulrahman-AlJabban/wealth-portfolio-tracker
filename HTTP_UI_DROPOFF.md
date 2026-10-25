# HTTP Layer and UI Implementation - Dropoff

**Project:** Wealth Portfolio Tracker
**Date:** October 25, 2026
**Status:** Complete

---

## Overview

This document summarizes the HTTP layer and UI built from `docs/http_ui_tasks.md`: the `ValuationService` read model, two more Actions, Form Requests, routes, controllers, and the Inertia/React pages for portfolios, holdings, transactions, assets and members. The AI agent and the price sync job are not part of it. They come in the next task lists.

**Results at handoff**
- `./vendor/bin/pest`: 257 tests pass (765 assertions)
- `npx tsc --noEmit`, `npm run build`, `vendor/bin/pint --test`: all pass
- `php artisan migrate:fresh --seed`: works, demo users alice, bob and carol

---

## Demo Scripts (run against the live app)

All three were run with `curl` as each seeded user against `php artisan serve`, not only through tests.

| Script | Result |
|---|---|
| **1. Alice logs a buy and reverses it** | Pass. BTC 0.5 → 0.6 → 0.5. The reversal row has `reverses_id`, the original shows `reversed`. "Transaction logged." flashes once. A second reverse is refused: "This transaction has already been reversed." |
| **2. Bob logs a transaction, Carol is refused** | Pass. Bob's sell is logged under his name. Carol gets `abilities {write: false, manage: false}`, so every write control is hidden. Her direct requests to log, reverse, add an asset, set a valuation and delete the portfolio all return **403** with nothing changed. |
| **3. Alice adds Dave by email, then tries to demote herself** | Pass. Dave registers and sees the empty state. Alice adds `Dave@Example.com` as viewer (case-insensitive match). An unknown email gives "No user with that email." Demoting or removing herself as the last owner gives "A portfolio needs at least one owner." |

---

## What Was Implemented

### Read model
- **`app/Services/ValuationService.php`**: `summary()` returns holdings, `total_value`, `total_cost` and `total_pnl`. Average-cost method with `bcmath` at scale 8. Three queries however many assets are held (a test proves the count is constant).

### Actions (added in this phase)
- **`DeletePortfolio`**: Owner only, soft delete. The ledger stays.
- **`CreatePrivateAsset`**: Editor or higher. Real estate or cash only. `portfolio_id`, `price_source` and `currency` are set by the Action, never taken from the caller, so it can never create a global asset. Names are unique per portfolio (case-insensitive), with a DB unique index as the backstop.
- **`AddMember`** (changed): takes an email instead of a user id.

### HTTP layer
- **Routes** (`routes/web.php`): BUILD_GUIDE 9.1, all behind `auth`, with `scopeBindings()` so `{transaction}`, `{asset}` and `{member}` must belong to `{portfolio}` (404 otherwise). No PUT, PATCH or DELETE route for transactions (R5).
- **Form Requests** (`app/Http/Requests/`): shape only; `authorize()` returns true because the Actions authorize. Money uses `LogTransaction::DECIMAL_PATTERN`. `LogTransactionRequest::toData()` builds the DTO.
- **Controllers** (`app/Http/Controllers/`): `PortfolioController`, `TransactionController`, `AssetController`, `ValuationController`, `MemberController`. Each method parses input, calls one Action, and redirects with a flash message. No role logic in any controller.
- **Shared props**: `auth.user` (`id`, `name`, `email` only) and `flash.success`, `flash.error`.
- **Error pages**: 403 and 404 render a readable Inertia `Error` page and keep their status code. JSON requests still get JSON.

### UI (`resources/js/`)
| File | What it does |
|---|---|
| `Pages/Portfolios/Index.tsx` | Portfolio cards with role badges, empty state, create form |
| `Pages/Portfolios/Show.tsx` | Header with value, cost and signed profit; Holdings, Transactions and Members tabs (kept in the URL hash, arrow-key navigation); reserved slot for the agent panel |
| `Components/HoldingsTable.tsx` | Quantity, price with "as of" time, value, signed profit, allocation bar |
| `Components/AssetsPanel.tsx` | Private assets with latest manual value, add-asset and set-value forms; market assets read-only (R1) |
| `Components/TransactionForm.tsx` | Editors and owners only. Text decimal inputs, idempotency key per submit, local date sent as ISO UTC |
| `Components/TransactionList.tsx` | Source badges, struck-through reversed rows, Reverse with inline confirm, refusals shown on the row |
| `Components/MembersPanel.tsx` | Member list for everyone; add by email, change role, remove for owners |
| `lib/format.ts` | Display-only formatters that never convert money to a float |
| `types/portfolio.d.ts` | Typed contract for every prop |

### Tests
| File | Tests |
|---|---|
| `tests/Feature/ValuationServiceTest.php` | 9 |
| `tests/Feature/DeletePortfolioTest.php`, `CreatePrivateAssetTest.php` | 22 |
| `tests/Feature/Http/PortfolioControllerTest.php` | 16 (props shape, constant query count, 403, 404, error page) |
| `tests/Feature/Http/PortfolioHttpTest.php` | 53 (guests, non-members, viewers, editors, owners, validation, ledger protection) |
| `tests/Architecture/WritePathTest.php` | extended to `Asset`, `Portfolio` and `->delete()` writes, with no exceptions |

---

## Where the Code Differs From the Spec

1. **`summary()` also returns `total_pnl`.** The header needs total profit, and the spec forbids money arithmetic in TypeScript.
2. **The `assets` prop carries `price` and `price_as_of`.** The assets panel needs each private asset's latest value; loaded eagerly in one query.
3. **No `agent` route yet.** BUILD_GUIDE 9.1 lists it, but `ai_agent_tasks.md` Task 8 owns it.
4. **`/` redirects to the portfolios** (login for guests), and `/dashboard` forwards there, so Breeze's post-login redirect lands on the real page. The Breeze `Welcome` and `Dashboard` pages are removed.
5. **Account deletion was removed** (user decision). Users are referenced by portfolios and ledger rows with `restrictOnDelete`, so deleting an account with any data failed with a 500.
6. **Private asset names are unique per portfolio** (user decision).
7. **Dates from forms are sent as ISO UTC.** A `datetime-local` value has no timezone; read as UTC it would look like a future date east of UTC (Damascus is UTC+3).
8. **Two font weights (400, 500)** instead of three, per the accessibility checklist.

---

## Problems Found and Fixed Along the Way

1. **Breeze account deletion would 500** for any user who owned data (FK restrict). Removed.
2. **Timezone bug in date inputs**, described above. Fixed with `localInputToIso()`, shared by both forms, and pinned by a test.
3. **A refused reversal reports its error under `quantity`**, not `transaction`. The list reads the error from the request itself, so it shows on the right row and never on the form.
4. **Breeze's reset-password page had an unlabeled field** (label pointing at an input with no id). Fixed.
5. **Breeze form errors were not linked to their inputs**, status messages had no `role="status"`, and nav controls had no visible keyboard focus. All fixed.

---

## Known Gaps

- Transaction list limited to the latest 50, no pagination.
- No realtime updates; other users' changes appear on the next page load.
- No dark mode.
- Single currency: market assets are USD, so a portfolio in another currency can only hold private assets.
- Agent panel not built (a marked slot is reserved on the show page).
- Price sync not built; market prices are the seeded snapshots.
- The 375 px layout and the keyboard focus rings were checked by reading the code, not visually.
- No confirm before an owner demotes themselves (the server still prevents zero owners).

---

## Git Commits

1. `Prepare for HTTP and UI work`
2. `Add ValuationService`
3. `Add DeletePortfolio and CreatePrivateAsset actions`
4. `Remove account deletion`
5. `Make private asset names unique per portfolio`
6. `Add form requests, routes and shared props`
7. `Add PortfolioController`
8. `Add transaction, asset, valuation and member controllers`
9. `Add HTTP feature tests`
10. `Add TypeScript types and format helpers`
11. `Add portfolios index page`
12. `Add portfolio show page and holdings table`
13. `Add transactions tab`
14. `Add private assets and manual valuations UI`
15. `Add members tab`
16. `Polish UI states and accessibility`

---

## Next Steps (Not Part of This Task)

- AI agent (`ai_agent_tasks.md`): its prerequisites, `ValuationService::summary()` and the portfolio show page, now exist.
- Scheduled price sync job (completes R1).
- Optional: database triggers for the R5 query-builder gap (`actions_tasks.md` Task 11).

---

## Contact

For questions about this implementation, refer to:
- Spec: `docs/http_ui_tasks.md`
- Build Guide: `BUILD_GUIDE.md`
- Earlier layers: `DATABASE_DROPOFF.md`, `ACTIONS_DROPOFF.md`
