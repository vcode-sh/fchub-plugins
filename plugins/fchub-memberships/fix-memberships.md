# fix-memberships.md — Modernisation Plan

The plugin works. It's feature-complete, architecturally sound, and has zero TODOs.
It also writes PHP like it's 2021. Time to fix that.

**Target**: PHP 8.3+, PER CS 3.0, no behavioural changes.
**Rule**: Every phase must leave the plugin in a working state. No big-bang rewrites.

---

## Phase 1 — Strict Types & Type Safety

The single highest-impact change. Zero new features, zero refactors — just tell PHP to actually check types.

### 1.1 Add `declare(strict_types=1)` to all files

Every `.php` file in `app/` and `tests/` gets `declare(strict_types=1);` as the first statement after the opening tag. All 133 source files + 25 test files.

```php
<?php

declare(strict_types=1);

namespace FChubMemberships\...;
```

**Risk**: Medium. Implicit type coercions that silently worked will now throw `TypeError`. Especially dangerous around `$wpdb` results (returns strings for int columns) and FluentCart data (array values from JSON decode).

**Mitigation**: Run full test suite after each directory. Fix type coercions by adding explicit `(int)`, `(string)` casts where `$wpdb` returns string-typed integers.

**Order** (least risky first):
1. `app/Support/` (6 files) — static utilities, least coupled
2. `app/Storage/` (8 files) — repositories, will surface `$wpdb` string→int issues
3. `app/Adapters/` (5 files)
4. `app/Domain/` (16 files) — core business logic
5. `app/Email/` (8 files)
6. `app/Http/` (8 files)
7. `app/Integration/` (5 files)
8. `app/FluentCRM/` (36 files) — parent classes have untyped signatures
9. `app/Reports/` (4 files)
10. `app/Frontend/` (3 files)
11. `app/CLI/` (1 file)
12. `tests/` (25 files)

### 1.2 Replace loose `==` with `===`

17 instances, mostly in FluentCRM triggers:

- All 15 trigger classes: `Arr::get($conditions, 'run_multiple') == 'yes'` → `=== 'yes'`
- `MembershipGrantedTrigger.php:147`: `$updateType == 'skip_all_if_exist'` → `===`
- `SubscriptionValidityWatcher.php:237`: `$g['plan_id'] == $planId` → `===` (after ensuring both are `int`)

### 1.3 Standardise date functions

Kill all bare `date()` calls — they use the server timezone, not WordPress's.

| File | Line(s) | Current | Fix |
|------|---------|---------|-----|
| `AccessGrantService.php` | 61, 137, 664 | `date('Y-m-d H:i:s')` | `current_time('mysql', true)` |
| `MembershipAccessIntegration.php` | 328, 340 | `date('Y-m-d H:i:s')` | `current_time('mysql', true)` |
| `GenericCsvParser.php` | 68 | `date('Y-m-d H:i:s')` | `current_time('mysql', true)` |
| `PmproCsvParser.php` | 65 | `date('Y-m-d H:i:s')` | `current_time('mysql', true)` |
| `GrantCommand.php` | 192 | `date('Y-m-d H:i:s')` | `current_time('mysql', true)` |

Decide on a convention: if timestamps are stored in UTC (they should be), use `gmdate()`. If they follow WP timezone, use `current_time('mysql')`. Pick one, document it, apply it everywhere.

---

## Phase 2 — Enums & Constants

### 2.1 Convert `Constants` class to backed enums

`app/Support/Constants.php` → split into 4 enum files in `app/Support/Enums/`:

```php
// app/Support/Enums/GrantStatus.php
declare(strict_types=1);

namespace FChubMemberships\Support\Enums;

enum GrantStatus: string
{
    case Active  = 'active';
    case Paused  = 'paused';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
```

```php
// app/Support/Enums/Provider.php
enum Provider: string
{
    case WordPressCore    = 'wordpress_core';
    case LearnDash        = 'learndash';
    case FluentCommunity  = 'fluent_community';
}
```

```php
// app/Support/Enums/DripType.php
enum DripType: string
{
    case Immediate = 'immediate';
    case Delayed   = 'delayed';
    case FixedDate = 'fixed_date';
}
```

```php
// app/Support/Enums/RestrictionContext.php
enum RestrictionContext: string
{
    case LoggedOut        = 'logged_out';
    case NoAccess         = 'no_access';
    case Expired          = 'expired';
    case DripLocked       = 'drip_locked';
    case MembershipPaused = 'membership_paused';
}
```

**Keep `Constants.php`** temporarily as a facade with deprecated constants pointing to enum values, so any third-party code referencing `Constants::STATUS_ACTIVE` doesn't break immediately. Remove after one minor version.

### 2.2 Integrate enums into StatusTransitionValidator

```php
// Before
private const TRANSITIONS = [
    'active'  => ['expired', 'revoked', 'paused'],
    ...
];

// After
private const TRANSITIONS = [
    GrantStatus::Active  => [GrantStatus::Expired, GrantStatus::Revoked, GrantStatus::Paused],
    GrantStatus::Paused  => [GrantStatus::Active, GrantStatus::Revoked],
    GrantStatus::Expired => [GrantStatus::Active],
    GrantStatus::Revoked => [GrantStatus::Active],
];

public static function isValid(GrantStatus $from, GrantStatus $to): bool
```

### 2.3 Add visibility to remaining constants

All 25 constants in the deprecated `Constants.php` facade get explicit `public const`. The 11 files already using `private const` are fine.

### 2.4 Protection mode enum

```php
// app/Support/Enums/ProtectionMode.php
enum ProtectionMode: string
{
    case Explicit = 'explicit';
    case Redirect = 'redirect';
}
```

Remove `ALLOWED_PROTECTION_MODES` array — enum has `cases()` for that.

---

## Phase 3 — Modern PHP Syntax

### 3.1 Replace `strpos()` with `str_contains()` / `str_starts_with()`

13 occurrences across 4 files:

| Pattern | Replacement |
|---------|-------------|
| `strpos($x, 'y') !== false` | `str_contains($x, 'y')` |
| `strpos($x, 'y') === 0` | `str_starts_with($x, 'y')` |
| `strpos($x, 'y') === false` | `!str_contains($x, 'y')` |

Files: `WordPressContentAdapter.php`, `ContentProtection.php`, `MembershipPausedTrigger.php`, `AccountPage.php`.

### 3.2 `readonly` properties

Add `readonly` to properties that are set once in the constructor and never mutated:

- All 8 repository classes: `private readonly string $table`
- Service class dependencies: `private readonly GrantRepository $grantRepo` etc.
- Report classes: `private readonly string $grantsTable`, `private readonly string $statsTable`

### 3.3 Constructor promotion (services only)

Skip repositories (they use `global $wpdb` in constructor body — not promotable).
Apply to service classes that instantiate dependencies:

```php
// Before (AccessGrantService)
private GrantRepository $grantRepo;
private GrantSourceRepository $sourceRepo;
private PlanRuleResolver $ruleResolver;
private DripScheduleRepository $dripRepo;
private EventLockRepository $lockRepo;

public function __construct()
{
    $this->grantRepo = new GrantRepository();
    $this->sourceRepo = new GrantSourceRepository();
    $this->ruleResolver = new PlanRuleResolver();
    $this->dripRepo = new DripScheduleRepository();
    $this->lockRepo = new EventLockRepository();
}

// After
public function __construct(
    private readonly GrantRepository $grantRepo = new GrantRepository(),
    private readonly GrantSourceRepository $sourceRepo = new GrantSourceRepository(),
    private readonly PlanRuleResolver $ruleResolver = new PlanRuleResolver(),
    private readonly DripScheduleRepository $dripRepo = new DripScheduleRepository(),
    private readonly EventLockRepository $lockRepo = new EventLockRepository(),
) {}
```

This also opens the door to injecting mocks in tests without the 939-line bootstrap.

**Applies to**: `AccessGrantService`, `PlanService`, `TrialLifecycleService`, `DripScheduleService`, `ImportService`, `AuditLogger`, `AccessEvaluator`, `WebhookDispatcher`, `FluentCrmSync`, `FluentCommunitySync`, report classes.

### 3.4 `final` keyword on utility classes

Mark as `final`:
- `Constants` (deprecated facade)
- `StatusTransitionValidator`
- `Logger`
- `Migrations`, `MigrationV2`, `MigrationV3`
- `AdminMenu`
- `ResourceTypeRegistry`

### 3.5 `match` expressions

Replace `switch` statements and if-else chains with `match` where the pattern fits. Only where it genuinely improves readability — don't force it.

---

## Phase 4 — Return Types & Parameter Types

### 4.1 Add return type declarations

~40 methods missing return types. Split by constraint:

**Can type freely** (own methods):
- `MembershipSettings::get()` → `mixed`
- `ContentProtection::filterRestContent()` → `array`
- `StatusTransitionValidator::getAllStatuses()` → `array` (already typed, but verify all)
- All report methods, controller methods, service methods

**Constrained by parent class** (FluentCRM/FluentCart):
- 15 trigger classes: `getTrigger()`, `getSettingsFields()`, `getFunnelSettingsDefaults()`, `handle()`, `getConditionFields()`, `getFunnelConditionDefaults()`, `getBlockFields()`, `getBlock()`
- 7 action classes: `handle()`, `getBlock()`, `getBlockFields()`
- 7 benchmark classes: similar set

For FluentCRM parent-constrained methods: add return types **only if** the parent class declares them. If the parent is untyped, add `@return` PHPDoc instead. Don't break Liskov.

### 4.2 Parameter types on own methods

- `Logger::orderLog($order, ...)` → `Logger::orderLog(object $order, ...)`
- `ContentProtection::filterRestContent($content, $handler, $request)` → add types

### 4.3 PHPDoc for complex return shapes

Where methods return associative arrays, add PHPStan-compatible `@return` shapes:

```php
/**
 * @return array{success: bool, message: string, grant_id?: int}
 */
```

Already partially done — extend to all public service methods.

---

## Phase 5 — Code Deduplication

### 5.1 Extract `parsePeriod()` utility

Create `app/Support/DateRange.php`:

```php
declare(strict_types=1);

namespace FChubMemberships\Support;

final class DateRange
{
    /**
     * Parse a period string like '30d', '6m', '1y' into start/end dates.
     *
     * @return array{start: string, end: string}
     */
    public static function fromPeriod(string $period): array
    {
        // extracted logic from the 4 duplicated methods
    }
}
```

Replace duplicates in:
- `ChurnReport::parsePeriod()`
- `MemberStatsReport::parsePeriod()`
- `RevenueReport::parsePeriod()`
- `GrantCommand::parsePeriod()`

### 5.2 Remove duplicated `hydrateRow()` from `TrialLifecycleService`

`TrialLifecycleService::hydrateRow()` (line ~231) duplicates `GrantRepository::hydrate()`. Replace with a call to `GrantRepository::hydrate()` (make it `public static` if needed, or inject the repo).

---

## Phase 6 — Tooling

### 6.1 Add `phpcs.xml` with PER CS 3.0

```xml
<?xml version="1.0"?>
<ruleset name="FCHub Memberships">
    <description>PER Coding Style 3.0</description>

    <file>app</file>
    <file>tests</file>

    <arg name="extensions" value="php"/>
    <arg name="colors"/>
    <arg value="sp"/>

    <rule ref="PER-CS2.0"/>

    <!-- WordPress-specific exclusions -->
    <exclude-pattern>*/index.php</exclude-pattern>
</ruleset>
```

Add to `composer.json`:

```json
"require-dev": {
    "phpunit/phpunit": "^13.0",
    "squizlabs/php_codesniffer": "^3.10",
    "phpstan/phpstan": "^2.0"
}
```

### 6.2 Add `phpstan.neon` at level 6

```neon
parameters:
    level: 6
    paths:
        - app
    excludePaths:
        - app/*/index.php
    ignoreErrors:
        # WordPress globals and dynamic functions
        - '#Function fluent_cart_\w+ not found#'
        - '#Function fluentcrm_\w+ not found#'
```

Start at level 6. Fix errors. Then bump to 7, then 8.

### 6.3 Add composer scripts

```json
"scripts": {
    "test": "phpunit",
    "phpcs": "phpcs",
    "phpcbf": "phpcbf",
    "phpstan": "phpstan analyse",
    "check": ["@phpcs", "@phpstan", "@test"]
}
```

---

## Phase 7 — Test Improvements (Optional)

Not blocking release, but important for long-term maintainability.

### 7.1 Constructor promotion enables proper mocking

Once services use constructor promotion with default values (Phase 3.3), tests can inject mocks directly:

```php
$service = new AccessGrantService(
    grantRepo: $this->createMock(GrantRepository::class),
    sourceRepo: $this->createMock(GrantSourceRepository::class),
);
```

This makes the 939-line `tests/bootstrap.php` progressively redundant.

### 7.2 Priority test targets

If writing new tests, focus on the untested critical paths:
1. `AccessGrantService` — grant/revoke/pause/resume lifecycle
2. `SubscriptionValidityWatcher` — subscription→grant status sync
3. `TrialLifecycleService` — trial start/convert/expire
4. `DripScheduleService` — drip evaluation and unlock

---

## Execution Order

| Phase | Effort | Risk | Can Ship After? |
|-------|--------|------|-----------------|
| 1.2 — `==` → `===` | 15 min | Low | Yes |
| 1.3 — Date functions | 30 min | Low | Yes |
| 3.1 — `strpos` → `str_contains` | 15 min | Low | Yes |
| 2.3 — Const visibility | 10 min | None | Yes |
| 3.4 — `final` classes | 10 min | None | Yes |
| 1.1 — `declare(strict_types=1)` | 2-3 hrs | Medium | Yes |
| 3.2 — `readonly` properties | 1 hr | Low | Yes |
| 2.1-2.4 — Enums | 2-3 hrs | Medium | Yes |
| 3.3 — Constructor promotion | 1-2 hrs | Low | Yes |
| 4.x — Return/param types | 2 hrs | Low | Yes |
| 5.x — Deduplication | 30 min | Low | Yes |
| 6.x — Tooling (phpcs/phpstan) | 1-2 hrs | None | Yes |
| 7.x — Tests | 4-6 hrs | None | Yes |

Total: ~15-20 hours of focused work. Each phase is independently shippable.

---

## What This Plan Does NOT Do

- No architectural refactoring (the architecture is fine)
- No new features
- No dependency injection container (constructor promotion with defaults is enough)
- No value objects/DTOs (nice-to-have, not worth the churn right now)
- No custom exception hierarchy (low ROI for a WordPress plugin)
- No changes to the Vue admin app
- No database changes
