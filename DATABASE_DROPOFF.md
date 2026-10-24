# Database Layer Implementation - Dropoff

**Project:** Wealth Portfolio Tracker
**Date:** October 24, 2026
**Status:** Complete

---

## Overview

This document summarizes the completed database layer implementation for the Wealth Portfolio Tracker application. All tasks from `database_tasks.md` have been completed successfully.

---

## What Was Implemented

### 1. Domain Enums (5 files)
- **Role**: Owner, Editor, Viewer with `rank()` and `atLeast()` methods
- **TransactionType**: Buy, Sell, Deposit, Withdrawal with `isOutflow()` and `opposite()` methods
- **AssetType**: Stock, Crypto, RealEstate, Cash
- **PriceSource**: Market, Manual
- **TransactionSource**: User, Agent

**Location:** `app/Enums/`

### 2. Database Migrations (6 files)

#### Portfolios Table
- Fields: id, name, base_currency (USD default), created_by (FK to users), timestamps, softDeletes
- FK Policy: `restrictOnDelete` on created_by
- **Location:** `database/migrations/2026_10_24_121407_create_portfolios_table.php`

#### Portfolio Members Table
- Fields: id, portfolio_id (FK, cascade), user_id (FK, cascade), role, timestamps
- Constraints: Unique on (portfolio_id, user_id), index on user_id
- **Location:** `database/migrations/2026_10_24_121408_create_portfolio_members_table.php`

#### Assets Table
- Fields: id, portfolio_id (nullable, FK, restrict), type, symbol (nullable), external_id (nullable), name, currency (USD default), price_source, timestamps
- Constraints: Index on (portfolio_id, symbol)
- **Location:** `database/migrations/2026_10_24_121446_create_assets_table.php`

#### Transactions Table (Append-Only Ledger)
- Fields: id, portfolio_id (FK, restrict), asset_id (FK, restrict), type, quantity (decimal 24,8), price (decimal 24,8), fee (decimal 24,8), occurred_at, created_by (FK, restrict), source, reverses_id (nullable, unique, FK), idempotency_key (nullable, unique), note (nullable), timestamps
- Constraints: Unique on reverses_id, unique on idempotency_key, composite index on (portfolio_id, asset_id, occurred_at)
- **Location:** `database/migrations/2026_10_24_122549_create_transactions_table.php`

#### Price Snapshots Table
- Fields: id, asset_id (FK, cascade), price (decimal 24,8), currency (USD default), as_of, source, created_by (nullable, FK, nullOnDelete), timestamps
- Constraints: Unique on (asset_id, as_of, source)
- **Location:** `database/migrations/2026_10_24_122707_create_price_snapshots_table.php`

#### Agent Runs Table
- Fields: id, user_id (FK, restrict), portfolio_id (FK, restrict), run_id (36 chars), tool (64 chars), input (json), outcome (16 chars), error (nullable), timestamps
- Constraints: Index on (portfolio_id, created_at)
- **Location:** `database/migrations/2026_10_24_122708_create_agent_runs_table.php`

### 3. Eloquent Models (7 files)

#### Portfolio Model
- Uses SoftDeletes
- Relations: members(), transactions()
- Methods: roleFor(User), usableAssets() (global + private assets)
- **Location:** `app/Models/Portfolio.php`

#### PortfolioMember Model
- Casts: role to Role enum
- Relations: portfolio(), user()
- **Location:** `app/Models/PortfolioMember.php`

#### Asset Model
- Casts: type to AssetType, price_source to PriceSource
- Relations: portfolio(), snapshots(), latestSnapshot()
- Methods: isGlobal()
- **Location:** `app/Models/Asset.php`

#### Transaction Model
- Casts: type to TransactionType, source to TransactionSource, quantity/price/fee to decimal:8, occurred_at to datetime
- Guard: LogicException on update/delete (append-only enforcement)
- Relations: portfolio(), asset(), author(), reverses(), reversal()
- **Location:** `app/Models/Transaction.php`

#### PriceSnapshot Model
- Casts: price to decimal:8, as_of to datetime
- Relations: asset()
- **Location:** `app/Models/PriceSnapshot.php`

#### AgentRun Model
- Casts: input to array
- **Location:** `app/Models/AgentRun.php`

#### User Model (Modified)
- Added relations: memberships(), portfolios()
- **Location:** `app/Models/User.php`

### 4. Demo Seeder
- Users: alice (owner), bob (editor), carol (viewer) - all with password "password"
- Global Assets: BTC, ETH, AAPL with market price snapshots
- Portfolio: "Family wealth" with Alice as owner, Bob as editor, Carol as viewer
- Private Asset: "Damascus flat" with manual valuation
- Transactions: 4 ledger rows (BTC, ETH, AAPL, flat purchases)
- Second Portfolio: "Carol personal" for isolation tests
- **Location:** `database/seeders/DatabaseSeeder.php`

### 5. Schema Tests (4 tests)
- Membership uniqueness per user and portfolio
- Transaction reversal uniqueness (database-level constraint)
- Price snapshot uniqueness per asset, time, and source
- Money precision (8 decimal places preserved)
- **Location:** `tests/Feature/SchemaTest.php`

---

## Key Design Decisions

### DECIMAL for Money
- All monetary values use `decimal(24,8)` in migrations and `decimal:8` casts in models
- Avoids floating-point precision issues
- Handles very large quantities and very small prices

### Append-Only Ledger
- Transactions cannot be updated or deleted at the model level (LogicException)
- `reverses_id` is unique at the database level to prevent double reversals
- Corrections use reversal rows with opposite transaction types

### Nullable portfolio_id on Assets
- `null` = global market assets (BTC, ETH, AAPL)
- Non-null = private assets specific to a portfolio
- Trade-off: Cannot enforce unique global symbols at DB level (MySQL treats NULL as distinct)
- Enforced in code via `firstOrCreate` in seeder and future Actions

### Foreign Key Policies
- `restrictOnDelete`: Prevents orphaned records (portfolios, assets, transactions)
- `cascadeOnDelete`: Automatically cleans up dependent records (portfolio_members, price_snapshots)
- `nullOnDelete`: Allows soft delete when referenced record is deleted (price_snapshots.created_by)

### Test Database
- The suite runs on a separate MySQL database, `wealth_test`, configured in `phpunit.xml`
- Running tests does not affect demo data in the main `wealth` database
- Create it once: `CREATE DATABASE wealth_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
- There is no `.env.testing`, so `php artisan ... --env=testing` still targets `wealth`. Use Pest to touch the test DB

### Storage Engine
- The mysql connection forces `'engine' => 'InnoDB'` in `config/database.php`
- Reason: some local MySQL installs default to MyISAM, which silently ignores transactions, row locks and foreign keys

---

## Verification Commands

```bash
# Run all migrations
php artisan migrate

# Run migrations with fresh start and seed demo data
php artisan migrate:fresh --seed

# Check migration status
php artisan migrate:status

# View table structure
php artisan db:table transactions
php artisan db:table assets
php artisan db:table portfolio_members

# Run schema tests
./vendor/bin/pest tests/Feature/SchemaTest.php

# Verify demo data in tinker
php artisan tinker
>>> App\Models\Asset::whereNull('portfolio_id')->count(); // Should return 3
>>> App\Models\Transaction::count(); // Should return 4
>>> App\Models\Portfolio::first()->roleFor(App\Models\User::where('email','bob@example.com')->first()); // Should return Role::Editor
```

---

## Git Commits

1. `Add docs and local MySQL setup` - Initial setup with docs, docker-compose, MySQL configuration
2. `Add domain enums` - Five backed enums with business logic methods
3. `Add portfolios and portfolio_members tables` - First two tables with FKs and constraints
4. `Add assets table` - Assets with nullable portfolio_id for global/private distinction
5. `Add transactions ledger table` - Append-only ledger with decimal money and unique reverses_id
6. `Add price_snapshots and agent_runs tables` - Last two tables with unique constraints
7. `Add models with casts and relations` - Six models plus User modifications
8. `Add demo seeder` - Demo data matching spec exactly
9. `Add schema tests` - Four tests verifying database constraints
10. `Document test database setup in SchemaTest` - Documentation for test database configuration

---

## Fixes After Handoff (October 25, 2026)

Found while starting `actions_tasks.md`:

1. **`Portfolio::roleFor()` crashed for every member.** `->value('role')` already returns the `Role` enum because of the `PortfolioMember` cast, so the extra `Role::from()` threw a TypeError. Now returns `->first()?->role`. Commit `32cfca6`.
2. **All tables were MyISAM.** The local server's default engine is MyISAM, so `DB::transaction()`, `lockForUpdate()` and every foreign key policy above were silently not enforced. The mysql connection now forces InnoDB, and both databases were rebuilt. Commit `d79725c`.
3. **Tests moved from SQLite to MySQL (`wealth_test`)**, as `database_tasks.md` Task 8 originally asked. SQLite stores decimals as floats, so `SUM()` could return values like `1.0E-8` that `bcmath` rejects, and it ignores row locks. Commit `d79725c`.

---

## Next Steps (Not Part of This Task)

According to the spec, the following tasks are separate from the database layer:
- Policies (PortfolioPolicy)
- Actions (LogTransaction, ReverseTransaction, SetAssetPrice, member management)
- Services (PositionService, ValuationService)
- Controllers and Routes
- Agent integration
- Scheduled price sync job
- UI implementation

---

## Files Created/Modified Summary

### Created (21 files):
- 5 Enum files in `app/Enums/`
- 6 Model files in `app/Models/`
- 6 Migration files in `database/migrations/`
- 1 Test file in `tests/Feature/`
- 2 Documentation files in `docs/`
- 1 Docker compose file
- 1 Modified seeder file

### Modified (4 files):
- `app/Providers/AppServiceProvider.php` - Schema default string length
- `app/Models/User.php` - Added relations
- `database/seeders/DatabaseSeeder.php` - Demo data
- `database/migrations/0001_01_01_000002_create_jobs_table.php` - Index fix for MySQL

---

## Questions Answered

1. **Why DECIMAL(24,8) and strings instead of floats?**
   - Avoids floating-point precision errors with monetary values
   - Handles large quantities (24 digits total) and small units (8 decimal places)
   - String arithmetic via bcmath ensures exact calculations

2. **Why is the ledger append-only, and how do corrections work?**
   - Ledger rows are never updated or deleted to maintain audit trail
   - Corrections use `reverses_id` to create opposite transaction rows
   - Unique constraint on `reverses_id` prevents double reversals
   - Model-level LogicException provides second line of defense

3. **Why is portfolio_id nullable on assets, and what is the cost?**
   - Null = global market assets available to all portfolios
   - Non-null = private assets specific to one portfolio
   - Cost: Cannot enforce unique global symbols at DB level (MySQL NULL behavior)
   - Enforced in code via `firstOrCreate` and future Actions

4. **Why no MySQL ENUM columns?**
   - Not portable to PostgreSQL
   - Backed PHP enums provide type safety and business logic methods
   - Easier to add new enum values without schema changes

5. **Which invariants are enforced by the database and which by Actions?**
   - Database: Unique constraints (membership, reverses_id, snapshot key), FK referential integrity
   - Actions: Business rules (R1-R5), authorization, position checks, global symbol uniqueness
   - Split leverages both layers for defense in depth

6. **What would change for PostgreSQL?**
   - Config only (database.php connection settings)
   - Could add partial unique index for global symbols
   - Could add row-level security as hardening
   - No migration changes needed (schema builder is portable)

---

## Contact

For questions about this implementation, refer to:
- Spec: `docs/database_plan.md`
- Tasks: `docs/database_tasks.md`
- Build Guide: `BUILD_GUIDE.md`
