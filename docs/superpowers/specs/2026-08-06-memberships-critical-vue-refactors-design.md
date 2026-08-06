# Memberships Critical Vue Refactors Design

## Goal

Refactor every supported memberships Vue file at or above 800 physical lines into focused components and composables without changing user-visible behaviour, network contracts, DOM selectors, accessibility, or WordPress-admin geometry.

## Scope

The seven critical files are:

- `pages/Members/MemberProfile.vue`
- `components/content/ContentProtectionWizard.vue`
- `pages/Settings.vue`
- `pages/Dashboard.vue`
- `pages/Import/ImportWizard.vue`
- `components/settings/SettingsNotificationsSection.vue`
- `pages/Plans/PlanList.vue`

`fchub-stream` remains excluded because maintenance is discontinued. Existing warning-level files below 800 lines are outside this pass.

## Success Criteria

- Every scoped Vue file finishes below 500 physical lines.
- Extracted files have one named responsibility and remain below 500 lines unless generated output makes that impossible.
- Existing API methods, payloads, response handling, routing, labels, loading states, error states, focus behaviour, and confirmation semantics remain unchanged.
- Existing Playwright selectors and responsive layouts remain valid.
- New logic boundaries receive focused tests written before implementation and observed failing for the missing extraction.
- The complete Vitest, Playwright, production build, PHP, and diff verification lanes pass.
- Generated `assets/dist` files are rebuilt once, after source verification, rather than by parallel workers.

## Architecture

### Settings

`Settings.vue` remains the route and category controller. Form hydration/serialisation, integration option loading, and webhook operations move to separate composables. The existing section components retain the same mutable form object and event contracts.

`SettingsNotificationsSection.vue` remains the standalone Email Studio boundary. Catalogue/draft/preview/test/save state moves to `useNotificationStudio`; catalogue and editor surfaces become child components. It must not be merged with page-wide Settings persistence.

### Member profile

`MemberProfile.vue` remains the route owner. Member/access mutations and activity pagination move to composables. Hero, access, history, activity, extend-dialog, and drip-timeline surfaces become components. Paused access, refresh behaviour, fixed-user grant flow, and silent fallback domains remain unchanged.

### Content protection

`ContentProtectionWizard.vue` remains the Element Plus dialog root and keeps its global WordPress geometry CSS. The progress rail and four steps become presentation components. `useContentProtectionWizard` remains the API/state owner.

### Dashboard

Dashboard response validation and display policy move to a pure module, while loading/theme/chart state moves to a composable. Attention, summary, readiness, trend, distribution, and activity panels become presentation components. The page remains the route shell.

### Import workflow

Parsing, mappings, derived previews, batch execution, reporting, and reset state move to a composable. Each wizard step becomes a component while the page retains navigation and header ownership.

### Plan list

List fetching, stale-request protection, filtering, mutations, import/export, and dialogs move into focused composables. Desktop/mobile records and import/delete dialogs become components. Route navigation and exact API messages remain unchanged.

## Styling Strategy

Scoped styles move with extracted markup when selectors are component-local. Cross-component shell and responsive geometry stay at the page level. The content-protection wizard deliberately retains its unscoped root stylesheet because teleported Element Plus DOM and WordPress sidebar offsets depend on cascade order and global selectors.

## Verification Strategy

Each track runs focused Vitest and its owning Playwright file. Parallel workers do not run the production build or mutate `assets/dist`. After integration, verification runs sequentially:

1. `npm test`
2. focused owner Playwright specifications
3. `npm run test:smoke`
4. `npm run build`
5. `./vendor/bin/phpunit`
6. LOC audit, `git diff --check`, and final change-scope review

## Risks and Controls

- **Scoped CSS stops applying after extraction:** move local CSS with markup or preserve global shell CSS; browser geometry tests are mandatory.
- **Reactive object identity changes:** composables receive and mutate the existing reactive form objects rather than replacing them.
- **Request races regress:** preserve existing request/session counters and add focused behaviour tests around extracted operations.
- **Payload drift:** retain exact builders and assert API payloads at the new logic boundaries.
- **Parallel file collisions:** each worker owns one original Vue file and uniquely named new files; workers do not touch shared build output, commits, or unrelated tests.
