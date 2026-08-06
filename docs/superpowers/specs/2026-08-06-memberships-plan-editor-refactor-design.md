# Memberships Plan Editor Refactor Design

## Objective

Refactor `plugins/fchub-memberships/resources/admin/pages/Plans/PlanEditor.vue` into focused Vue components and composables without changing observable behaviour. The current 2,357-line component combines page orchestration, form state, validation, API operations, feature-specific state, responsive layout, and styling.

## Preservation Contract

- Preserve routes, copy, DOM semantics, accessibility behaviour, selectors used by browser tests, and responsive layout.
- Preserve all request endpoints, request timing that affects user-visible behaviour, and exact create/update payloads.
- Preserve create and edit flows, legacy read-only content rules, validation recovery, duplicate-save protection, stale slug-response protection, lazy tab loading, error states, and retry behaviour.
- Do not change backend PHP, public APIs, dependencies, or product behaviour.
- Do not commit, push, publish, deploy, or edit the discontinued FCHub Stream plugin.

## Current Evidence

- The repository was clean before planning.
- `PlanEditor.vue` contains 550 template lines, 959 script lines, and 844 scoped-style lines.
- The focused unit baseline passes 57 tests.
- The full admin Vitest baseline passes 275 tests across 33 files.
- The Plan Editor Playwright baseline passes 20 tests.
- Existing unit coverage includes pure UI policy tests and several source-string assertions. Browser coverage exercises the three-step flow, payload shape, validation recovery, responsive geometry, loading failures, retries, product operations, and duplicate-save protection.

## Chosen Approach

Use incremental feature-boundary extraction. `PlanEditor.vue` remains the page-level coordinator while coherent visual sections move to child components and stateful operations move to composables. This reduces responsibility and navigation cost without introducing a new global store or rewriting the user flow.

Template-only extraction is rejected because it leaves the component's state and API responsibilities intact. A new Pinia store or formal state machine is rejected because it changes data-flow architecture without a product requirement and creates needless regression risk.

## Target Architecture

### Page coordinator

`PlanEditor.vue` owns only:

- route and create-versus-edit context;
- the shared Element Plus form reference;
- active builder step and page-level submission flow;
- initial feature loading orchestration;
- validation-error focus routing;
- composition of the extracted features;
- navigation after a successful save.

The target is approximately 300–450 lines. This is a target rather than a correctness condition; clear ownership takes precedence over manipulating the counter.

### Visual components

- `PlanOfferStep.vue`: title, description, availability, duration, advanced settings, membership term, and the existing schedule panel.
- `PlanAccessStep.vue`: content-rule empty state, rule cards, resource controls, drip controls, and FluentCommunity Space Group addition.
- `PlanReviewStep.vue`: review summary and plan warnings.
- `PlanManagementTabs.vue`: drip preview, linked products, members, and the link-product dialog.

Existing focused components remain in use: builder progress, summaries, schedule panel, linked-products tab, members tab, and link-product dialog.

### Composables and policies

- `usePlanEditorForm.js`: default form creation, server response normalisation, validation rules, term handling, duration handling, and save-payload construction.
- `usePlanSlugPreview.js`: debounce, request sequencing, manual-versus-automatic slug state, availability, errors, and pending-preview flushing.
- `usePlanAccessRules.js`: resource-type loading, resource search, rule mutation and validation, legacy locks, Space Group loading, and bulk rule addition.
- `usePlanProducts.js`: lazy loading, search, duplicate state, linking, unlinking, retries, and errors.
- `usePlanSchedule.js`: schedule state, save, clear, errors, and loading state.
- `usePlanMembers.js`: lazy loading, pagination, retries, and errors.
- Existing pure policy modules remain pure. Functions currently verified by source-string assertions move behind exported functions and receive behavioural unit tests.

## Data Flow

The page owns the canonical reactive plan form because Element Plus validates nested paths against that model. Step components receive the relevant model slices and explicit state, then emit semantic actions. Stateful composables receive API dependencies explicitly and return refs plus commands, following existing repository patterns.

Feature components do not call unrelated APIs. Product and member state remain lazy: switching to a management tab triggers its owning composable once, while an explicit retry bypasses the loaded guard. Saving still validates the complete form, builds the same payload, performs one create or update mutation, and navigates only after success.

## Styling Strategy

Move styles with the template that owns them. Page-shell, progress placement, form actions, and cross-step responsive layout remain in `PlanEditor.vue`. Offer, access, review, and management styles move into their respective scoped components.

Before removing any selector, confirm that it is absent from the current template and extracted children, then rely on desktop and mobile Playwright coverage. Do not replace the component with a single external mega-stylesheet.

## Test Strategy

1. Add behavioural characterisation tests before extracting each logic boundary and run them to confirm the missing extraction fails for the expected reason.
2. Replace source-string assertions with direct tests of exported policies while preserving the asserted behaviours.
3. After each extraction, run its focused Vitest tests and the Plan Editor Playwright suite.
4. At completion, run all admin Vitest tests, all Playwright smoke tests, the production frontend build, the complete memberships PHPUnit suite, PHP lint where relevant, `git diff --check`, and a final Git/status review.
5. Compare final create/update requests, edit loading, mobile layout, validation focus, read-only rules, schedules, linked products, and members against the preservation contract.

## Failure Handling

- API errors retain their current user-visible messages and recoverability.
- Stale asynchronous slug and resource-search responses must not overwrite newer state.
- Failed link/unlink, member, or product operations retain truthful existing data.
- A failed save must keep the user in the editor with the first invalid control revealed and focused.
- Refactor work stops if baseline behaviour cannot be reproduced or a failing test cannot be attributed to the intended extraction.

## Out of Scope

- UX, copy, visual-design, endpoint, schema, or payload changes.
- New state-management dependencies.
- Backend refactoring.
- General cleanup outside Plan Editor ownership.
- Release, deployment, or publication work.
