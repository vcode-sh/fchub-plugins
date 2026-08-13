# CartShift Story Review Implementation Plan

> **For agentic workers:** Execute inline with test-driven development. Do not commit, push, tag, publish, or release; the project owner owns Git history.

**Goal:** Replace the record-centric migration review with a fast, evidence-bound review that shows products, customers, orders, and subscriptions as understandable commerce stories.

**Architecture:** Materialise an allowlisted `review_context` from the same `GuidedSourceDependencyIndex` snapshot already used to build the proposal. `GuidedDecisionReview` will compose that context into three presentation sections while preserving the existing individual opaque review and choice IDs accepted by the REST controller. Vue will render one safe-plan batch, compact story choices, and one explicit stays-behind acknowledgement without changing the decision ledger or target execution pipeline.

**Tech Stack:** PHP 8.3, WordPress REST API, WooCommerce, WooCommerce Subscriptions, FluentCart 1.6.x, Vue 3 Composition API, PHPUnit 13, Vitest 4.

## Global Constraints

- Product copy, code, tests, and comments remain English.
- Existing FluentCart records are never overwritten or deleted.
- Source and target drift must refresh the review and write nothing.
- The browser submits the same individual `approved_reviews` and `review_answers`; grouping is presentation, not weaker acceptance.
- REST presentation exposes no canonical identities, target IDs, fingerprints, internal codes, or paths.
- Safe deterministic outcomes may be approved in one batch; real alternatives remain individually overridable.
- Large routine record lists stay available through progressive disclosure but are not rendered into the DOM all at once.
- Exactly one primary action is visible.
- No changes to `app/Domain/Transfer/Execution` are part of this review redesign.

---

### Task 1: Characterise the human review contract

**Files:**
- Modify: `plugins/cartshift/tests/Unit/Domain/Transfer/SameSite/GuidedDecisionReviewTest.php`
- Modify: `plugins/cartshift/tests/js/guidedMigrationScreen.test.js`

**Interfaces:**
- Consumes: current proposal rows plus product, customer, and collision questions.
- Produces: failing acceptance tests for story facts, deterministic batches, suggested choices, and bounded rendering.

- [ ] Add a PHP test with one product, customer, order, and collision context. Assert presentation contains a human order story with customer name, date, status, formatted amount facts, and item names, while canonical identity and fingerprints are absent.
- [ ] Add a PHP negative test proving a missing or malformed fact is reported through safe fallback copy rather than leaked raw identity.
- [ ] Add a Vue test for 1,599 deterministic items and 22 genuine choices. Assert the initial screen renders three review sections, not 1,621 record cards.
- [ ] Add a Vue test proving one click can apply every suggested unique customer match while an individual row can still be changed to create separately.
- [ ] Add a Vue test proving routine details render only the first 20 rows until `Show more` is used.
- [ ] Run the two focused suites and verify the new assertions fail for the current flat record review.

### Task 2: Build evidence-bound review context once

**Files:**
- Create: `plugins/cartshift/app/Domain/Transfer/SameSite/GuidedReviewContextBuilder.php`
- Modify: `plugins/cartshift/app/Domain/Transfer/SameSite/GuidedRunner.php`
- Test: `plugins/cartshift/tests/Unit/Domain/Transfer/SameSite/GuidedReviewContextBuilderTest.php`
- Modify: `plugins/cartshift/tests/Unit/Domain/Transfer/SameSite/GuidedRunnerTest.php`

**Interfaces:**
- Produces: `GuidedReviewContextBuilder::enrich(array $proposal, GuidedSourceDependencyIndex $index): array`.
- Adds internal proposal key `review_context` containing allowlisted record facts keyed only inside persisted private run state.
- Consumed by: `GuidedDecisionReview::presentation()` in Task 3.

- [ ] Write failing builder tests for product name/SKU/type/status, customer name/email/classification, order customer/date/status/currency/gross total/item summaries, and subscription customer/item context.
- [ ] Add order-line and product-subrecord tests proving context resolves to the owning root record without displaying technical source IDs.
- [ ] Add an allowlist negative test containing addresses, phone numbers, notes, payment metadata, and source fingerprints; assert none enter `review_context`.
- [ ] Implement the smallest pure builder over `RecordEnvelope` payloads and dependency-index lookups.
- [ ] Call it once at the end of `GuidedRunner::runProposal()` using the already materialised index. Do not reload WooCommerce.
- [ ] Run focused tests and assert source materialisation remains one call.

### Task 3: Compose safe batches and commerce stories

**Files:**
- Modify: `plugins/cartshift/app/Domain/Transfer/SameSite/GuidedDecisionReview.php`
- Modify: `plugins/cartshift/app/Domain/Transfer/SameSite/GuidedCollisionDecisionBuilder.php`
- Modify: `plugins/cartshift/tests/Unit/Domain/Transfer/SameSite/GuidedCollisionDecisionBuilderTest.php`
- Modify: `plugins/cartshift/tests/Unit/Domain/Transfer/SameSite/GuidedDecisionReviewTest.php`

**Interfaces:**
- `presentation()` keeps `items` for acceptance compatibility and adds `sections` with `safe_plan`, `choices`, and `stays_behind` groups.
- Each presentation item may add `story`, `recommended_choice_id`, and `outcome`; none expose internal evidence.
- `approve()` continues requiring every exact review ID and exact answer.

- [ ] Extend collision candidate evidence with an allowlisted target summary derived from the same snapshot already fingerprinted for drift detection.
- [ ] Present orders as customer, date, amount, status, first item names, remaining item count, and an `Already in FluentCart` target state.
- [ ] Classify no-choice rows and single-create questions into `safe_plan`; classify unique customer matches and multi-choice products into `choices`; classify cascade skips and known collisions into `stays_behind`.
- [ ] Mark only unique evidence-bound reuse/create outcomes as suggested. Never recommend among ambiguous candidates.
- [ ] Keep all underlying review IDs and choice IDs in each batch so bulk actions remain exact.
- [ ] Add negative tests for source context drift, target summary drift, partial bulk acceptance, ambiguous matches, and presentation leakage.
- [ ] Run the review, collision, controller acceptance, and stale-review tests.

### Task 4: Replace the flat Vue list with three focused sections

**Files:**
- Modify: `plugins/cartshift/src/components/GuidedDecisionReview.vue`
- Create: `plugins/cartshift/src/components/GuidedReviewBatch.vue`
- Create: `plugins/cartshift/src/components/GuidedReviewStory.vue`
- Modify: `plugins/cartshift/src/components/GuidedMigrationScreen.vue`
- Modify: `plugins/cartshift/src/styles/app.css`
- Modify: `plugins/cartshift/tests/js/guidedMigrationScreen.test.js`

**Interfaces:**
- `GuidedDecisionReview` remains the orchestration component and emits the existing `toggle`, `bulk-toggle`, `accept`, and `cancel` events.
- `GuidedReviewBatch` renders one batch outcome plus progressively disclosed rows.
- `GuidedReviewStory` renders one product/customer/order/subscription story and its radio choices.

- [ ] Render `Ready to move`, `Needs your choice`, and `Stays where it is` in that order.
- [ ] Add `Apply suggested matches` only when every included suggestion is unique and evidence-bound.
- [ ] Show order customer/date/amount/status and product lines in a compact scan row; show source and target states as plain labels, not technical comparison tables.
- [ ] Fix the 18-pixel choice-copy layout by making story copy full width and choices a separate row.
- [ ] Render 20 disclosed routine rows at a time with `Show 20 more`; keep the remaining data reachable without mounting every card.
- [ ] Replace `52 review steps` with section-aware progress and remaining genuine choices.
- [ ] Keep a single primary `Confirm review` action, disabled until safe batches, stays-behind acknowledgement, and all genuine choices are complete.
- [ ] Verify keyboard labels, fieldset legends, focus after refreshed evidence, colour-independent state, and reduced-motion behaviour.
- [ ] Run Vitest and the production build.

### Task 5: Install and verify the real Lapka flow

**Files:**
- Modify only defects demonstrated by the checks below.

**Interfaces:**
- Consumes: built CartShift 1.5.2 candidate.
- Produces: a visually and behaviourally verified review on `lapka.vcode.sh`; no decision acceptance or migration execution.

- [ ] Run focused PHP suites for context, review, collision, controller, and run projection.
- [ ] Run the complete CartShift PHPUnit and Vitest suites plus `npm run build` and `git diff --check`.
- [ ] Build the local 1.5.2 ZIP using the existing repository packaging script and install it into the Lapka Docker site.
- [ ] Regenerate the pending review so the persisted proposal includes `review_context`.
- [ ] Capture and inspect the overview, product choice, customer match, order collision, expanded detail, and disabled/enabled footer states at the same viewport.
- [ ] Verify the typical Lapka path is: approve safe plan, apply suggested customer matches, answer the two genuine product choices, acknowledge stays-behind handling, then reach one enabled confirm action.
- [ ] Do not press the final confirm action. Leave the verified review open for the owner.

## Self-Review

- Every approved design element maps to one task.
- Grouping does not weaken acceptance or target protection.
- The plan introduces one backend owner for presentation facts and two small Vue presentation components; no new route, schema, configuration option, or migration engine is added.
- No placeholder, commit, deployment, release, or terminal-facing member workflow remains.
