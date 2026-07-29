# Mobile blog tag overflow report

## Scope

The comparison blog tag row could exceed a narrow viewport because its badges
were rendered in a non-wrapping flex container. The local row now uses
`flex-wrap`; tag text and the shared `Badge` component are unchanged.

## TDD evidence

### RED

Command:

```sh
node --test --test-name-pattern='wraps comparison-blog tags on narrow viewports' scripts/check-mcp-docs-experience.test.mjs
```

Output before the source change:

```text
tests 1
pass 0
fail 1
AssertionError: The input did not match /<div className="flex flex-wrap items-center gap-1\\.5 mt-4">/
```

The failure identified the existing non-wrapping tag container.

### GREEN

Command:

```sh
node --test --test-name-pattern='wraps comparison-blog tags on narrow viewports' scripts/check-mcp-docs-experience.test.mjs
```

Output after the source change:

```text
tests 1
pass 1
fail 0
```

## Verification

```sh
node --test scripts/check-mcp-docs.test.mjs scripts/check-mcp-docs-experience.test.mjs
```

```text
tests 26
pass 26
fail 0
```

```sh
node scripts/check-mcp-docs.mjs
```

```text
All current-facing FluentCart MCP documentation matches the release contract.
```

```sh
cd web-docs && bun run lint
```

```text
Checked 66 files in 72ms. No fixes applied.
```

```sh
cd web-docs && bun run build
```

```text
Compiled successfully.
Finished TypeScript.
Generating static pages using 11 workers (221/221).
```

```sh
git diff --check
```

Output: no whitespace errors.

## Self-review

- The regression contract targets only the blog tag container and requires
  `flex-wrap`.
- The production change is one class addition, leaving badge styling and tag
  content untouched.
- No runtime, historical changelog, or FCHub Stream files were changed.
