# Implementation Report: Wealth Portfolio Tracker

**For the planning conversation.** You wrote `BUILD_GUIDE.md`, `database_plan.md` and the four task lists (`database_tasks.md`, `actions_tasks.md`, `http_ui_tasks.md`, `ai_agent_tasks.md`). This report picks up from there: what happened when Claude Code executed those plans in the repository, task by task, where the result differs from the plan and why, which decisions the user made along the way, what went wrong and how it was caught, and what is still open.

Read it together with the plan you already have. Plan references use your own numbering (for example "BUILD_GUIDE §8.3", "actions Task 4").

**Next step for this conversation:** plan the remaining submission work and write the video script (BUILD_GUIDE §16). Section 9 below lists what is open; section 10 gives the material for each video topic.

---

## 1. Status at a glance (25 October 2026, deadline day)

| Plan | Result |
|---|---|
| `database_tasks.md` (Tasks 0 to 9) | Done before this phase. Three bugs found afterwards and fixed (section 3). |
| `actions_tasks.md` (Tasks 0 to 12) | Done, except **Task 11 (DB triggers), skipped** by the user. |
| `http_ui_tasks.md` (Tasks 0 to 14) | Done. |
| `ai_agent_tasks.md` (Tasks 0 to 12) | Done, **including Task 11 (human approval)**. Live browser rehearsal still owed. |
| BUILD_GUIDE tiers | T0 partly (plan docs yes; `docs/decisions.md` and `docs/rules.md` never written; content lives in BUILD_GUIDE and the reports). T1, T2, T4 done. **T3 (price sync) and T5 (realtime) not built.** |
| Tests | **382 passing, 1,059 assertions**, on MySQL `wealth_test`. 127 of them are agent tests. |
| Repository | About 65 local commits on `master`. **No remote yet.** README is still Laravel's stock page plus one "Configure the agent" section. |

Reports written along the way (in the repo): `DATABASE_DROPOFF.md`, `ACTIONS_DROPOFF.md`, `HTTP_UI_DROPOFF.md`, `docs/ai-workflow.md` (one entry per agent task), `docs/demo.md` (five demo scripts), `docs/guide.html` (static visual guide).

---

## 2. How the execution worked

Claude Code (in VS Code) ran each task list exactly as your rules said: one task at a time, stop, report in the format **Changed / Why / Verified / Open questions**, wait for "next". The user answered open questions, often with "do what's best", which is why some decisions below were made by the agent with the user's approval.

Additions to your process that turned out to matter:
- **Laravel Boost** was installed (dev only) at the user's request. It rewrote `CLAUDE.md` and `AGENTS.md` and added skills (testing best practices, Laravel best practices, Inertia React). The agent followed its rules: `php artisan make:*` for new files, Pint after each PHP change, read the testing skill before writing tests.
- **Mutation checks:** for key protections the agent broke the code on purpose and confirmed a test failed, then restored it.
- **Read the installed SDK source instead of guessing** (your agent-list rule 3). This found three important SDK behaviours (section 6).
- **No live AI calls during the build:** the user asked to save Gemini tokens, so every agent test uses the SDK's fakes, and live checks were deferred to the end.

---

## 3. Database layer: fixes after your handoff

The database tasks were complete when this phase started. Three problems surfaced in the first tasks of the actions list:

1. **All tables were MyISAM.** The local MySQL server (not the docker-compose one; root, no password) defaults to MyISAM, which silently ignores transactions, row locks and foreign keys. Every "restrict" and every rollback in the plan was a no-op. Caught when a test left rows behind that a rollback should have removed. Fix: `'engine' => 'InnoDB'` in `config/database.php`, databases rebuilt.
2. **Tests had moved to SQLite** (contrary to database Task 8). SQLite stored the decimals as floats: `1 − 0.99999999` came back as `1.0E-8`, which `bcmath` rejects. Caught by a throwaway probe. Fix: tests run on MySQL `wealth_test`, as Task 8 asked.
3. **`Portfolio::roleFor()` crashed for every member:** `->value('role')` already returns the cast `Role` enum, so `Role::from()` threw. The schema tests never called it. Caught by the first helper test.

Also: there is no `.env.testing`, so `php artisan --env=testing` still targets the dev database (the agent wiped the seed once that way and reseeded).

---

## 4. Policy and Actions (`actions_tasks.md`)

Built as specified: `PortfolioPolicy`, `LogTransactionData`, `PositionService` (with your time-aware `assertNeverNegative`), `LogTransaction`, `ReverseTransaction`, `SetAssetPrice`, `CreatePortfolio`, the three member Actions, `RulesTest` (the exact R1 to R5 names plus the honest R5 known-gap test), and the write-path architecture test.

**Differences from the spec, with reasons:**

| Spec | Built | Why |
|---|---|---|
| Task 4: idempotency lookup *before* the portfolio lock | Lookup **after** the lock | Two concurrent same-key requests could both miss the lookup and the second would fail on the unique index with a 500. User: "do what's best". |
| Task 4: same key returns the existing row | Same key with **different trade details is rejected** | Silently returning a different, older transaction hides a client bug (Stripe-style behaviour). |
| Task 6: `SetAssetPrice` creates a snapshot | **Rejects future `as_of`**, uses `updateOrCreate` | The newest `as_of` is the current price, so a typo'd future date would pin the value; two valuations in the same second would hit the unique key. |
| Task 8: lock owner rows | Decide "is this the last owner?" from the **locked rows**, not the passed `$member` | A stale `$member` object could carry an old role. A mutation check proved the test catches it. |
| Task 8 | `AddMember` also **locks the portfolio row** | Two simultaneous adds of the same user would otherwise race to the unique index. |
| Task 11: MySQL triggers for R5 | **Skipped** (user decision) | Time. The known-gap test stays. |
| — | `LogTransaction::DECIMAL_PATTERN` made public and reused by `SetAssetPrice` and the Form Requests | One definition of a valid amount. |
| — | Frontend build fixed (`@vitejs/plugin-react` ^6 and `@types/node` ^22 for Vite 8; removed a missing `./bootstrap` import) | Breeze page tests failed on a missing Vite manifest. |

---

## 5. HTTP layer and UI (`http_ui_tasks.md`)

Built as specified: `ValuationService::summary()`, `DeletePortfolio`, `CreatePrivateAsset`, Form Requests, routes, five controllers, HTTP feature tests (every write endpoint tested against guests, non-members and viewers), TypeScript types and format helpers, and the Index and Show pages with Holdings, Transactions, Assets and Members.

**User decisions during this phase:**
- **Account deletion removed.** Breeze's "delete account" would fail with a 500 for any user with data, because portfolios and ledger rows reference users with `restrictOnDelete`. The route, controller method, form and Breeze tests were removed; a test now proves `DELETE /profile` returns 405. This also let the architecture test ban `->delete()` in `app/Http` with no exceptions.
- **Private asset names unique per portfolio** (unique index on `(portfolio_id, name)`, which leaves global assets alone because NULLs are distinct, plus a friendly check in the Action).

**Differences from the spec, with reasons:**

| Spec | Built | Why |
|---|---|---|
| Task 1: `summary()` returns totals of value and cost | Also returns **`total_pnl`** | The header shows profit, and rule 4 forbids money arithmetic in TypeScript. |
| Task 4: `assets` prop `{id, symbol, name, type, is_private}` | Adds **`price`, `price_as_of`** (eager loaded) | Task 11 needs each private asset's latest manual value. |
| Task 3: routes exactly as BUILD_GUIDE §9.1 | Agent route left to the agent list | This list's scope excludes the agent. |
| — | `/` redirects to portfolios; `/dashboard` (Breeze's post-login target) forwards there; Breeze `Welcome` and `Dashboard` pages removed | Login landed on an empty Breeze page. |
| Task 12: add member by email | `AddMember` signature changed to `string $email`, case-insensitive | As the task allowed. |
| Task 13 checklist | Three weights reduced to two; focus rings added to Breeze nav and dropdown; Breeze form errors linked with `aria-describedby`; a Breeze reset-password label pointing at an input with no id fixed; `role="status"` on Breeze messages; arrow-key tab navigation; readable Inertia 403/404 error page (status kept, JSON untouched) | Checklist items that failed. |
| Task 10: optional date field | `datetime-local` value sent as **ISO UTC** | Read as UTC, a local time in Damascus (UTC+3) looked like a future date and was rejected. |

**Demo scripts (Task 14) were run against the live app with `curl`** as each seeded user: Alice buy and reverse (BTC 0.5 → 0.6 → 0.5, second reverse refused), Bob logs while Carol gets 403 on five direct writes, Alice adds Dave by email and is refused demoting herself as last owner. All passed.

---

## 6. AI agent (`ai_agent_tasks.md`)

**Provider:** the user chose **Google AI Studio (Gemini)**. The SDK installed as **`laravel/ai` v1.3.0**, not pre-1.0 as the task list assumed; the installed API was used throughout.

**Built:** `config/agent.php`, `AssistantStatus`, `AgentContext`, `ValuationService::allocation()`, `AssistantTools` (the single guarded execution path), four thin SDK tool adapters, `PortfolioAssistant`, the `log_transaction` write tool, server-side conversations, the streaming endpoint, `AgentPanel`, the 15-name checklist test file, and **human approval** (Task 11).

### What reading the SDK source revealed

1. **`continue($conversationId)` does not check ownership.** The SDK has a `conversationBelongsTo()` method but never calls it, and its tables store the user but not the portfolio. So Task 7 used **server-side mode with the `assistant_threads` table** (not the fallback). Deleting the portfolio condition made the "other portfolio" test fail, proving the check is the boundary.
2. **The SDK makes a hidden model call to title every new conversation.** That spends tokens for nothing the app shows. Turned off (`ai.conversations.generate_title = false`); the title is the first 50 characters of the message.
3. **The SDK's fake deliberately skips approved tools on resume** (`ResumesToolApprovals`). The first "approval writes one row" test failed although the code was right; printing the stored turn showed why. Approval tests now drop the fake and use the SDK's **real** resume path: the SDK runs our tool (a real write through `LogTransaction`) and then its follow-up model call is blocked by the suite-wide `Http::preventStrayRequests()`. No network, no tokens.
4. Options can be **methods**, not only attributes, so `maxSteps()` reads `config('agent.max_tool_rounds')`.
5. Tool names default to the class name; Gemini rejects that for anonymous classes (HTTP 400), so **every tool defines `name()`**.

### Differences from the task list, with reasons

| Task list | Built | Why |
|---|---|---|
| Task 1: one live prompt and one tool call | Done after the user added a key. Default model `gemini-3.8-flash` failed (503 "high demand", 60-second hangs); **`gemini-3.1-flash-lite`** works, set in `.env` | Provider overload, not a code problem. |
| Task 6: money parameters are strings | Non-string money (a JSON number) is **refused with a clear message** | Under `strict_types` it would have crashed with a `TypeError`. |
| Task 6 | Agent notes **capped at 200 characters** | The 500-character limit lives only in the web Form Request, which the agent bypasses. |
| Task 4 | Tool name truncated to 64 characters before auditing; audit write failures reported, not thrown | A long invented tool name would make the audit insert fail; "never throws" must hold. |
| Task 8: build non-streaming first, then streaming | Streamed from the start | The SDK's stream works with its fake, so tests read the real event sequence. |
| Task 8: invalid payload returns 422 | Validator run by hand, JSON 422 | Laravel's `validate()` returns a 302 redirect for an `Accept: text/event-stream` request. |
| Task 9 | Panel mounted in the slot reserved on the real Show page | `Show.tsx` existed by then. |
| Task 10: stray-request guard | `Http::preventStrayRequests()` for **every** feature test in `tests/TestCase.php` | So no test anywhere can reach a provider; all 357 existing tests still passed with it on. |
| Task 11 | Built (the list's recommended option). Our own **10-minute expiry** (the SDK has none). SSE code moved into a shared `AssistantStream` for chat and approvals | Expiry and identical streaming for both endpoints. |
| Task 12, demo script 3 ("show the denied `agent_runs` row") | Rewritten: the panel shows the model declining (a Viewer is never offered `log_transaction`, so no row is written), then a Tinker command proves the **Action** refuses Carol and writes a `denied` row | The script as written cannot happen from the panel. |
| Task 0 references `docs/HANDOFF.md` | Did not exist; BUILD_GUIDE §11 used instead | |

**Open choices still standing:** `list_transactions` returns `symbol: null` for private assets (spec fields only; adding the asset name was suggested and not answered); replies render as plain text (no Markdown library allowed).

### The final approval flow

1. "Log a buy of 0.1 BTC at 65000" → the model calls `log_transaction` → the SDK **pauses**; nothing is written.
2. The stream sends `{"type":"approval","id":…,"tool":"log_transaction","arguments":{…}}`; the panel shows a card (portfolio, asset, type, quantity, unit price) with Approve / Reject; chat input is locked.
3. `POST portfolios/{portfolio}/agent/approvals` checks before streaming: user can still `write` (403), conversation belongs to this user and portfolio (404), request still pending and under 10 minutes (422). Approving twice fails.
4. Approve → the SDK resumes and runs the tool as that user through `LogTransaction` (role checked again). The row has `source = agent` and an Agent badge.

---

## 7. Where the finished system differs from BUILD_GUIDE

| BUILD_GUIDE | Finished system |
|---|---|
| §8.3 R4 compares against today's position | Time-aware replay after the insert (your actions list already changed this) |
| §11 `AgentTools` built with Prism, idempotency `sha256(runId+tool+args)` | Laravel AI SDK; `AssistantTools::execute()` as the single path; key `runId|callCount` so identical legitimate trades both log |
| §11.3 history sent by the client and filtered | Server-side conversations scoped by `assistant_threads` |
| §11 agent writes immediately | Human approval before any agent write |
| §11 `AGENT_PROVIDER=anthropic` | Gemini via Google AI Studio |
| §13 seeder | Unchanged numbers: value 132,600.00, cost 124,100.00, profit +8,500.00 |
| §6 Portfolio deletion cascades | Soft delete (from `database_plan.md` §2.4) |
| §9.1 routes | Same, plus `agent.approvals`; no profile deletion |
| §10 price sync (T3) | Not built |
| §15 `docs/decisions.md`, `docs/rules.md` | Not written; covered by BUILD_GUIDE and the reports |

New components the plan did not name: `AssistantStatus`, `AssistantConversations`, `AssistantStream`, `AssistantThread` (model and table), `AgentApprovalController`, `CreatePrivateAsset`, `DeletePortfolio`, `RoleBadge`, `AssetsPanel`, the `Error` page.

---

## 8. "The AI was wrong and it was caught" (for the agentic-execution part of the video)

| What went wrong | Caught by | Fix |
|---|---|---|
| MyISAM tables silently ignored transactions, locks and FKs | A test left rows a rollback should have removed | Force InnoDB |
| SQLite test DB turned decimals into floats | A throwaway probe test | MySQL `wealth_test` |
| `roleFor()` crashed for every member | First helper test | Return the cast enum |
| Idempotency lookup before the lock (a race in the spec) | Reviewing the spec's order | Lookup after the lock |
| Local time read as UTC looked like the future | Building the form | ISO UTC timestamps |
| Account deletion would 500 | Reviewing FK policies | Feature removed |
| SDK `continue()` trusts any conversation id | Reading the SDK source | `assistant_threads` |
| Hidden title-generation model call | Reading the SDK source | Turned off |
| Tool without `name()` rejected by Gemini (400) | Live check, error body printed with the key redacted | `name()` on every tool |
| Default Gemini model overloaded | Live check | `gemini-3.1-flash-lite` |
| `validate()` gives 302 for an event stream | Writing the endpoint | Manual validator |
| SDK fake skips approved tools | A correct test failing; stored turn printed | Real resume path in tests |
| Money as a JSON number → `TypeError` | Reviewing model behaviour | Clear refusal |
| Empty `GEMINI_MODEL=` → empty model name | Config review | `?: null` |
| Weight truncation summed to 99.98 | Allocation test | Round half-up |
| Test helper consumed one-time flash messages (looked like an app bug) | Comparing with a cached-version request | Helper fixed; app was correct |

Mutation checks that proved tests catch real mistakes: eager loading removed (query count 15 → 27, test failed); portfolio scope removed from conversations; owner check reading the stale model; weight rounding switched to truncation; the Gate check moved inside the stream (a non-member got 200 instead of 403). Planted forbidden writes made the architecture test fail.

---

## 9. What is still open

**Submission:**
- Push to GitHub (no remote exists).
- Replace the stock README with setup instructions (the PDF requires them). Also: `.env.example` says database `wealth_portfolio` with user root, `docker-compose.yml` says `wealth`/`wealth`/`secret`, and the working machine uses `wealth`; `wealth_test` must be created by hand.
- Rotate the Gemini key (it was pasted into a chat).
- One live browser run of `docs/demo.md` scripts 1 to 3: streaming, Approve and the Viewer refusal have only run under tests.
- Record the 10 to 15 minute video; reply to the email with both links.

**Known gaps to state in the video:** no price sync (prices never change) and no realtime; the R5 query-builder gap; single currency; average cost only, sell fees not in profit, no cash debit; no concurrency test; transaction list capped at 50; assistant has no token budget (only 20 messages a minute), no offline prompt evaluations, one provider tested, a page refresh starts a new chat, plain-text replies, up to 100 messages replayed per conversation. The visual design is a restrained Breeze look (gray and indigo); the 375 px layout and focus rings were checked by reading code only.

**Present but not asked for by the PDF** (mention briefly or not at all): human approval (worth leading with), Laravel Boost tooling in the repo, the many written reports (the PDF says no written report is required), and hardening such as idempotency keys, soft deletes, unique private names, the architecture test and the accessibility pass.

---

## 10. Material for the video script (BUILD_GUIDE §16 topics)

| Topic | What to say | What to show |
|---|---|---|
| Scope and prioritization | Built modeling, the agent and UX in depth; deferred async and realtime and why; tiered plan | BUILD_GUIDE §0 tier table |
| Permissions and security | Actions authorize themselves; R1 to R5 each enforced once and tested by name; non-members 403 everywhere; scoped bindings | `app/Actions/LogTransaction.php`, `tests/Feature/RulesTest.php`, Carol's 403 |
| Agent architecture | Laravel AI SDK (official, tool calling, streaming, conversations, approvals, fakes) vs Vercel AI SDK / Prism / raw loop; four tools; adapters → `AssistantTools::execute()` (`guarded()`, audit row, never throws); own SSE format; server-side history; human approval | `app/Agent/AssistantTools.php`, `PortfolioAssistant.php`, demo 1 and 2 |
| Agent permissions | Tool list filtered by role but not the boundary; the Action refuses a Viewer even when the tool is called directly; approval re-checks the role; `agent_runs` audit; prompt injection stays data | Demo 3 (panel + Tinker), demo 4 |
| Stack trade-offs | Laravel + Inertia, no Express and no API tier; MySQL DECIMAL vs Postgres RLS; Actions instead of policy-in-schema; decimal strings; the type-safety answer (Form Requests + DTOs + enums + hand-written TS types + `assertInertia` tests; code generation considered, declined) | BUILD_GUIDE §15.2 |
| Agentic execution | Plan → task lists → one task, report, review, "next"; verification and mutation checks; reading SDK source; the table in section 8 | `docs/ai_agent_tasks.md`, `docs/ai-workflow.md` |
| Tests and next steps | 382 tests; network blocked in every test; next: price sync, realtime, R5 triggers, README and type generation | `./vendor/bin/pest` |

Numbers worth saying: 382 tests; 127 agent tests; 15 required agent test names; 5 rules; 4 tools; seeded value 132,600.00 with profit +8,500.00; smallest unit 0.00000001; approval expiry 10 minutes; throttle 20 messages a minute.
