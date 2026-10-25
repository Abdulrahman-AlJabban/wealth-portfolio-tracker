# AI Workflow Log

A running log of how AI coding tools were used to build this project: what each was asked to do, what was reviewed, and what was changed or rejected. One short entry per task.

Earlier phases are summarized in `DATABASE_DROPOFF.md`, `ACTIONS_DROPOFF.md` and `HTTP_UI_DROPOFF.md`. The most useful examples from those phases of the AI being wrong and being caught:

- **Every table was MyISAM.** The database layer was built and "passed" while transactions, row locks and foreign keys were silently ignored by the local server. Caught when a test left rows behind that a rollback should have removed.
- **SQLite as the test database.** It turned decimal sums into floats (`1.0E-8`), which `bcmath` rejects. Caught with a throwaway probe test before relying on it.
- **`Portfolio::roleFor()` crashed for every member.** It called `Role::from()` on a value the model had already cast to `Role`. Caught by the first test that used the helper.
- **Timezone bug in date inputs.** A `datetime-local` value read as UTC looked like a future date from Damascus (UTC+3). Caught while writing the transaction form; fixed by sending ISO UTC.

---

## AI agent (`docs/ai_agent_tasks.md`)

### Task 0: Prepare and check prerequisites

- **Asked:** Claude Code to run the prerequisite checks and set up this log.
- **Reviewed:** PHP 8.3.11 (SDK needs 8.3+), Laravel 13.38, `migrate:fresh --seed`, Pest (257 passing), `tsc`. `ValuationService::summary()` exists. `Portfolios/Show.tsx` exists, so the panel mounts on the real page.
- **Changed or rejected:** nothing. Noted that `docs/HANDOFF.md`, cited by the task list for the design, does not exist; `BUILD_GUIDE.md` section 11 is used instead.

### Task 1: Install the Laravel AI SDK and make one model call

- **Asked:** Claude Code to install `laravel/ai`, configure one provider (Google AI Studio, Gemini), and report the installed API instead of guessing it.
- **Reviewed:** the installed source (v1.3.0), not memory: the agent and tool contracts, `Promptable`, `TextGenerationOptions`, the streaming events, `RemembersConversations`, the approval classes and `FakeTextGateway`. A throwaway script ran a scripted tool call through the SDK's fake to confirm the tool loop and the stream event order.
- **Changed or rejected:**
  - The task list described the SDK as pre-1.0. The installed version is **1.3.0**; the installed API was used.
  - The provider-wide `vendor:publish` also copied four `stubs/*.stub` files; deleted, they are not needed.
  - Caught before it shipped: `env('GEMINI_MODEL')` returns `''` for an empty value, which would have sent an empty model name. Config uses `?: null`.
  - Security finding: the SDK's `continue($conversationId)` does **not** check who owns the conversation (nothing calls `conversationBelongsTo()`). Task 7 must check ownership and portfolio itself.
  - Live checks skipped: no `GEMINI_API_KEY` yet. The throwaway live-check script is ready to run once a key is added.

### Task 2: Assistant config and "not configured" state

- **Asked:** Claude Code to add `config/agent.php` and `AssistantStatus::isConfigured()`.
- **Reviewed:** that the check works for any provider in `config/ai.php` (all keep their credential under `key`), never reads the key out, and that tests set the key themselves so they do not depend on the real `.env`.
- **Changed or rejected:** the check is "enabled and the configured provider's `key` is non-empty". Stated limitation: providers that authenticate without a key (Ollama, Bedrock with default AWS credentials) would show as not configured. Only Gemini is used here.

### Task 1 follow-up: live checks with a real key

- **Asked:** Claude Code to run the two live checks once a Gemini key was added to `.env`.
- **Reviewed:** the failures by printing each wrapped exception with the key redacted, instead of guessing.
  - `gemini-3.8-flash` (the SDK default) failed intermittently: Google returned **503 "high demand"** or hung for 60 seconds. A provider problem, not the key or the code.
  - `gemini-3.1-flash-lite` answered in about 5 seconds.
- **Changed or rejected:**
  - The first tool check returned **400**. Cause: the test tool was an anonymous PHP class without `name()`, so the SDK sent a class name like `class@anonymous...` as the function name, which Google rejects. With `name()` returning `get_time`, **tool calling works**. Rule for Task 5: every tool class defines `name()`.
  - Local `.env` uses `GEMINI_MODEL=gemini-3.1-flash-lite`. The committed config still defaults to the SDK's model when the variable is empty.
