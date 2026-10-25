# Decision Register

Every significant decision in the Wealth Portfolio Tracker: what was decided, why, what was rejected, and what it costs. The short version, with the main discussions, is in [PLAN.html](../PLAN.html).

## Stack

| Decision | Why | Rejected | Cost |
|---|---|---|---|
| **Laravel + PHP** instead of the reference Next.js | My strongest stack, so the time went into the domain rules and the agent, not into learning tools. Auth, policies, validation, migrations, a scheduler and a test runner are built in. | Next.js with Prisma or ZenStack: I would assemble auth, jobs and access rules myself. Express: see the next row. | The backend is not TypeScript, so type safety across the boundary needs its own answer (see below). |
| **Inertia (no API tier)** instead of a JSON API with a separate SPA | One server, session auth with CSRF, no tokens and no CORS. Validation errors flow straight into React forms. There was nothing for a separate API server, Express included, to do. | An API with an SPA: twice the plumbing, for a public API no one consumes. Livewire or Blade: the brief and the team use React, and a streaming chat UI is simpler in React. | No reusable API for a mobile app. The assistant uses a plain `fetch`, because Inertia cannot stream. |
| **MySQL 8.4 (InnoDB)** over PostgreSQL | What I know best. `DECIMAL` is exact, and transactions, foreign keys and row locks behave the same. Schema builder only, so moving to PostgreSQL is a config change. | PostgreSQL: it has row-level security and partial unique indexes, which I gave up. SQLite: it turned decimals into floats in tests. | Authorization lives only in the application. The uniqueness of global symbols is checked in code, because MySQL treats NULLs as distinct in a unique index. |
| **Eloquent with Actions** instead of repositories or schema-level access rules | Eloquent already abstracts the database. Access rules belong where every entry point passes: the Actions. | Repositories: no gain over Eloquent. ZenStack-style rules in the schema: no Laravel equivalent, and they hide the rules from the code that runs them. | "Writes go through Actions" is a convention, guarded by an architecture test. |
| **Laravel AI SDK 1.3** | First-party. It brings tool calling, streaming, stored conversations, tool approval and test fakes in one package. | Prism PHP: I would have to build conversation storage and approval myself. Vercel AI SDK: TypeScript, so it would need a Node server. A hand-written loop: tool loop, retries and stream parsing are all mine to get right. | A young package. I read its source and found three surprises (see below). |
| **Gemini `gemini-3.1-flash-lite`** | Free Google AI Studio key. The SDK's default model, `gemini-3.8-flash`, was often overloaded (HTTP 503) during testing. The provider is one config value. When Google answers 503 "overloaded", the SDK fails over to a second model on the same key (`gemini-2.5-flash`), set by `AI_FAILOVER`. | Paid providers for a prototype. | Only Gemini was tested. Failover only helps before a reply starts streaming. |
| **Breeze starter kit** | Tested login, register and profile pages in React and TypeScript in minutes. | Writing auth by hand. Jetstream: teams and other features that overlap or aren't needed. | The default visual style. Account deletion removed (see below). |
| **Pest** | Laravel's default, readable, and it has `arch()` tests. | PHPUnit syntax: same engine, more boilerplate. | None worth noting. |

## Data and domain

| Decision | Why | Rejected | Cost |
|---|---|---|---|
| **Roles per portfolio** (`portfolio_members`) | One person can own the family portfolio and view a friend's, which is the household case. | Global user roles. A permissions package for three roles. | Every query carries a portfolio. |
| **Roles as a ladder** (`Role::atLeast()`) | The three roles nest: Owner ⊃ Editor ⊃ Viewer. | An ability map per role. | A sideways role, such as an auditor, would need that map. |
| **Enums: PHP enums, string columns** | A closed set in code. Adding a value needs no migration, and it works the same on PostgreSQL. | MySQL `ENUM` columns. | The database itself accepts any string; casts and validation guard it. |
| **Append-only ledger, positions calculated** | Full history and an audit trail. No stored balance that can drift. Corrections stay visible. | A mutable balances table. Editing rows. | Holdings are recalculated on every read. A positions cache is deferred. |
| **Corrections by reversal rows** (unique `reverses_id`) | How accounting works. The unique index stops a row being reversed twice, even under a double click. | Edit or delete with a history table. | Two rows instead of one edited row. |
| **Global vs private assets by a nullable `portfolio_id`** | One price feed serves every portfolio, and private assets stay isolated. | Separate tables. Copying BTC into every portfolio. | Global symbol uniqueness is checked in code. |
| **Price history table** (`price_snapshots`) | History, a visible "as of" time, and one shape for market and manual prices. | A `current_price` column. | One "latest snapshot" lookup, eager-loaded. |
| **Money as decimal strings**, `bcmath` at 8 places, `DECIMAL(24,8)` | Exact maths. Eight places cover 0.00000001 BTC. | Floats (`0.1 + 0.2 ≠ 0.3`). Integer minor units, which get awkward when crypto needs 8 places and stocks need 2 to 4. | Discipline: strings everywhere, and no `Money` type yet. |
| **R4 checked by replaying the ledger after the insert** | A sell dated last week can make last week's position negative even when today's total looks fine. | Comparing against today's total. | One extra read of that asset's rows per write. |
| **Average cost** for cost basis | The simplest correct method, and common in trackers. | FIFO or tax lots. | Tax figures would differ from FIFO. |
| **Profit split into realized and unrealized** | A sale moves profit from "on holdings" to "realized". Showing only unrealized profit made the total drop after a profitable sale. Realized = sale price − average cost at that moment, kept after the asset is fully sold. | Showing unrealized profit only. | One more number in the summary and the header. |
| **A reversal and the row it reverses are left out of valuation** | A correction should look as if the mistake never happened. Treated as an ordinary sale, reversing one of several buys would change the average cost and invent a realized profit. | Treating reversals as ordinary opposite rows. | None: the pair always cancels exactly. |
| **Soft-delete portfolios** | The ledger must survive (R5). Foreign keys restrict deletes. | Cascading deletes. | Deleted portfolios stay in the database. |
| **One base currency per portfolio** | FX conversion is a project of its own. | Multi-currency with rates. | Assets must match the portfolio's currency. |
| **No trading fees** | The brief does not mention them. They cost a column, validation and tests for no graded value. | Keeping the fee column. | Cost basis excludes fees. Adding them back is one column. |
| **Unit price entered by the user, pre-filled from the latest price** | The market price values the portfolio; the transaction price records what was actually paid. A trade may be backdated, and exchanges charge their own price. | Forcing the market price, which would make cost follow value and profit meaningless. | The user can type a wrong price; the pre-fill and the "as of" hint reduce that. |
| **Account self-deletion removed** | Users are referenced by ledger rows with restrict, so Breeze's delete would fail with a 500. | Anonymising the user. | No self-service account deletion. |
| **Cash is not debited on buys** | It needs a cash accounting model. | Automatic cash movements. | Cash holdings are manual. |

## Architecture and security

| Decision | Why | Rejected | Cost |
|---|---|---|---|
| **Actions for every write** | One place per rule, used by the web, the agent and the tests. Controllers stay thin. | Fat controllers (the agent can't reuse them). Rules in models (hidden in Active Record). Large service classes. A command bus (a framework for nine use cases). | More files and one level of indirection. |
| **Gate inside each Action**, with `Gate::forUser($user)` | The agent has no controller and no route. A check at the door would protect HTTP only, and every new entry point would need its own copy. The user is passed explicitly because the agent acts with no request. | Route `can:` middleware, Form Request `authorize()`, controller checks: all HTTP-only. Global query scopes only hide rows. | Each Action must remember the Gate, so each has tests where a Viewer and a non-member are refused. |
| **Validate twice: Form Request for shape, Action for truth** | The agent never passes through a Form Request. | Validation only in the Action, which loses Laravel's inline form errors. | Some checks are written twice. |
| **A read-only DTO** (`LogTransactionData`) | Typed, named fields. Keeps the Action independent of HTTP. | Passing the `Request` (the agent has none). Loose arrays (typos fail late). | One mapping method. |
| **Lock the portfolio row** on every write | Writers to one portfolio run one at a time, so R4 and the "last owner" check can't race. | Optimistic locking (version columns and retries). Table locks. | Writers to the same portfolio wait for each other, which is fine at this scale. |
| **Idempotency key**, looked up after the lock; same key with different details refused | Double submits return the first row. The lookup comes after the lock so two identical requests can't both miss it. A reused key with a different trade is a client bug and should fail loudly. | Looking up before the lock (a race). Silently returning the old row. | One unique column. |
| **Scoped route bindings** | `/portfolios/1/transactions/9` is a 404 if row 9 is in another portfolio. | Manual ownership checks in each controller. | None. |
| **Architecture test** for the write path | Fails the build if controllers or agent code write domain rows directly. | Relying on review. | A text scan: a guardrail, not a proof. |
| **No database triggers for R5** (deferred) | Time. Model guards, the lack of routes and the architecture test cover normal code. | MySQL triggers. | A raw query-builder update would bypass R5. |

## The AI assistant

| Decision | Why | Rejected | Cost |
|---|---|---|---|
| **Identity built on the server** (`AgentContext`); no user or portfolio id in any tool | The model cannot choose who it acts as. | Ids as tool arguments that are then checked. | None. |
| **Four tools: three reads, one write** | They cover the brief's examples: summaries, rebalancing (allocation), transaction logging. | Tools for reversing, valuing and members: more write surface for the model. | The assistant cannot reverse a trade. |
| **Tool list filtered by role, but not trusted** | A Viewer's model never tries a write. The real boundary stays in the Action. | Trusting the filter as the boundary. | None. |
| **One execution path** (`AssistantTools::execute`) that never throws and audits every call | One place for authorization, error mapping and audit. Denied attempts are recorded too. | Logic in each tool adapter. | None. |
| **Money arguments must be strings** | A JSON number loses precision and would crash the strictly typed DTO. It is refused with a clear message, and the model retries. | Accepting numbers. | The model occasionally needs a second attempt. |
| **No date argument on `log_transaction`** | Backdating, the riskiest case for R4, is not possible by chat. | A date argument. | Agent trades are dated "now". |
| **Human approval before every agent write**, 10-minute expiry, role checked again | No write happens without a click, even if the model is tricked. The SDK has no expiry, so we added one. | Immediate writes. | One extra endpoint and a UI card. |
| **Conversations stored on the server**, bound by `assistant_threads` | The client cannot forge history. The SDK's `continue($id)` does not check ownership, so we bind each conversation to a user and a portfolio and check before continuing. | History sent by the client. Trusting the SDK. | One extra table. |
| **The SDK's automatic conversation titles turned off** | It made a hidden extra model call per conversation for a title the app never shows. | Leaving it on. | Titles are the first 50 characters of the message. |
| **Our own small SSE format** (`AssistantStream`) | A few lines on each side with no extra npm package. One class serves chat and approvals. | The Vercel AI data-stream protocol (a client package). WebSockets (two-way traffic isn't needed). | Not an open standard. |
| **Every refusal before the first byte**; validator run by hand | Once a stream starts, the status is locked at 200. Laravel's `validate()` answers an event-stream request with a 302. | Checking inside the stream. | A slightly longer controller. |
| **Limits:** 20 messages a minute, 6 tool rounds | Caps cost and stops runaway loops. | A token budget (deferred). | No per-user cost tracking. |
| **Replies shown as plain text** | No HTML injection, and no Markdown dependency. | A Markdown renderer. | Markdown symbols show as raw characters. |
| **Prompt-injection posture** | Database text reaches the model only as JSON string values, the instructions call it data, and a write still needs approval. | Filtering text. | It cannot be perfect, which is why approval and the Action exist. |

## Prices and realtime

| Decision | Why | Rejected | Cost |
|---|---|---|---|
| **CoinGecko** | One provider for BTC, ETH and AAPL. Free Demo key. | freecryptoapi.com (crypto only). Finnhub (stocks only, so a second provider). | Crypto-first data. |
| **AAPL through Apple xStock** (`apple-xstock`) | The brief lists stocks as an asset class, and this keeps one provider. | Finnhub for the real quote. Removing AAPL. | A token that tracks Apple, trading 24/7, so it can differ from the Nasdaq price. |
| **Every 5 minutes** | The free key allows 10,000 calls a month. One batched call every 5 minutes is about 8,640. | Every minute (about 43,200). | Prices can be up to 5 minutes old. |
| **A plain class run by an Artisan command** on the scheduler, not a queued job | It is one small HTTP call. A queue adds a worker process for nothing. | A queued job with a worker. | Runs inline in the scheduler, guarded by `withoutOverlapping()`. |
| **Upsert keyed on the minute** | Re-runs and retries never duplicate rows. | Plain inserts. | Prices are kept per minute. |
| **Fail closed** | An HTTP error writes nothing. A missing or bad price is skipped, never stored as zero. | Writing what arrived. | One provider failure means one missed update. |
| **`PriceProvider` interface with a fake driver as default** | The app runs without a key, tests are deterministic, and a new provider is one class. | Calling CoinGecko directly. | One interface. |
| **Polling every 30 seconds** (Inertia `usePoll`, only the price props) | The data changes every 5 minutes, so a 30-second poll is already faster than the source. Every poll is a normal page request behind the existing login and `view` check. | WebSockets with Laravel Reverb: a server process, a queue for broadcasts, private-channel authorization, client packages, for no visible gain. | Requests even when nothing changed. Up to 30 seconds of delay. No base for presence. |
| **Presence and collaborative notes deferred** | They need push (Reverb) and new models; they're not the core of a tracker. | Building them shallowly. | No multiplayer features. |

## Type safety across PHP and TypeScript

PHP is not TypeScript, so the contract is enforced at each boundary:

1. **Form Requests** validate input shape.
2. **The DTO and PHP enums** carry typed values into the Actions.
3. **Hand-written TypeScript types** (`resources/js/types/portfolio.d.ts`) describe every page's props. Money is `string`, and `any` is not used.
4. **`assertInertia` tests** pin what the server actually sends, so the TypeScript types can't silently drift from the PHP.

Generating the types from PHP was considered and declined, because it adds a dependency for a handful of types.

## Testing and process

| Decision | Why | Rejected | Cost |
|---|---|---|---|
| **Tests on MySQL** (`wealth_test`), InnoDB forced | Tests run on the production engine. The local server defaulted to MyISAM, which silently ignores transactions and locks. | SQLite (decimals became floats). | A second database to create. |
| **No network in any test** (`Http::preventStrayRequests()`), SDK fakes for the model, `Http::fake()` for CoinGecko | Tests are free, fast and deterministic, and a test can never call a real provider. | Recorded live responses. | Live behaviour was checked by hand. |
| **Approval tests use the SDK's real resume path** | The SDK's fake deliberately skips approved tools, so a faked test would prove nothing. | Faking the resume. | More setup in those tests. |
| **Mutation checks** | Break the code on purpose and confirm a test fails. That is how we know the tests guard something. | Trusting coverage numbers. | Time. |
| **No browser tests** | Time. `assertInertia` covers the server side of every page. | Dusk or Playwright. | Layout and focus were checked by reading code. |
