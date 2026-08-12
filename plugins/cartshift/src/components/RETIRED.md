# The retired v1 wizard — retained, not forgotten

`PreflightScreen`, `SelectScreen`, `MapScreen`, `ProgressScreen`,
`ResultsScreen` and `SubscriptionAuditScreen` are unreachable from `App.vue`,
along with everything that exists only to serve them: `MapRow`,
`MigrationReceipt`, `RetryPanel`, `ScopePicker`, `ConfirmDialog`, the five
`log/` components, and the `useMigration`, `useMapping`, `useLogViewer`,
`useSubscriptionAudit` and `usePolling` composables. Roughly 5,600 lines.
Vite transforms 15 modules into the shipped bundle, so none of it reaches a
browser.

**They are kept, deliberately, and this file is the decision rather than an
oversight.** The question had been left open twice.

## Why they are not deleted

Their subject is not gone; it is *superseded*. Every write route they call
answers `410` through `LegacyCommandPolicy`, and their model — one WordPress,
one shot, browser-driven writes — was replaced by the v2 transfer contract.
Deleting them would be tidy and would destroy the only worked example of the
screens the guided route still needs: `MapScreen` and `MapRow` are the product
mapping surface, and the guided route's review step needs exactly that shape
for the two decisions the shop cannot answer for itself — product mapping and
order-note visibility.

FChub Stream is retained on the same reasoning and says so in the shared
contract. This is that precedent, applied.

## What is true about them

- They are not shipped. The bundle does not contain them.
- They cannot write. Every route behind them is a classified refusal.
- Their tests still run, and are not evidence that the wizard works — only that
  these components still behave as they did when they were mounted.

## When to delete them

When the guided review step is built and no longer needs them as reference.
Delete the components and their tests in the same change: a test kept alive
past its subject is maintenance cost carrying no signal.
