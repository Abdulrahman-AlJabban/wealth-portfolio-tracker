# Wealth Portfolio Tracker

Shared portfolios of stocks, crypto, real estate and cash, with Owner / Editor / Viewer roles, an append-only transaction ledger, scheduled market prices and an AI assistant that can answer questions and propose transactions.

Built for the Talent Syria technical assessment. The scope, the design and the main decisions are in [PLAN.html](PLAN.html) (open it in a browser).

## Stack

- Laravel 13, PHP 8.3
- Inertia v2, React, TypeScript, Tailwind
- MySQL 8.4 (InnoDB)
- Laravel AI SDK with Google Gemini
- CoinGecko for market prices
- Pest

## Features

- **Portfolios and roles.** Each portfolio has members with a role. Viewers can read, Editors can also log transactions and value assets, and Owners can also manage members and delete the portfolio.
- **Ledger.** Buys, sells, deposits and withdrawals are never edited or deleted. Mistakes are corrected with a reversing row. Holdings, cost (average cost) and profit (realized on sales, unrealized on holdings) are calculated from the ledger.
- **Assets.** Market assets (BTC, ETH, AAPL) are shared and priced by the sync. Private assets (real estate, cash) belong to one portfolio and are valued by hand.
- **Market prices.** `market:sync` runs every 5 minutes, and the portfolio page refreshes its prices every 30 seconds.
- **AI assistant.** It answers with live portfolio numbers, suggests rebalancing, and proposes transactions that the user must approve. It acts with the user's own permissions.

Every write goes through an Action class in `app/Actions` that authorizes the user itself, so the web UI and the assistant are held to the same rules.

## Requirements

- PHP 8.3+ with `bcmath` and `pdo_mysql`
- Composer
- Node 20.19+ or 22.12+ (required by Vite 8)
- MySQL 8: local, or Docker with the included `docker-compose.yml`

## Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Set the database in `.env` and create two databases: the app's, and `wealth_test` for the tests. The test database is set in `phpunit.xml` and uses the same host and credentials as the app.

**With Docker:**

```bash
docker compose up -d
docker compose exec mysql mysql -uroot -proot -e "CREATE DATABASE wealth_test"
```

```dotenv
DB_DATABASE=wealth
DB_USERNAME=root
DB_PASSWORD=root
```

**With a local MySQL:** create `wealth_portfolio` and `wealth_test`, and set `DB_USERNAME` and `DB_PASSWORD` to your MySQL login.

Then migrate and load the demo data:

```bash
php artisan migrate:fresh --seed
```

## Run

Run each command in its own terminal:

```bash
PHP_CLI_SERVER_WORKERS=4 php artisan serve   # app at http://127.0.0.1:8000
npm run dev                                  # front-end assets
php artisan schedule:work                    # price sync every 5 minutes
```

The assistant streams its replies, which keeps one connection open, so the server needs more than one worker. PHP's built-in server ignores `PHP_CLI_SERVER_WORKERS` on Windows; there, use WSL, Docker or Laravel Herd if the page stalls while the assistant is answering.

**Demo users** all have the password `password`:

| User | Role in "Family wealth" |
|---|---|
| alice@example.com | Owner |
| bob@example.com | Editor |
| carol@example.com | Viewer |

## Configuration

**Assistant.** Without a key, the assistant shows "not configured" and the rest of the app works.

```dotenv
GEMINI_API_KEY=            # https://aistudio.google.com/apikey
GEMINI_MODEL=gemini-3.1-flash-lite
AI_FAILOVER=gemini-fallback          # optional: try a second model when the first is overloaded (503)
GEMINI_FALLBACK_MODEL=gemini-2.5-flash
```

The model must support tool calling. To use another provider, set `AI_PROVIDER` to an entry in `config/ai.php` and set its key.

**Market prices.** `fake` moves prices randomly and needs no key.

```dotenv
MARKET_DRIVER=coingecko    # or: fake
COINGECKO_API_KEY=         # free Demo key: https://www.coingecko.com/en/api
```

To run one sync now, use `php artisan market:sync`.

Run `php artisan config:clear` after changing `.env`.

## Tests

```bash
php artisan test
```

The tests run on MySQL (`wealth_test`) and never call the network. The AI model and CoinGecko are faked.

## Known limitations

- **Ledger edits:** the append-only rule is enforced in the model and the Actions, not by database triggers, so a raw query could bypass it.
- **AAPL price:** it comes from Apple xStock, a token on CoinGecko that tracks the share price, not from an exchange quote.
- **Live updates:** values refresh by polling every 30 seconds. There is no push, presence or collaborative editing.
- **Accounting:**
  - one currency per portfolio;
  - cost is average cost;
  - no trading fees;
  - cash is not debited automatically when buying.
- **Assistant:** one provider has been tested (Gemini). Replies are plain text.

## Docs

- [PLAN.html](PLAN.html): scope, design and the main decisions
- [docs/decisions.md](docs/decisions.md): every decision, with what was rejected and what it costs

The task lists the AI coding agent worked through, one task at a time, in this order:

1. [docs/database_tasks.md](docs/database_tasks.md): schema, enums, models and seeder
2. [docs/actions_tasks.md](docs/actions_tasks.md): policy, Actions and the business rules
3. [docs/http_ui_tasks.md](docs/http_ui_tasks.md): routes, controllers and the React pages
4. [docs/ai_agent_tasks.md](docs/ai_agent_tasks.md): the AI assistant
