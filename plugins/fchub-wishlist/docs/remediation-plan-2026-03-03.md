# fchub-wishlist Remediation Plan (2026-03-03)

## Objective
Close all identified implementation gaps between wishlist specification and production code, with bug-finding tests and release-ready CI/release hygiene.

## Execution Status (2026-03-03)
- [x] Phase 1: Correctness blockers completed.
- [x] Phase 2: Runtime settings enforcement completed.
- [x] Phase 3: API/performance hardening completed.
- [x] Phase 4: Integration/lifecycle wiring completed.
- [x] Phase 5: Test hardening completed.
- [x] Phase 6: CI/release/docs hygiene completed.

## Phase 1: Correctness Blockers (P0)
1. Implement real "Add All to Cart" execution (frontend cart mutations for each validated wishlist item).
2. Fix dependency boot gating to avoid partial/contradictory runtime states.
3. Fix item_count integrity hazards on add/remove/automation paths.
4. Resolve current failing test in FluentCRM trigger defaults.

Acceptance:
- Add-all actually updates cart contents.
- PHPUnit suite green.
- No count drift from failed writes in action paths.

## Phase 2: Runtime Settings Enforcement (P1)
1. Enforce `enabled` master switch across public actions and frontend hooks.
2. Enforce `guest_wishlist_enabled` in guest resolution/creation flows.
3. Use setting-backed defaults for `max_items_per_list` and `guest_cleanup_days`.
4. Apply `button_text_remove` in active-state UI text and support `icon_style` in rendered icon SVG.

Acceptance:
- Settings changes produce immediate behavioral changes (no dead settings).

## Phase 3: API and Performance Hardening (P1)
1. Normalize REST response payload shape in `ItemsController::add`.
2. Replace status endpoint per-item enrichment (N+1) with joined paginated query.
3. Implement documented filter contracts:
   - `fchub_wishlist/items_query`
   - `fchub_wishlist/item_data`
   while preserving backward compatibility (`fchub_wishlist/enriched_item`).

Acceptance:
- `/items` output is consistent and built from single joined query path.
- Filter contracts are present and exercised.

## Phase 4: Integration and Lifecycle Wiring (P1/P2)
1. Register `WishlistSettings` integration hooks in module boot flow.
2. Update activation flow to persist DB version after migrations.

Acceptance:
- Integration settings are discoverable/functional in FluentCart integration UI.
- Activation/migration path leaves version state consistent.

## Phase 5: Test Hardening (P1)
1. Add/adjust unit tests for:
   - add-all behavior contract
   - settings-backed max/guest behavior
   - joined status query path and filter execution
   - count integrity guard rails
2. Keep one deterministic bootstrap suite green with no failing tests.

Acceptance:
- Tests catch regressions in non-happy-path behaviors.

## Phase 6: CI/Release/Docs Hygiene (P2)
1. Align CI PHP version with current lock/dev tooling compatibility.
2. Add wishlist LOC budget check in CI.
3. Exclude dev scripts from distribution zip.
4. Update root README plugin + CI coverage entries.
5. Correct checklist/audit notes to match real repository state.

Acceptance:
- CI validates tests + LOC policy for wishlist.
- Release zip excludes dev-only artifacts.
- Documentation reflects actual implementation.

## Verification Matrix (must pass)
1. `./vendor/bin/phpunit --configuration phpunit.xml.dist`
2. `bash scripts/check-loc.sh`
3. `php -l` on modified PHP files
4. `./build.sh fchub-wishlist` and zip content inspection

## Non-goals in this remediation pass
1. Full browser E2E infrastructure setup.
2. Major architecture rewrites beyond required fixes.
