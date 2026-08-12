# CartShift Guided Mapping Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` or `superpowers:dispatching-parallel-agents` for isolated domains. Follow test-driven development and do not commit; the repository owner owns Git history.

**Goal:** Let a shop migrate from WooCommerce into an occupied FluentCart store through one simple GUI flow, without overwriting existing FluentCart rows or creating avoidable duplicates.

**Architecture:** Keep the existing evidence-bound decision ledger and target execution pipelines. Replace aggregate target-count refusal with per-source-record classification at the SameSite adapter boundary: create when no candidate exists, reuse only an evidence-bound target, and offer a plain skip when reuse cannot be proved. Run the existing subscription cutover domain services from the GUI through a new guided execution context, rather than exposing terminal commands or inventing a second migration engine.

**Tech Stack:** PHP 8.3, WordPress REST API, WooCommerce, WooCommerce Subscriptions, FluentCart 1.6.x, Vue 3, PHPUnit 13, Vitest 4.

## Implementation Status — 2026-08-13

Tasks 1–8 are implemented in the working tree. The integrated verification passed with 2,896 PHP tests and 12,763 assertions (32 environment skips), 409 Vue tests, a production frontend build, the CartShift CI scope contracts, and an independent final GO review with no reachable P0/P1 finding. The local 1.5.1 package was rebuilt from this tree.

The disposable installed-vendor matrix remains unrun because the required WooCommerce, FluentCart, and licensed WooCommerce Subscriptions ZIP files with trusted SHA-256 values are not available locally. Its setup script stopped before creating or mutating the disposable WordPress stack. Deployment, commit, tag, and release remain owner actions outside this plan.

## Global Constraints

- All repository artifacts and product copy are English.
- The only member job is: move the WooCommerce store into FluentCart without changing existing FluentCart records or creating duplicates.
- The GUI asks only decisions that source and target evidence cannot answer.
- Existing target records are never overwritten, deleted, or included in rollback ownership.
- `GET /cartshift/v1/migration/status` remains zero-write.
- Review and acceptance use opaque evidence-bound identifiers; source or target drift refreshes review and writes nothing.
- Products support `Use existing`, `Create separately`, and `Skip`; unsafe dependent skips include the complete reverse dependency closure and explain the consequence.
- Customers support `Use existing` and `Create separately`; `Skip` is available only when no selected order or subscription depends on the customer.
- Orders and subscriptions are created when no collision exists, reused only through an already checked exact map, and otherwise offer a safe skip. They are never overwritten and never created through a known collision.
- Active WooCommerce subscriptions use the existing prepared source-release and target-activation contracts from the GUI. No terminal or command is exposed.
- Subscription skips remain WooCommerce-managed and appear in the final exception report.
- Parent-shared stock keeps the approved conservative zero/unavailable projection and report.
- The guided route is one same-site migration, not an enterprise rehearsal/cutover ceremony. Rollback is available before source renewal ownership is released; after release, recovery proceeds forward idempotently.
- Existing valid decisions remain byte-identical unless their evidence changed.
- No commits, pushes, tags, releases, or deployment are part of this plan.

## Simplicity Evidence

### Aggregate FluentCart blocker

- **What it does:** `GuidedPreflightPresentation` promotes any existing FluentCart customer, order, or subscription count to a hard failure.
- **Who depends on it:** status/start controller gates, readiness panel, controller/preflight tests, and the current blocker copy.
- **Where it now lives:** aggregate counts become a warning; actual collisions are classified per source record during the existing review proposal.

### Subscription CLI sequence

- **What it does:** `LoadedTargetTransferPipeline`, `SubscriptionSourceCutover`, and `SubscriptionTargetCutover` already prepare, release, activate, and reconcile subscription ownership.
- **Who depends on it:** CLI transfer commands, completion gates, rollback gates, receipts, and subscription contract tests.
- **Where it now lives:** the same domain services are called by `GuidedRunner`; CLI remains an adapter, not the only implementation.

### Completed-rehearsal rollback gate

- **What it does:** `GuidedTargetReadinessInspector` currently stops every package because a completed rehearsal cannot be rolled back.
- **Who depends on it:** guided run state/projection, controller tests, and the current failed-state copy.
- **Where it now lives:** the member approves one real migration. Failures before subscription source release can roll back; failures after that durable boundary resume forward. A completed move is final and is not presented as a rehearsal.

---

### Task 1: Characterise the occupied-target and legacy-stop behaviour

**Files:**
- Modify: `plugins/cartshift/tests/Unit/Http/GuidedPreflightPresentationTest.php`
- Modify: `plugins/cartshift/tests/Unit/Domain/Transfer/SameSite/GuidedRunStateTest.php`
- Modify: `plugins/cartshift/tests/Unit/Domain/Transfer/SameSite/GuidedRunCoordinatorTest.php`
- Modify: `plugins/cartshift/tests/Unit/Http/Controllers/GuidedMigrationControllerTest.php`

**Interfaces:**
- Consumes: existing `GuidedPreflightPresentation::evaluate()` and persisted `GuidedRunState`.
- Produces: failing behavioural tests for the new warning and safe restart contract.

- [ ] Add a preflight fixture with 97 customers, 34 orders, and 4 subscriptions. Assert `ready === true`, `fc_data` remains `warn`, the message says records will be checked during review, and no `counts` key reaches REST presentation.
- [ ] Run `./vendor/bin/phpunit tests/Unit/Http/GuidedPreflightPresentationTest.php`; verify the new test fails because `fc_data` is promoted to `fail`.
- [ ] Add a persisted `FAILED` state with `descriptor === null` and failure `guided_dependency_bound_target_readiness_unavailable`; assert the coordinator starts a fresh run epoch while retaining the source namespace.
- [ ] Add the negative control with a descriptor; assert it never restarts and remains rollback-owned.
- [ ] Run the focused state/controller tests; verify the legacy pre-target restart test fails for the old refusal branch.

### Task 2: Replace aggregate refusal with truthful readiness

**Files:**
- Modify: `plugins/cartshift/app/Http/GuidedPreflightPresentation.php`
- Modify: `plugins/cartshift/app/Domain/Transfer/SameSite/GuidedRunState.php`
- Modify: `plugins/cartshift/app/Domain/Transfer/SameSite/GuidedRunCoordinator.php`
- Modify: `plugins/cartshift/app/Http/GuidedRunProjection.php`
- Modify: `plugins/cartshift/app/Http/Controllers/GuidedMigrationController.php`

**Interfaces:**
- Consumes: Task 1 tests.
- Produces: `GuidedRunState::canReplaceBeforeTarget(): bool` and warning-only occupied-target preflight.

- [ ] Implement `canReplaceBeforeTarget()` as `FAILED && descriptor === null` for the two superseded capability failures only. Keep subscription-mode drift, source-key changes, and target-prepared failures non-restartable.
- [ ] Make `GuidedRunCoordinator::start()` create a fresh state for that predicate; do not continue the old package or change `decisions.json`.
- [ ] Keep `fc_data` severity unchanged and replace the blocker copy with: “FluentCart already has … CartShift will check for safe matches during review. Unrelated records will stay untouched, and existing records will not be overwritten.”
- [ ] Remove rehearsal-only failure copy for the superseded states and call the journey a migration/store move.
- [ ] Run Task 1 tests and the controller leak/zero-write tests; verify all pass.

### Task 3: Build evidence-bound customer mapping choices

**Files:**
- Modify: `plugins/cartshift/app/Domain/Transfer/SameSite/GuidedCustomerDecisionBuilder.php`
- Modify: `plugins/cartshift/app/Domain/Transfer/SameSite/GuidedRunner.php`
- Modify: `plugins/cartshift/app/Domain/Transfer/SameSite/GuidedDecisionReview.php`
- Modify: `plugins/cartshift/app/Domain/Transfer/Customer/CustomerAssessor.php`
- Modify: `plugins/cartshift/app/Domain/Transfer/Customer/CustomerWriter.php`
- Modify: `plugins/cartshift/app/Domain/Transfer/Execution/CustomerEnvelopeReconciler.php`
- Modify: `plugins/cartshift/app/Domain/Transfer/Execution/RollbackPlanner.php`
- Test: `plugins/cartshift/tests/Unit/Domain/Transfer/SameSite/GuidedCustomerDecisionBuilderTest.php`
- Test: `plugins/cartshift/tests/Unit/Domain/Transfer/Customer/CustomerWriterTest.php`
- Test: `plugins/cartshift/tests/Unit/Domain/Transfer/Execution/RollbackPlannerTest.php`

**Interfaces:**
- Produces: `GuidedCustomerDecisionBuilder::enrich(array $proposal, TransferSelection $selection): array` with `customer_questions`.
- Produces: question choices containing opaque `choice_id`, internal `action`, source fingerprint, complete target snapshot fingerprint, and friendly target label.
- Consumes later: `GuidedDecisionReview` and controller acceptance under the existing state lock.

- [ ] Write failing tests for: no candidate creates the current ownership acknowledgement; unique same `user_id` candidate offers `Use existing customer`; email-only candidate offers explicit `Use existing`/`Create separately`; multiple candidates list each candidate once; target read failure blocks; changed target snapshot changes `review_id` and `choice_id`.
- [ ] Verify RED with the focused builder test.
- [ ] Implement one target query by normalised email plus same-site `user_id`, returning complete target snapshots and no raw IDs in presentation.
- [ ] Resolve `Use existing` to `reuse_explicit_target_customer`; resolve `Create separately` to the existing `attach_exact_same_site_user` or `allow_unlinked_downloads` decision. Never infer guest account ownership from email.
- [ ] Recheck the chosen target fingerprint in `CustomerAssessor` and `CustomerWriter`, write only a non-owning CartShift map, and avoid customer/address mutation.
- [ ] Reconcile reused customers at the root only; source addresses remain order-owned data rather than false customer ownership.
- [ ] Extend evidence-only rollback so a run-created non-owning map is retired without deleting or changing the target customer. Pre-existing maps remain byte-identical.
- [ ] Run the builder, writer, reconciler, and rollback tests; verify zero create/update calls for reuse and exact target bytes before/after rollback.

### Task 4: Add safe order and subscription collision choices

**Files:**
- Create: `plugins/cartshift/app/Domain/Transfer/SameSite/GuidedCollisionDecisionBuilder.php`
- Create: `plugins/cartshift/app/Domain/Transfer/SameSite/GuidedSourceDependencyIndex.php`
- Modify: `plugins/cartshift/app/Domain/Transfer/SameSite/GuidedRunner.php`
- Modify: `plugins/cartshift/app/Domain/Transfer/SameSite/GuidedDecisionReview.php`
- Modify: `plugins/cartshift/app/Domain/Transfer/Decision/TransferDecisionSet.php`
- Modify: `plugins/cartshift/app/Domain/Transfer/Subscription/LoadedFluentCartSubscriptionGateway.php`
- Test: `plugins/cartshift/tests/Unit/Domain/Transfer/SameSite/GuidedCollisionDecisionBuilderTest.php`
- Test: `plugins/cartshift/tests/Unit/Domain/Transfer/SameSite/GuidedSourceDependencyIndexTest.php`
- Test: `plugins/cartshift/tests/Unit/Domain/Transfer/Subscription/LoadedFluentCartSubscriptionGatewayTest.php`

**Interfaces:**
- Produces: `GuidedCollisionDecisionBuilder::enrich()` with `collision_questions` and `resolve()` with exact `excluded_by_policy` rows.
- Produces: `GuidedSourceDependencyIndex::closure(SourceIdentity $root): list<RecordEnvelope>` ordered products/customers/orders/subscriptions.

- [ ] Write failing tests proving unrelated target orders/subscriptions create no question and do not block.
- [ ] Write failing order-collision tests: invoice/UUID discovery is not identity; the only safe unresolved choice is “Keep the existing FluentCart order and skip this WooCommerce copy”; dependent subscriptions are included in the displayed consequence and excluded in the same accepted decision set.
- [ ] Write failing subscription-collision tests using deterministic CartShift UUID. The choice skips that WooCommerce subscription and states that its renewal remains managed in WooCommerce.
- [ ] Write negative tests for duplicated candidates, target read failure, dependency cycles, stale target fingerprints, partial answers, and a collision appearing after review. All must write nothing.
- [ ] Implement a single materialised source dependency index so source evidence is read once per proposal and skip closure is deterministic.
- [ ] Add a deterministic UUID collision check before `LoadedFluentCartSubscriptionGateway::create()`; a known collision must fail before insert even if the database lacks a unique constraint.
- [ ] Resolve accepted collision questions to canonical `excluded_by_policy` rows for the root and exact dependent closure. Do not add an overwrite or create-through-collision action.
- [ ] Run focused tests and verify source/target drift changes opaque IDs and acceptance fails closed.

### Task 5: Make product skip close dependencies instead of dead-ending

**Files:**
- Modify: `plugins/cartshift/app/Domain/Transfer/SameSite/GuidedProductQuestionBuilder.php`
- Modify: `plugins/cartshift/app/Domain/Transfer/SameSite/GuidedProductDecisionBuilder.php`
- Modify: `plugins/cartshift/tests/Unit/Domain/Transfer/SameSite/GuidedProductQuestionBuilderTest.php`
- Modify: `plugins/cartshift/tests/Unit/Domain/Transfer/SameSite/GuidedProductDecisionBuilderTest.php`

**Interfaces:**
- Consumes: `GuidedSourceDependencyIndex` from Task 4.
- Produces: every unresolvable product match has a safe skip choice with exact dependent closure.

- [ ] Write a failing test where a strong product candidate has incompatible variations and is used by one order and one subscription. Assert there is no blocker and the only choice is a cascade skip with both dependants named by friendly counts.
- [ ] Write a negative control where variations align; `Use existing` remains available and the target is not mutated.
- [ ] Bind the complete closure fingerprints into the product question and emit `excluded_by_policy` rows for every skipped root.
- [ ] Keep `Create separately` unavailable for a strong duplicate signal; allow it only where the existing matcher already classifies the candidate as non-strong.
- [ ] Run product builder/question tests and the transfer dependency graph tests.

### Task 6: Wire the complete same-site subscription sequence

**Files:**
- Create: `plugins/cartshift/app/Domain/Transfer/SameSite/GuidedSubscriptionSourceRelease.php`
- Modify: `plugins/cartshift/app/Domain/Transfer/SameSite/GuidedRunPlan.php`
- Modify: `plugins/cartshift/app/Domain/Transfer/SameSite/GuidedRunner.php`
- Modify: `plugins/cartshift/app/Domain/Transfer/Execution/LoadedTargetPreparePipeline.php`
- Modify: `plugins/cartshift/app/Domain/Transfer/Execution/PreparedTransfer.php`
- Modify: `plugins/cartshift/app/Domain/Transfer/Subscription/SubscriptionCutoverEvidence.php`
- Modify: `plugins/cartshift/app/Domain/Transfer/SameSite/GuidedRollback.php`
- Test: `plugins/cartshift/tests/Unit/Domain/Transfer/SameSite/GuidedRunPlanTest.php`
- Test: `plugins/cartshift/tests/Unit/Domain/Transfer/SameSite/GuidedRunnerTest.php`
- Test: `plugins/cartshift/tests/Unit/Domain/Transfer/SameSite/GuidedSubscriptionSourceReleaseTest.php`

**Interfaces:**
- Adds execution context `guided` accepted only by the SameSite adapter and shared prepared-transfer/subscription evidence.
- Adds guided verbs in order: `prepare-subscription-cutover`, `release-subscription-source`, `activate-subscriptions` between `promote` and `activate-catalogue`.

- [ ] Write a failing plan test asserting the exact subscription sequence: stage, reconcile, promote, prepare source/target evidence, release Woo renewal ownership, activate and reconcile FluentCart subscriptions, activate catalogue, complete.
- [ ] Write a non-subscription negative control asserting those three verbs are absent.
- [ ] Write runner tests proving every emitted option is translated and all three verbs call shared domain services, not CLI wrappers.
- [ ] Implement `guided` execution context in prepared transfer and subscription evidence validation; CLI continues to accept only rehearsal/cutover.
- [ ] Implement `GuidedSubscriptionSourceRelease`: re-read prepared evidence, source instance/runtime fingerprints, and call `SubscriptionSourceCutover::release()` idempotently with the existing mark-before-act contract.
- [ ] Treat release as the irreversible boundary: rollback remains available while cutover evidence is only `prepared`; after source release, the run may only resume forward.
- [ ] Run the guided plan/runner tests plus all subscription cutover, completion-gate, and rollback-gate tests.

### Task 7: Remove artificial readiness stops and preserve real capability checks

**Files:**
- Modify: `plugins/cartshift/app/Domain/Transfer/SameSite/GuidedTargetReadinessInspector.php`
- Modify: `plugins/cartshift/app/Domain/Transfer/SameSite/GuidedRunState.php`
- Modify: `plugins/cartshift/app/Domain/Transfer/SameSite/GuidedRunCoordinator.php`
- Modify: `plugins/cartshift/app/Http/GuidedRunProjection.php`
- Test: `plugins/cartshift/tests/Unit/Domain/Transfer/SameSite/GuidedTargetReadinessInspectorTest.php`
- Test: `plugins/cartshift/tests/Unit/Domain/Transfer/SameSite/GuidedRunCoordinatorTest.php`

**Interfaces:**
- Produces: validated packages proceed to prepare; product/customer plans are checked early, dependency-bound order/subscription plans are checked at their normal stage boundary.
- Produces: `GuidedRunState::canResumeForward()` for idempotent post-release recovery.

- [ ] Replace tests expecting `guided_dependency_bound_target_readiness_unavailable` and `guided_completed_rehearsal_rollback_unavailable` with a package-backed test that reaches prepare and never invokes a target writer during validation.
- [ ] Keep real product/customer capability failures as blockers and preserve parent-stock migration exceptions.
- [ ] Add crash-window tests: source release succeeds but adapter response is lost; retry must not release twice and must continue to target activation. Target activation succeeds but adapter state write is lost; retry resumes without duplicate mutation.
- [ ] Implement forward resume only when durable subscription evidence proves release has started. Other descriptor-bearing failures keep rollback behaviour.
- [ ] Run readiness/state/coordinator tests and the target pipeline idempotency tests.

### Task 8: Present one obvious GUI flow and a useful exception report

**Files:**
- Modify: `plugins/cartshift/src/components/GuidedMigrationScreen.vue`
- Modify: `plugins/cartshift/src/components/GuidedReadinessPanel.vue`
- Modify: `plugins/cartshift/src/components/GuidedDecisionReview.vue`
- Modify: `plugins/cartshift/src/components/GuidedRunPanel.vue`
- Modify: `plugins/cartshift/src/admin.css`
- Modify: `plugins/cartshift/app/Http/GuidedRunProjection.php`
- Modify: `plugins/cartshift/tests/js/guidedMigrationScreen.test.js`
- Modify: `plugins/cartshift/tests/Unit/Http/GuidedRunProjectionTest.php`

**Interfaces:**
- Consumes: customer/product/collision question shapes and guided run state.
- Produces: one primary action per state and no technical identifiers in rendered copy.

- [ ] Write failing component tests for: occupied target shows a warning and enabled `Review my store`; customer/product/collision choices share the same radio component; confirm is disabled until every item is answered; exactly one primary button is visible; no overwrite option appears.
- [ ] Replace “rehearsal/check” progress copy with “migration/store move” copy once records start moving. Before target preparation, say review; after preparation, say migration.
- [ ] Render skipped products/orders/subscriptions in the exception report with what stayed in WooCommerce and why. Keep details behind progressive disclosure.
- [ ] Preserve the parent-stock report and add no remediation write button.
- [ ] Ensure the acceptance request contains only opaque review IDs and choice IDs. A `review_changed` response clears answers, explains that nothing was saved, and focuses the refreshed review.
- [ ] Run Vitest and verify keyboard labels, focus behaviour, one-primary-action, and technical-data leak assertions.

### Task 9: Full integration and release-candidate verification

**Files:**
- Modify only defects found by the verification commands below.

**Interfaces:**
- Consumes: Tasks 1–8.
- Produces: a locally verified 1.5.1 candidate; no commit, deployment, or release.

- [ ] Run focused PHP tests for every changed domain and fix only observed failures.
- [ ] Run `./vendor/bin/phpunit` from `plugins/cartshift`.
- [ ] Run `npm test -- --run` from `plugins/cartshift`.
- [ ] Run `npm run build` from `plugins/cartshift`.
- [ ] Run the repository scoped diff/contract check used by CartShift.
- [ ] Run the mounted-runtime contract for: occupied target with unrelated rows, product reuse, customer reuse, order collision skip, subscription collision skip, active subscription guided sequence, pre-release rollback, and post-release forward recovery.
- [ ] Perform an independent final code review for data ownership, duplicate prevention, target non-mutation, source/target drift, rollback boundary, REST leaks, and the one-primary-action GUI contract.
- [ ] Record exact test totals and remaining limitations. Do not call the candidate complete if any selected source record has no create/reuse/skip disposition.

## Final Acceptance Matrix

| Source/target situation | CartShift behaviour |
|---|---|
| No target candidate | Create a new FluentCart record |
| Existing checked exact map | Reuse automatically and do not mutate target |
| Product candidate | Owner chooses Use existing, Create separately where non-duplicate, or Skip |
| Customer candidate | Owner chooses a specific existing customer or Create separately |
| Order collision without exact checked map | Keep target untouched and skip the Woo copy plus exact dependent closure |
| Subscription collision without exact checked map | Keep target untouched and leave the Woo subscription Woo-managed |
| Unrelated target record | Ignore it; no question and no rollback ownership |
| Shared parent stock | Migrate unavailable with zero stock; report manual stock setup |
| Active migrated subscription | Stage paused, release Woo renewal ownership, activate and reconcile FluentCart |
| Failure before renewal release | Offer receipt-scoped rollback |
| Failure after renewal release | Resume forward idempotently; never pretend rollback is safe |
| Completed migration | Show completion and exceptions; do not present it as a rehearsal |
