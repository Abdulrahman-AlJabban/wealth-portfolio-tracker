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
