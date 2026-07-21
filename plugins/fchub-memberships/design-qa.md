# Guided Plan Builder Design QA

## Comparison target

- Source visual truth, desktop: `/Users/tomrobak/.codex/visualizations/2026/07/20/019f811a-7c46-7870-acce-d194ec3c550d/create-plan-directions/01-guided-desktop-v2.png`
- Source visual truth, mobile Offer: `/Users/tomrobak/.codex/visualizations/2026/07/20/019f811a-7c46-7870-acce-d194ec3c550d/create-plan-directions/04-guided-mobile-revised.png`
- Source visual truth, mobile Access: `/Users/tomrobak/.codex/visualizations/2026/07/20/019f811a-7c46-7870-acce-d194ec3c550d/create-plan-directions/05-guided-mobile-content-step.png`
- Rendered implementation: `https://fchub.vcode.sh/wp-admin/admin.php?page=fchub-memberships#/plans/new`
- Desktop implementation capture: `/Users/tomrobak/.codex/visualizations/2026/07/20/019f811a-7c46-7870-acce-d194ec3c550d/guided-builder-implementation/wordpress-desktop-offer.png`
- Mobile implementation capture: `/Users/tomrobak/.codex/visualizations/2026/07/20/019f811a-7c46-7870-acce-d194ec3c550d/guided-builder-implementation/wordpress-mobile-offer-final.png`
- Mobile Access implementation capture: `/Users/tomrobak/.codex/visualizations/2026/07/20/019f811a-7c46-7870-acce-d194ec3c550d/guided-builder-implementation/wordpress-mobile-access.png`

## Viewports and states

- Desktop: 1440×1024, light theme, Create Plan, Offer step, empty plan.
- Mobile: 390×844 viewport override; browser content area rendered at 375px after the scrollbar; light theme; Create Plan Offer and Access states.
- Edit state: routed smoke application at `/plans/5/edit`, loaded plan with level 1 and edit-only management tabs.
- Validation states: empty Offer, malformed advanced slug, zero-rule Review warning, exact successful create payload, and rapid duplicate submission.

The approved boards include an ideation canvas and, in some states, realistic prefilled content. Fidelity judgement therefore uses the embedded WordPress/app screen as the target region and classifies empty implementation content as a real product state rather than visual drift.

## Full-view comparison evidence

- Desktop source and implementation in one frame: `/Users/tomrobak/.codex/visualizations/2026/07/20/019f811a-7c46-7870-acce-d194ec3c550d/guided-builder-implementation/desktop-comparison.png`
- Mobile Offer source crop and mounted implementation in one frame: `/Users/tomrobak/.codex/visualizations/2026/07/20/019f811a-7c46-7870-acce-d194ec3c550d/guided-builder-implementation/mobile-offer-comparison-final.png`
- Mobile Access source and implementation in one frame: `/Users/tomrobak/.codex/visualizations/2026/07/20/019f811a-7c46-7870-acce-d194ec3c550d/guided-builder-implementation/mobile-access-comparison.png`

## Focused region comparison evidence

- Mobile progress-card rail: `/Users/tomrobak/.codex/visualizations/2026/07/20/019f811a-7c46-7870-acce-d194ec3c550d/guided-builder-implementation/mobile-progress-focused-comparison.png`

The focused comparison was required because the user's final approval specifically conditioned the design on Readiness Canvas-style mobile step buttons. The final rail shows all three cards together, preserves `STEP 1/2/3` hierarchy, wraps Content access cleanly, and keeps the active state visibly distinct.

## Findings

No actionable P0, P1, or P2 findings remain.

- [P3] Desktop implementation is slightly denser than the conceptual board.
  - Location: Offer panel duration and availability sections.
  - Evidence: the board illustrates three duration choices and prefilled copy; the implementation preserves four production duration modes plus status and specialist controls.
  - Impact: the lower duration row may require scrolling on shorter desktop viewports, but the sticky footer keeps navigation available and no field is clipped or unreachable.
  - Classification: acceptable product-contract deviation. Removing a real duration mode to flatter the mock would be a splendid way to make a screenshot correct and the product wrong.

## Required fidelity surfaces

- Fonts and typography: both source and implementation use the existing Inter stack, compact uppercase step eyebrows, strong task headings, restrained helper copy, and appropriate mobile wrapping. No truncation or broken optical hierarchy remains.
- Spacing and layout rhythm: desktop preserves the step rail / task panel / summary proportions and bounded form width. Mobile uses a one-column flow, three compact progress cards, an in-flow summary, and a fully visible sticky action footer. Borders, 9–12px radii, and low elevation follow the existing FCHub tokens.
- Colors and visual tokens: the implementation deliberately retains FCHub's blue primary token instead of importing the Readiness Canvas amber accent. Light and dark themes both use existing semantic tokens; status, selected duration, focus, warning, and success treatments remain legible.
- Image quality and asset fidelity: the flow contains no product imagery or decorative source assets. All functional icons come from the existing Element Plus icon family; there are no emoji, handcrafted SVG substitutes, placeholder illustrations, or CSS-drawn assets.
- Copy and content: task-first headings and helper text stand alone clearly. The implementation improves the conceptual board's empty-state honesty, no-rule warning, inactive warning, duration explanations, and Review summary without leaking design-discussion language into the product.
- Icons: Back, lock, add, remove, disclosure, theme, status, and completion icons use one library and align with surrounding controls.
- Accessibility: progress controls are semantic buttons inside a labelled navigation list; current step uses `aria-current="step"`; duration cards use `aria-pressed`; all form controls retain labels; destructive controls have accessible names; disclosures expose state; keyboard activation and validation focus recovery are covered by browser tests.
- Responsive resilience: 390×844 checks prove no document-level horizontal overflow, all three progress cards fit the visible rail, the shared navigation stays within the viewport, the page header begins below it, and the sticky primary action remains inside the viewport.

## Comparison history

### Pass 1 — blocked

- [P2] The shared Memberships top navigation had no mobile breakpoint and overlaid the plan header/progress rail.
- Fix: added the existing shell's 782px breakpoint in `resources/admin/App.vue`, moved it beneath the WordPress mobile admin bar, hid desktop-only links, and corrected the content offset.
- Post-fix evidence: `wordpress-mobile-offer.png`; the brand header no longer covers the builder and the mobile shell stays within the viewport.

### Pass 2 — blocked

- [P2] The first progress-card implementation used 72vw cards, showing only one card and part of the next. That did not match the approved Readiness Canvas pattern.
- Fix: changed the mobile card basis to 104px, retained horizontal scroll as overflow protection, and added an adversarial assertion that every card is visible inside the rail at 390px.
- Post-fix evidence: `wordpress-mobile-offer-final.png` and `mobile-progress-focused-comparison.png`; all three cards are visible and correctly wrapped.

### Pass 3 — passed

- Re-captured the mounted WordPress route after the production build.
- Re-ran source/implementation composites at desktop and mobile.
- Verified Offer, Access, Review, Advanced validation recovery, edit-only tools, mobile summary, keyboard steps, exact POST payload, and duplicate-submit protection.
- Opened the final mounted route in a fresh browser tab and checked the console: zero errors.
- No actionable P0/P1/P2 mismatch remained.

## Implementation checklist

- [x] Desktop three-column Guided Builder matches the approved interaction model.
- [x] Mobile uses the approved three-card progress pattern.
- [x] Live summary, Offer, Access, Review, Advanced settings, and contextual footer are functional.
- [x] Existing payload fields and edit-only tools are preserved.
- [x] Empty, validation, responsive, keyboard, rapid-submit, and edit states are covered.
- [x] Mounted WordPress desktop and mobile states were visually inspected.
- [x] Fresh mounted-page console check reports zero errors.

final result: passed

---

## Content Protection WordPress Content Canvas Anchoring

### Comparison target

- Source state showing the defect: `/Users/tomrobak/.codex/visualizations/2026/07/21/content-protection-wizard/desktop-compact-steps-final.png`
- Final desktop implementation: `/Users/tomrobak/.codex/visualizations/2026/07/21/content-protection-wizard/desktop-content-canvas-anchored.png`
- Final mobile implementation: `/Users/tomrobak/.codex/visualizations/2026/07/21/content-protection-wizard/mobile-content-canvas-anchored.png`
- Same-viewport before/after comparison: `/Users/tomrobak/.codex/visualizations/2026/07/21/content-protection-wizard/content-canvas-before-after.png`
- Mounted implementation: `https://fchub.vcode.sh/wp-admin/admin.php?page=fchub-memberships#/content`

### Viewports and states

- Desktop: 1159×809, expanded 160px WordPress navigation, Category step, light theme.
- Compact desktop: 900×809, WordPress `auto-fold` navigation at 36px, Category step.
- Mobile: 390×844, WordPress toolbar at 46px, Category step, full-width wizard workspace.

### Findings

No actionable P0, P1, or P2 findings remain.

- Before: the fixed centring container covered x=0–1159, placing the 880px dialog at x=132–1012. The Memberships content canvas covered x=160–1144, so the dialog centre missed the content centre by 80px and entered the WordPress sidebar.
- After: the centring container covers x=160–1159 and the dialog sits at x=212–1092. Dialog and Memberships content centres both equal x=652.
- At 900px the auto-folded centring container starts at x=36 and the dialog retains at least a 16px usable gutter without document overflow.
- At 390×844 the desktop offset is removed: dialog and overlay both cover x=0–390 below the 46px toolbar; the footer ends at y=828, progress overflow is zero, and document width is exactly 390px.

### Required fidelity surfaces

- Fonts and typography: unchanged from the approved wizard; no wrapping or hierarchy regression is visible.
- Spacing and layout rhythm: the desktop dialog now belongs to the Memberships canvas, uses symmetric usable-space centring, and retains 16px minimum gutters at compact desktop widths.
- Colours and visual tokens: unchanged; the full-viewport dimmer, semantic blue selection, borders, and low elevation remain consistent.
- Image quality and asset fidelity: this positioning change introduces no assets. Existing Element Plus icons remain sharp and unchanged.
- Copy and content: unchanged; all category labels, helper copy, and actions remain visible or independently scrollable.
- Accessibility and interaction: semantic progress/category controls and focus behaviour are unchanged; only the visual centring container moved.

### Comparison history

#### Pass 1 — blocked

- [P1] The teleported Element Plus overlay centred the dialog against the complete browser viewport instead of the WordPress content canvas.
- Fix: offset the desktop centring container by the active WordPress rail width: 160px expanded, 36px folded or auto-folded, and 0px mobile.

#### Pass 2 — blocked

- [P2] At the 900px auto-fold breakpoint, the fixed 880px width consumed the complete available canvas and lost the required gutters.
- Fix: bound the dialog to `min(880px, calc(100% - 32px))` while retaining the existing full-width mobile override.

#### Pass 3 — passed

- Rebuilt and reloaded the mounted WordPress route with the final hashed stylesheet.
- Compared the before and after states in one same-viewport image and separately inspected the final mobile capture.
- Verified expanded, folded, auto-folded, and mobile geometry; no document overflow or hidden primary action remains.
- Mounted-page browser console reports zero warnings or errors.

### Implementation checklist

- [x] Expanded WordPress navigation uses a 160px content offset.
- [x] Folded and auto-folded navigation use a 36px content offset.
- [x] Compact desktop retains usable gutters and no horizontal document overflow.
- [x] Mobile remains full width below the WordPress toolbar.
- [x] Full wizard journey and frontend regression suites pass.
- [x] Mounted desktop/mobile screenshots and browser-console checks pass.

final result: passed

# Content Protection Guided Wizard Design QA

## Comparison target

- Approved target: `/Users/tomrobak/.codex/generated_images/019f811a-7c46-7870-acce-d194ec3c550d/exec-d092d9b9-05a9-4a45-91b4-17add5794086.png`
- Mounted implementation: `https://fchub.vcode.sh/wp-admin/admin.php?page=fchub-memberships#/content`
- Desktop selected-state capture: `/Users/tomrobak/.codex/visualizations/2026/07/21/content-protection-wizard/desktop-selected.png`
- Desktop access capture: `/Users/tomrobak/.codex/visualizations/2026/07/21/content-protection-wizard/desktop-access.png`
- Desktop review capture: `/Users/tomrobak/.codex/visualizations/2026/07/21/content-protection-wizard/desktop-review.png`
- Mobile category capture: `/Users/tomrobak/.codex/visualizations/2026/07/21/content-protection-wizard/mobile-category.png`
- Mobile review capture after toolbar and active-step corrections: `/Users/tomrobak/.codex/visualizations/2026/07/21/content-protection-wizard/mobile-review-final.png`
- Source and final implementation in one frame: `/Users/tomrobak/.codex/visualizations/2026/07/21/content-protection-wizard/comparison.png`

## Viewports and states

- Desktop: 1503×1257, light theme, mounted WordPress admin, Posts & Pages with Posts selected.
- Mobile: 390×844 viewport override, WordPress mobile admin bar present, Category and Review states.
- Journey: category, subtype, remote resource search, membership access, visitor experience, review, and protected-content mutation.
- Adversarial states: blank required selections, stale asynchronous search response, search failure, category change after resource selection, Escape dismissal, rapid duplicate submit, and document-level mobile overflow.

## Findings

No actionable P0, P1, or P2 findings remain.

- [P3] The mounted implementation is slightly denser than the conceptual target.
  - Location: category rows and progress copy.
  - Reason: the implementation retains production category descriptions and the WordPress admin context while preserving the approved hierarchy and interaction model.
  - Impact: none on task completion; all seven categories, inline subtype controls, summary strip, and actions remain visible or reachable.

## Required fidelity surfaces

- Typography and hierarchy: compact uppercase step labels, strong task headings, restrained helper text, and production data use the existing FCHub type scale.
- Layout: the 880px desktop workspace uses four status cards, a bounded task stage, grouped rows, and a persistent footer. Mobile becomes a full-width workspace below the WordPress toolbar with an independently scrollable task stage.
- Tokens: colours, borders, 9–12px radii, focus rings, and low elevation reuse FCHub and Element Plus semantic tokens.
- Assets: all functional icons use the existing Element Plus icon set. There are no emoji, handcrafted SVGs, placeholder images, CSS-drawn illustrations, or fake product assets.
- Interaction: earlier completed steps are editable; upcoming steps remain locked; changing category clears stale resource data; remote-search failures are visible; Escape and overlay clicks preserve unfinished work; submit is idempotent while pending.
- Accessibility: the progress rail is a labelled navigation list, category options are semantic buttons with `aria-pressed`, the active step uses `aria-current`, form controls keep programmatic labels, and all actions are keyboard-operable.
- Responsive resilience: at 390×844 there is no document-level horizontal overflow, the fixed WordPress toolbar no longer covers the dialog title, the active progress card centres automatically, the task stage scrolls independently, and the action footer remains inside the viewport.

## Comparison history

### Pass 1 — blocked

- [P1] The initial mobile full-height dialog started at viewport coordinate zero, so WordPress's fixed 46px mobile toolbar covered the dialog title and close control.
- Fix: positioned the overlay workspace below the mobile admin bar and calculated its height from the remaining dynamic viewport.

### Pass 2 — passed

- Rebuilt and reloaded the mounted WordPress route.
- Re-captured the desktop selected state, access configuration, review summary, and corrected mobile category workspace.
- Compared approved target and implementation in one composite image.
- Confirmed zero warning or error entries in the mounted-page browser console.
- Confirmed no actionable P0/P1/P2 mismatch remains.

## Implementation checklist

- [x] Four-step task-first flow matches the approved direction.
- [x] Category selection uses semantic controls and inline subtype selection.
- [x] Resource search has loading, empty, error, and stale-response protection.
- [x] Access and blocked-visitor choices are grouped and understandable.
- [x] Review is organised into Content, Access, and Visitor Experience sections.
- [x] Desktop and 390×844 mobile layouts were inspected on the mounted WordPress route.
- [x] Exact payload and duplicate-submit protection are covered by browser tests.
- [x] Mounted-page browser console reports zero warnings or errors.

final result: passed

---

## Content Protection Responsive Step Rail Refinement

### Approved direction

- Four equal compact cards, preserving step labels, descriptions, completed-step navigation, and active-step state.
- Desktop target: four equal 66px-high cards.
- Mobile target at 390×844: four equal 64px-high cards visible simultaneously with no document or rail overflow.

### Evidence

- Desktop final capture: `/Users/tomrobak/.codex/visualizations/2026/07/21/content-protection-wizard/desktop-compact-selected-target-viewport.png`
- Mobile final capture: `/Users/tomrobak/.codex/visualizations/2026/07/21/content-protection-wizard/mobile-compact-steps-final.png`
- Approved target and compact implementation in one frame: `/Users/tomrobak/.codex/visualizations/2026/07/21/content-protection-wizard/compact-steps-comparison.png`

### Measured result

- Desktop at 1487×1058: every progress card is exactly 198×66px.
- Mobile at 390×844: every progress card is exactly 77×64px; card edges run from 32px to 358px, so all four remain fully visible.
- Mobile document width is exactly 390px.
- Mounted-page browser console reports zero warnings or errors.

### Findings

No actionable P0, P1, or P2 findings remain. The compact rail retains the established FCHub tokens, icon system, focus states, semantic navigation, and completed-step behaviour.

final result: passed
