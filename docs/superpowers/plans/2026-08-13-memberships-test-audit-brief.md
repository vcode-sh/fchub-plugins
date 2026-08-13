# FCHub Memberships — Test Suite Audit Brief

> Hand this file to the agent team as their complete instruction set. It is
> self-contained: everything needed to start is below.

## Mission

Make the `fchub-memberships` test suite earn its runtime. Every test that
survives must be able to fail when the code it covers breaks. Every test that
cannot is either rewritten so it can, or deleted with evidence.

You are **Vibe Code**. Write everything — code, comments, test names, commit
messages you prepare but never run, reports — in English. Never mention AI
assistants, Claude, Codex, Anthropic or OpenAI in any artifact.

**Never run `git commit`, `git push`, `git tag`, or any publish/release command.**
The project owner owns Git history. You may stage nothing and commit nothing.

## Scope

- **In scope:** `plugins/fchub-memberships/` only — `tests/Unit/**`,
  `tests/admin/**`, `tests/admin-smoke/**`, `tests/stubs/**`, and any source
  file you must touch to make a real defect visible. At the time of writing:
  233 PHP test files, 50 Vitest files, 11 Playwright specs. Re-count before you
  start; another agent may have added more.
- **Out of scope:** every other plugin, `fluentcart-mcp/`, `web-docs/`,
  `fchub-stream` (discontinued — do not touch), and the sibling
  `fchub-playground` checkout except for read-only verification.
- **Concurrency hazard:** another agent may be working in this repository right
  now. Before editing, run `git status --short` and treat any file already
  modified that is not on your assigned list as foreign. Do not fix, revert, or
  "clean up" foreign changes. If a suite is red in a file you were not assigned,
  report it and move on.

## The lesson this audit exists to apply

A suite of ~1,990 PHP tests and ~400 JS tests was green while three admin
workflows were broken in production: extending a membership, bulk-extending,
and granting with an expiry date all failed with *"Invalid parameter:
expires_at"*. Every date picker emits `YYYY-MM-DD`; the REST validator has only
ever accepted `Y-m-d H:i:s`. Nothing caught it.

These are the concrete failure modes found in this repository. Hunt for each of
them by name:

1. **Asserting the bug.** A test asserted
   `expect(api.extend).toHaveBeenCalledWith({ expires_at: '2027-01-01' })` — the
   exact value the server rejects — and passed. A test that pins current output
   without asking whether that output is *correct* converts a defect into a
   requirement.
2. **Assertions that match the wrong part of the subject.** A filter test used
   `assertStringContainsString('g.starts_at IS NULL OR ...', $query)` against the
   whole SQL string. The same fragment also appears in the SELECT list, so the
   test passed with the entire `WHERE` clause deleted. Assert against the slice
   you are actually testing.
3. **Unasserted bound parameters.** A batch audit read asserted the id list but
   never the `entity_type` binding, so a query returning plan records as a
   member's history would have passed.
4. **Load-bearing code with zero coverage.** `GrantRepository::getByUserId()`
   backs the profile, the history, the timeline, bulk export, and the sibling
   lookup that pauses a whole membership — and had no test at all.
5. **Interaction tests that stop before the interaction.** A smoke test clicked
   a preset and asserted the submit button *became enabled*. It never submitted,
   so it never saw the payload.
6. **Harness blindness.** `smoke/main.js` replaces `window.fetch`, so Playwright
   request routing sees nothing. It already records every mutation body in
   `window.__fchubSmokeRequests` — nobody used it. Look for capabilities the
   harness has that no test exercises.
7. **Tests mirroring the implementation.** Asserting that a query contains
   `MIN(g.status)` tests that the code is what it is. Assert the behaviour the
   expression is *for*.
8. **Fixtures encoding fiction.** Fixtures supplied `revoked_at` and `paused_at`
   as grant columns. Neither exists; `revoked_at` is never written anywhere. The
   fixtures made dead production branches look tested.

## Method: mutation first, opinion never

Counting assertions does not work here — PHPUnit's own risky-test detection is
on by default and reports zero assertionless tests. The only objective question
is: **if I break this behaviour, does the suite go red?**

Build a mutation harness in your scratchpad (not in the repository). It takes a
list of `(label, file, find, replace)` tuples, and for each one: apply the
single replacement, run the relevant suite, record caught/survived, restore the
file from the in-memory original in a `finally` block. Never leave a mutation on
disk — verify with `git diff --stat` after every batch.

A mutation must express a **plausible defect**, not gibberish. Good: swapping a
status precedence, dropping a guard clause, loosening a validator, removing a
`WHERE` predicate, returning a link for a record that was not found. Bad:
deleting a whole method, introducing a syntax error.

Seed list — these behaviours are load-bearing and must be defended. Extend it;
this is a floor, not a ceiling:

| Area | Behaviour a mutation must break |
| --- | --- |
| `MembershipGrouper` | status precedence active > scheduled > paused > revoked > expired; plan-less grants stay separate; a lifetime row beats a dated one |
| `GrantStatusService` | pause/resume reach every row of a membership; terminal rows are not dragged in |
| `GrantSourceResolver` | no link for an unresolved record; a cancelled subscription advertises no renewal |
| `MemberTimelineComposer` | dedup is per membership; no revocation date is invented |
| `GrantRepository` | `accessStatusCase` scheduled branch; each status filter's `WHERE`; `getByUserId` filters nothing unless asked |
| `AuditLogRepository` | entity type binding; the read is bounded |
| `MembershipRestArguments` | expiry accepts only `Y-m-d H:i:s` |
| `AccessEvaluator` | each reason code is distinguishable (paused ≠ no grant ≠ drip locked) |
| `StatusTransitionValidator` | illegal transitions stay illegal |
| `EntitlementService` / `ProviderOperationWorker` | provider writes fail closed rather than reporting success |
| `IdempotentMutation` | a replayed key returns the stored response; a changed payload conflicts |
| Client (`resources/admin`) | expiry conversion; verdict treats scheduled as access; presets measured from current expiry |

Target: **every seeded mutation caught**. Report any you deliberately leave
uncaught with the reason.

## Phases

Work in this order. Each phase has a verification you must run and report.

### Phase 1 — Inventory (no edits)

Produce `test-audit/inventory.md` in your scratchpad listing every test file
with: subject under test, test count, and a first-pass classification —
`defends behaviour` / `mirrors implementation` / `unclear` / `subject gone`.
Cross-check "subject gone" against the source tree; a test whose subject no
longer exists is a deletion candidate, not a mystery.

**Verify:** file counts match `find tests -name '*.php' | wc -l` and
`ls tests/admin/*.test.js | wc -l`.

### Phase 2 — Baseline mutation score

Run the harness over the seed list plus everything Phase 1 flagged as
load-bearing. Record the score and the survivor list.

**Verify:** `git diff --stat` shows no source change after the run.

### Phase 3 — Close the survivors

For each survivor, decide: is the behaviour worth defending? If yes, write the
test that catches it. If no, say why in the report. Prefer strengthening an
existing test over adding a new one.

**Verify:** re-run the harness; every addressed survivor is now caught. Then
prove the new test is real by reintroducing the defect once and observing red.

### Phase 4 — Remove slop

Delete or rewrite tests matching the failure modes above. **Before deleting any
test, record three facts in the report** — the same evidence rule AGENTS.md
demands before removing capability:

1. **What it covered** — the behaviour and its owning module.
2. **Who depended on it** — is this the only test touching that path?
3. **Where that coverage now lives** — the test that replaces it, or an explicit
   statement that the behaviour is untestable/nonexistent and why nobody needed
   it.

If you cannot produce all three, you do not understand the test well enough to
delete it. Read the code it covers first.

Delete outright, with the three facts, when: the subject no longer exists; the
test asserts the framework rather than this plugin; it duplicates another test's
assertions without adding a boundary; or it is a smoke test that renders a page
and asserts nothing a compile error would not already catch.

**Never** delete a test merely because it fails, because it is slow, or because
deleting it raises the mutation score.

### Phase 5 — Fill the boundary gaps

AGENTS.md requires coverage of boundaries, error paths, and the cases most
likely to break. For each membership workflow — grant, revoke, pause, resume,
extend, bulk operations, import, drip, provider sync, webhooks — confirm there
is a test for: the invalid input, the partial failure, the idempotent replay,
and the concurrent/duplicate case. Add what is missing. Do not pad happy paths.

**Verify:** new tests fail before the behaviour exists and pass after.

### Phase 6 — Final report and full verification

```bash
cd plugins/fchub-memberships && ./vendor/bin/phpunit
cd plugins/fchub-memberships && npm run test
cd plugins/fchub-memberships && npm run test:smoke
cd plugins/fchub-memberships && npm run build
```

Report: baseline vs final mutation score, tests deleted (with the three facts
each), tests rewritten, tests added, defects found in production code, and any
survivor you consciously left.

## Partitioning across agents

Assign by directory so no two agents edit the same file. Suggested split:

| Agent | Owns |
| --- | --- |
| A | `tests/Unit/Domain/Grant/**`, `tests/Unit/Domain/Member/**`, `tests/Unit/Domain/Lifecycle/**` |
| B | `tests/Unit/Storage/**` |
| C | `tests/Unit/Http/**` (controllers, REST arguments, idempotency, permissions) |
| D | `tests/Unit/Integration/**`, `tests/Unit/FluentCRM/**`, `tests/Unit/Domain/Reconciliation/**` |
| E | `tests/admin/**` (Vitest) and `tests/admin-smoke/**` (Playwright) + `smoke/main.js` |

Shared files — `tests/stubs/test-bootstrap.php`, `tests/Unit/PluginTestCase.php`
— are owned by **one** nominated agent. Anyone needing a stub change requests it
from that agent rather than editing directly; a second writer will silently
clobber the first.

Each agent appends findings to a single shared ledger with an owner column. Do
not rewrite another agent's entries.

## Repository facts you need

**Commands** (run from `plugins/fchub-memberships/`):

```bash
composer install && ./vendor/bin/phpunit
npm run test
npm run test:smoke
npm run build
./vendor/bin/phpunit --filter SomeTestClass
npx vitest run tests/admin/some.test.js
npx playwright test tests/admin-smoke/some.spec.js
```

**PHP harness.** `tests/stubs/test-bootstrap.php` defines WordPress stubs.
`PluginTestCase` resets `$GLOBALS['_fchub_test_*']` per test. Database access
goes through `CustomTableDatabase`, and every call is recorded in
`$GLOBALS['_fchub_test_queries']` as `[method, sql, ...]`. Override results with
`$GLOBALS['_fchub_test_wpdb_overrides']['get_results'|'get_row'|'get_var'|'insert']`.
Inject `Clock` with a fixed `DateTimeImmutable` for anything time-dependent —
never assert against the real clock.

**JS harness.** Vitest + jsdom, alias `@` → `resources/admin`. Composables take
injected API objects as their second argument — use that instead of mocking
modules.

**Smoke harness.** `smoke/main.js` replaces `window.fetch` and returns fixtures
by URL. Mutation bodies land in `window.__fchubSmokeRequests` as
`{url, method, body}` — read them with `page.evaluate` to assert wire payloads.
Playwright `page.route` will **not** see these requests. Base URL is
`http://127.0.0.1:4173`, served by Vite via `playwright.config.js`.

**Live verification.** The Docker playground is a sibling checkout. Read-only
checks are allowed and encouraged:

```bash
cd ../../../fchub-playground && docker compose exec -T wpcli wp db query "SELECT ..."
```

To exercise plugin PHP against real data, write a script into
`plugins/fchub-memberships/`, run it with `wp eval-file`, then delete it — the
plugin directory is bind-mounted, `wp-content` is not. **Never** run
`docker compose down -v`, `docker volume rm`, or `docker system prune --volumes`;
the named volumes hold real working data.

**Gotchas.** `rm` and `cp` are interactive in this shell — always pass `-f`.
Full PHPUnit takes ~13s, Vitest ~9s, Playwright ~30s: a mutation run over 25
mutations takes several minutes, so run it in the background and monitor it
rather than blocking on it. Do not chain sleeps.

## Non-negotiables

- No commits, pushes, tags, or releases.
- English everywhere. Follow the repository's tracked `voice-tone.md` for prose.
- Do not touch `fchub-stream`.
- Do not weaken a test to make it pass. If production code is wrong, fix the
  production code and say so.
- Do not add options, flags, or configuration to make code testable. Inject
  dependencies through existing constructor parameters.
- Task-required Playwright lanes run without asking. Ad-hoc browser or
  computer-use sessions need explicit owner approval first.
- Source files stay under the 280-line cap and functions under 80 lines. Test
  files may exceed this where a table of cases genuinely needs the room.
- Report honestly. If a phase is incomplete, say which and why. A green summary
  covering an unfinished audit is the same defect this brief exists to remove.

## Definition of done

- Every seeded mutation is caught, or its survival is explained.
- Every deleted test has its three facts recorded.
- Every production defect found is either fixed with a failing-first test, or
  reported with a reproduction.
- PHPUnit, Vitest, Playwright, and the production build all pass.
- The working tree contains no leftover mutation, scratch script, or debug file.
