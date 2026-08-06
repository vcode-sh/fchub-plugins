# Memberships Plan Editor Refactor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the 2,357-line Plan Editor god component with focused components and composables while preserving every observable behaviour.

**Architecture:** Keep `PlanEditor.vue` as the route-aware form coordinator. Move pure form transformations to a policy module, asynchronous feature state to dependency-injected composables, and each major visual section plus its styles to a scoped child component.

**Tech Stack:** Vue 3.5 Composition API, Element Plus, Vue Router, Vitest, Vue Test Utils, Playwright, Vite, PHP 8+, PHPUnit.

## Global Constraints

- Preserve routes, copy, DOM semantics, accessibility behaviour, browser-test selectors, responsive layout, endpoints, call timing, and exact save payloads.
- Preserve legacy read-only rules, validation focus recovery, stale-response protection, lazy loading, retries, errors, and duplicate-save protection.
- Do not change backend APIs, schemas, dependencies, or product behaviour.
- Work in the existing checkout because the owner explicitly requested execution without another worktree prompt.
- Do not commit, push, publish, deploy, or touch `plugins/fchub-stream/`.
- Use `apply_patch` for hand edits and preserve unrelated changes.

---

### Task 1: Extract Pure Form Policy

**Files:**
- Create: `plugins/fchub-memberships/resources/admin/pages/Plans/planEditorForm.js`
- Create: `plugins/fchub-memberships/tests/admin/plan-editor-form.test.js`
- Modify: `plugins/fchub-memberships/resources/admin/pages/Plans/PlanEditor.vue`

**Interfaces:**
- Produces: `createPlanForm()`, `normalisePlanForm(plan)`, `applyDurationSelection(form, value)`, `applyMembershipTermMode(form, mode)`, `membershipTermHint(durationType)`, and `buildPlanSavePayload(form, { isNew, slugManuallyEdited })`.
- Consumes: `buildPlanRulesPayload()` from the existing plan-rule policy.

- [ ] Write literal behavioural tests for defaults, server normalisation, duration clearing, term-mode clearing/defaulting, every hint branch, create payload slug omission, edit payload slug inclusion, and legacy-rule payload omission.
- [ ] Run `npm test -- tests/admin/plan-editor-form.test.js` and confirm failure because `planEditorForm.js` does not exist.
- [ ] Implement the pure functions without Vue state or API calls.
- [ ] Replace the equivalent inline transformations and payload construction in `PlanEditor.vue`.
- [ ] Run the new test, existing Plan Editor unit tests, and the Plan Editor Playwright suite.

### Task 2: Extract Slug Preview State

**Files:**
- Create: `plugins/fchub-memberships/resources/admin/composables/plans/usePlanSlugPreview.js`
- Create: `plugins/fchub-memberships/tests/admin/plan-slug-preview.test.js`
- Modify: `plugins/fchub-memberships/resources/admin/pages/Plans/PlanEditor.vue`

**Interfaces:**
- Consumes: `{ plansApi, form, formRef, isNew, planId }`.
- Produces: `slugManuallyEdited`, `slugPreviewLoading`, `slugPreviewError`, `slugAvailable`, `onTitleInput(value)`, `onSlugInput(value)`, `flushSlugPreview()`, and `markPersistedSlug()`.

- [ ] Write tests using a complete fake preview response to prove automatic preview, manual preview, empty reset, server errors, pending-preview flush, and stale-response suppression.
- [ ] Run the focused test and confirm the missing-module failure.
- [ ] Implement the composable with injectable timer functions so debounce behaviour is deterministic in Vitest.
- [ ] Replace inline slug refs, timers, sequence handling and functions in `PlanEditor.vue`.
- [ ] Run focused unit tests and Plan Editor Playwright tests, including the Polish-slug and invalid-advanced-data cases.

### Task 3: Extract Access Rule State and Policy

**Files:**
- Create: `plugins/fchub-memberships/resources/admin/composables/plans/usePlanAccessRules.js`
- Create: `plugins/fchub-memberships/tests/admin/plan-access-rules.test.js`
- Modify: `plugins/fchub-memberships/tests/admin/plan-resource-capabilities.test.js`
- Modify: `plugins/fchub-memberships/resources/admin/pages/Plans/PlanEditor.vue`

**Interfaces:**
- Consumes: `{ contentApi, rules }` where `rules` is a getter for the canonical form rules.
- Produces: resource groups/options/loading, Space Groups/loading/selection, read-only state, rule mutation commands, resource validation, resource summaries, resource search, resource-type loading, Space Group loading, and `hydrateRuleOptions(rules)`.

- [ ] Replace source-string assertions with direct behavioural tests for allow-all defaults, positive integer IDs, slug IDs, historical-course normalisation, deleted labels, read-only locking, resource-type fallback, and unique Space Group additions.
- [ ] Run the focused tests and confirm failure on the missing composable exports rather than syntax or fixture errors.
- [ ] Implement the composable while retaining request debounce and current fallback values.
- [ ] Wire it into `PlanEditor.vue` and remove superseded inline state/functions.
- [ ] Run the focused tests, all Plan Editor unit tests, and Plan Editor Playwright tests.

### Task 4: Extract Management Composables

**Files:**
- Create: `plugins/fchub-memberships/resources/admin/composables/plans/usePlanProducts.js`
- Create: `plugins/fchub-memberships/resources/admin/composables/plans/usePlanSchedule.js`
- Create: `plugins/fchub-memberships/resources/admin/composables/plans/usePlanMembers.js`
- Create: `plugins/fchub-memberships/tests/admin/plan-management-composables.test.js`
- Modify: `plugins/fchub-memberships/resources/admin/pages/Plans/PlanEditor.vue`

**Interfaces:**
- Products consumes `{ plansApi, planId, isNew }` and returns current product/dialog/search/loading/error state plus load, retry, search, link, and unlink commands.
- Schedule consumes `{ plansApi, planId }` and returns schedule/loading plus hydrate, save, and clear commands.
- Members consumes `{ membersApi, planId, isNew, perPage: 10 }` and returns member/loading/error/page/total state plus load and retry commands.

- [ ] Write tests for lazy-load guards, retry behaviour, exact API arguments, truthful state after failures, duplicate-product marking, successful refreshes, schedule hydration/save/clear, and member pagination.
- [ ] Run the focused test and confirm missing-module failures.
- [ ] Implement each composable using dependency injection and existing messages.
- [ ] Replace inline management state and methods in `PlanEditor.vue` while preserving the tab watcher.
- [ ] Run focused tests and all product/member/schedule browser cases.

### Task 5: Extract Visual Steps and Their Styles

**Files:**
- Create: `plugins/fchub-memberships/resources/admin/components/plans/PlanOfferStep.vue`
- Create: `plugins/fchub-memberships/resources/admin/components/plans/PlanAccessStep.vue`
- Create: `plugins/fchub-memberships/resources/admin/components/plans/PlanReviewStep.vue`
- Create: `plugins/fchub-memberships/resources/admin/components/plans/PlanManagementTabs.vue`
- Modify: `plugins/fchub-memberships/resources/admin/pages/Plans/PlanEditor.vue`

**Interfaces:**
- Each child receives only its canonical model slice, feature refs and formatting functions.
- Children emit semantic operations; the parent remains responsible for navigation and final save.
- Existing class names, labels, ARIA relationships, form-item `prop` paths, and visible DOM order remain unchanged.

- [ ] Keep the existing Playwright suite green as the behavioural characterization while extracting one component at a time.
- [ ] Extract `PlanReviewStep.vue`, move only review styles, and run focused browser tests.
- [ ] Extract `PlanOfferStep.vue`, move only offer styles, and run focused browser tests.
- [ ] Extract `PlanAccessStep.vue`, move only access/rule styles, and run focused browser tests.
- [ ] Extract `PlanManagementTabs.vue`, move only management styles, and run focused browser tests.
- [ ] Remove selectors proven unused by current and extracted templates, then rerun desktop and mobile geometry tests.

### Task 6: Final Simplification and Verification

**Files:**
- Modify only files introduced or changed by Tasks 1–5.

**Interfaces:**
- `PlanEditor.vue` remains the sole router page for create and edit routes.
- No public API changes.

- [ ] Review `PlanEditor.vue` for remaining feature ownership and remove duplicated imports, state and dead functions.
- [ ] Run `npm test` and require all Vitest files and tests to pass.
- [ ] Run `npm run test:smoke` and require the complete Playwright smoke suite to pass.
- [ ] Run `npm run build` and require exit code 0.
- [ ] Run `./vendor/bin/phpunit` and require the complete PHP suite to pass.
- [ ] Run PHP lint only if PHP files changed; otherwise record that it is not applicable.
- [ ] Run `git diff --check`, inspect `git diff --stat`, inspect the complete scoped diff, and verify no unrelated files changed.
- [ ] Measure final SFC blocks and responsibility counts; report deviations from the 300–450-line target honestly.
- [ ] Leave every change uncommitted and unpushed for owner review.
