# Memberships Critical Vue Refactors Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reduce all seven critical memberships Vue files below 500 lines while preserving every established behavioural and visual contract.

**Architecture:** Each route remains a thin coordinator. Stateful policy moves to tested composables or pure modules; coherent visual regions move to child components with explicit props and emits. CSS follows markup except for cross-component or teleported WordPress geometry.

**Tech Stack:** Vue 3 Composition API, Element Plus, Vitest, Vue Test Utils, Playwright, Vite, PHPUnit.

## Global Constraints

- All code, tests, documentation, and commit text are English.
- Do not edit, test, or review `plugins/fchub-stream`.
- Do not change API paths, payload shapes, labels, selectors, accessibility, routing, or error/loading behaviour.
- Write a focused failing test before each new logic boundary, verify the expected failure, then implement.
- Keep every scoped original Vue file below 500 physical lines.
- Do not build, edit `assets/dist`, commit, or push from worker tasks.
- Preserve unrelated work and do not edit another task's original file.

---

### Task 1: Settings workspace

**Files:**
- Modify: `plugins/fchub-memberships/resources/admin/pages/Settings.vue`
- Create: `plugins/fchub-memberships/resources/admin/composables/settings/useSettingsForm.js`
- Create: `plugins/fchub-memberships/resources/admin/composables/settings/useSettingsIntegrationOptions.js`
- Create: `plugins/fchub-memberships/resources/admin/composables/settings/useWebhookSettingsOperations.js`
- Test: `plugins/fchub-memberships/tests/admin/settings-*.test.js`

**Interfaces:**
- Consumes the existing reactive settings form, route category, settings API, integrations API, and webhook child contracts.
- Produces the same refs, actions, payload builder, validation results, and stale-request guards currently owned by the page.

- [ ] Add focused tests that import the future composable boundaries and fail because they do not exist.
- [ ] Extract form hydration, serialisation, dirty snapshots, save/discard, and validation without changing object identity.
- [ ] Extract plan, FluentCRM, and FluentCommunity option loading with current validation/error behaviour.
- [ ] Extract credential, endpoint, history, busy-map, confirmation, and stale-response operations.
- [ ] Reduce `Settings.vue` below 500 lines and run focused Settings Vitest and Playwright suites.

### Task 2: Notification Studio

**Files:**
- Modify: `plugins/fchub-memberships/resources/admin/components/settings/SettingsNotificationsSection.vue`
- Create: `plugins/fchub-memberships/resources/admin/composables/settings/useNotificationStudio.js`
- Create: `plugins/fchub-memberships/resources/admin/components/settings/NotificationStudioCatalog.vue`
- Create: `plugins/fchub-memberships/resources/admin/components/settings/NotificationEmailEditor.vue`
- Create: `plugins/fchub-memberships/resources/admin/components/settings/NotificationBrandEditor.vue`
- Test: `plugins/fchub-memberships/tests/admin/notification-studio-*.test.js`

**Interfaces:**
- Keeps the existing standalone prop and direct email-notification API boundary.
- Preserves 350 ms preview debounce, optimistic delivery rollback, draft/block operations, media picker fallback, and separate error domains.

- [ ] Add focused tests for the future studio composable and observe the missing-module failure.
- [ ] Extract catalogue, draft, preview, test, save, rollback, and media state.
- [ ] Extract catalogue and email/brand editor surfaces without wrapper or selector changes.
- [ ] Remove the unconsumed non-standalone branch only if source search and tests prove it has no consumer; otherwise preserve it.
- [ ] Reduce the original component below 500 lines and run focused notification/Settings tests.

### Task 3: Member profile

**Files:**
- Modify: `plugins/fchub-memberships/resources/admin/pages/Members/MemberProfile.vue`
- Create: `plugins/fchub-memberships/resources/admin/composables/members/useMemberProfile.js`
- Create: `plugins/fchub-memberships/resources/admin/composables/members/useMemberActivity.js`
- Create: `plugins/fchub-memberships/resources/admin/components/members/profile/*.vue`
- Test: `plugins/fchub-memberships/tests/admin/member-profile-*.test.js`

**Interfaces:**
- Route remains owner of `route.params.id`.
- Preserve current-access semantics for active and paused grants, mutation refresh scope, fixed-user grant dialog, activity pagination, and silent fallback domains.

- [ ] Add focused failing tests for member hydration/mutation and activity pagination boundaries.
- [ ] Extract member and activity composables while retaining existing API/message behaviour.
- [ ] Extract hero, access, drip schedule, history, activity, extend dialog, and timeline drawer surfaces.
- [ ] Move component-local CSS with exact responsive rules and selectors.
- [ ] Reduce the page below 500 lines and run focused member-profile Vitest and Playwright suites.

### Task 4: Content protection wizard

**Files:**
- Modify: `plugins/fchub-memberships/resources/admin/components/content/ContentProtectionWizard.vue`
- Create: `plugins/fchub-memberships/resources/admin/components/content/wizard/ContentProtectionWizardProgress.vue`
- Create: `plugins/fchub-memberships/resources/admin/components/content/wizard/ContentProtectionWizardCategoryStep.vue`
- Create: `plugins/fchub-memberships/resources/admin/components/content/wizard/ContentProtectionWizardResourceStep.vue`
- Create: `plugins/fchub-memberships/resources/admin/components/content/wizard/ContentProtectionWizardAccessStep.vue`
- Create: `plugins/fchub-memberships/resources/admin/components/content/wizard/ContentProtectionWizardReviewStep.vue`
- Test: `plugins/fchub-memberships/tests/admin/content-protection-wizard.test.js`

**Interfaces:**
- Root dialog retains props/emits/footer, disabled Escape/overlay behaviour, unscoped geometry CSS, and form mutation contract.
- Child components preserve every `.cpw-*` selector, ARIA relationship, picker-visible browse call, and guarded step navigation.

- [ ] Add a failing component-boundary test for the future step components.
- [ ] Extract progress and four step templates with explicit props/emits.
- [ ] Keep root/global CSS and exact DOM class structure intact.
- [ ] Reduce the root below 500 lines and run focused unit and content-protection Playwright suites.

### Task 5: Dashboard

**Files:**
- Modify: `plugins/fchub-memberships/resources/admin/pages/Dashboard.vue`
- Create: `plugins/fchub-memberships/resources/admin/composables/dashboard/useDashboard.js`
- Create: `plugins/fchub-memberships/resources/admin/pages/dashboardUi.js`
- Create: `plugins/fchub-memberships/resources/admin/components/dashboard/Dashboard*.vue`
- Test: `plugins/fchub-memberships/tests/admin/dashboard-*.test.js`

**Interfaces:**
- Preserve strict wrapped-response validation, honest malformed/empty states, chart theme handling, safe destinations/severities, activity labels, and all route links.

- [ ] Add failing tests for the future response/display policy module.
- [ ] Extract validation and display functions, then loading/chart state.
- [ ] Extract attention, summary, readiness, trend, distribution, and activity panels with current selectors.
- [ ] Reduce the page below 500 lines and run dashboard Vitest and Playwright suites.

### Task 6: Member import wizard

**Files:**
- Modify: `plugins/fchub-memberships/resources/admin/pages/Import/ImportWizard.vue`
- Create: `plugins/fchub-memberships/resources/admin/composables/import/useMemberImportWizard.js`
- Create: `plugins/fchub-memberships/resources/admin/components/import/Import*Step.vue`
- Test: `plugins/fchub-memberships/tests/admin/import-wizard.test.js`

**Interfaces:**
- Preserve file validation/parsing, level mappings, option semantics, preview counters, batch order, progress, result aggregation, report download, reset, and exact import API payloads.

- [ ] Add failing tests for pure/composable import policy and payload boundaries.
- [ ] Extract workflow state/actions into the composable.
- [ ] Extract Upload, Mapping, Options, Preview, and Progress/Results step components.
- [ ] Move local styles with selectors and preserve mobile progress geometry.
- [ ] Reduce the page below 500 lines and run focused import tests and owning Playwright checks.

### Task 7: Plan list

**Files:**
- Modify: `plugins/fchub-memberships/resources/admin/pages/Plans/PlanList.vue`
- Create: `plugins/fchub-memberships/resources/admin/composables/plans/usePlanList.js`
- Create: `plugins/fchub-memberships/resources/admin/composables/plans/usePlanTransfer.js`
- Create: `plugins/fchub-memberships/resources/admin/components/plans/PlanListTable.vue`
- Create: `plugins/fchub-memberships/resources/admin/components/plans/PlanListMobile.vue`
- Create: `plugins/fchub-memberships/resources/admin/components/plans/PlanImportDialog.vue`
- Test: `plugins/fchub-memberships/tests/admin/plan-list-*.test.js`

**Interfaces:**
- Preserve request sequencing, filters/pagination, route actions, archive/delete history policy, import/export payloads, browser downloads, messages, and desktop/mobile parity.

- [ ] Add failing tests for future list/transfer composables and policy helpers.
- [ ] Extract list fetching/filtering/mutations and transfer operations.
- [ ] Extract desktop/mobile renderers and import dialog without selector changes.
- [ ] Reduce the page below 500 lines and run plan-list Vitest and Playwright suites.

### Task 8: Integrated verification

**Files:**
- Rebuild: `plugins/fchub-memberships/assets/dist/**`

- [ ] Confirm all seven original Vue files and all new Vue files are below 500 lines.
- [ ] Run full Vitest.
- [ ] Run all focused owner Playwright suites, then full Playwright.
- [ ] Run the production build once.
- [ ] Run PHPUnit after the build has completed.
- [ ] Run `git diff --check`, inspect scope, and perform a broad code review.
