import { describe, it, expect } from 'vitest';
import { readdirSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * Layout contracts that only the stylesheet can answer.
 *
 * happy-dom does not do layout, so there is no point mounting a component and
 * measuring it — every width would come back 0. These assertions read the CSS
 * as text instead, which is enough to catch the two regressions that actually
 * shipped: a full-bleed wrapper that overflowed the viewport, and data tables
 * whose columns were sized by whatever text happened to land in them.
 */

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const css = readFileSync(resolve(root, 'src/styles/app.css'), 'utf8');

/** Body of the first rule whose selector is exactly `selector`. */
function ruleBody(selector, source = css) {
  const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const match = source.match(new RegExp(`(?:^|[},])\\s*${escaped}\\s*\\{([^}]*)\\}`));
  return match ? match[1] : null;
}

/** Expand a `margin` shorthand into {top, right, bottom, left}. */
function expandMargin(shorthand) {
  const p = shorthand.trim().split(/\s+/);
  const [top, right = top, bottom = top, left = right] = p;
  return { top, right, bottom, left };
}

/** Every class name mentioned by a selector that sets `table-layout: fixed`. */
function fixedLayoutClasses(source = css) {
  const classes = new Set();
  for (const [, selectors] of source.matchAll(/([^{}]+)\{[^{}]*table-layout:\s*fixed[^{}]*\}/g)) {
    for (const [, name] of selectors.matchAll(/\.([a-zA-Z0-9_-]+)/g)) {
      classes.add(name);
    }
  }
  return classes;
}

/** Every .vue file under src/components, recursively. */
function componentFiles(dir = resolve(root, 'src/components')) {
  return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const path = resolve(dir, entry.name);
    if (entry.isDirectory()) return componentFiles(path);
    return entry.name.endsWith('.vue') ? [path] : [];
  });
}

describe('page wrapper', () => {
  it('does not pull horizontally against ancestor padding', () => {
    const body = ruleBody('.cartshift-page-wrap');
    expect(body).not.toBeNull();

    const declaration = body.match(/(?:^|;)\s*margin:\s*([^;]+)/);
    expect(declaration, 'expected a margin shorthand on .cartshift-page-wrap').not.toBeNull();

    const { left, right } = expandMargin(declaration[1]);

    // A negative horizontal margin here means the wrapper is trying to cancel
    // padding on an element WP owns. It gets that arithmetic wrong at some
    // breakpoint every time — #wpcontent pads 20px left, 0 right, and drops to
    // 10px left under 782px. The background belongs on #wpcontent instead.
    expect(left.startsWith('-'), `margin-left ${left} overflows the viewport`).toBe(false);
    expect(right.startsWith('-'), `margin-right ${right} overflows the viewport`).toBe(false);
  });

  it('paints the WP content area so the wrapper still reads as full-bleed', () => {
    expect(css).toMatch(/body\.tools_page_cartshift-migrator\s+#wpcontent/);
  });
});

describe('data table column geometry', () => {
  const fixed = fixedLayoutClasses();

  it('declares at least one fixed-layout table', () => {
    expect(fixed.size).toBeGreaterThan(0);
  });

  it.each(componentFiles().map((path) => [path.slice(root.length + 1), path]))(
    '%s gives every widefat table a fixed-layout class',
    (label, path) => {
      const source = readFileSync(path, 'utf8');
      const tables = [...source.matchAll(/<table[^>]*\sclass="([^"]*)"/g)]
        .map(([, classes]) => classes)
        .filter((classes) => classes.split(/\s+/).includes('widefat'));

      for (const classes of tables) {
        const names = classes.split(/\s+/);
        // Without one of these, the browser falls back to auto layout and sizes
        // columns from content — which is how two identical Entity/Count tables
        // ended up 597/363 and 611/349 on the same screen.
        expect(
          names.some((name) => fixed.has(name)),
          `<table class="${classes}"> in ${label} has no fixed-layout class; `
            + `add one and give its columns widths in app.css`,
        ).toBe(true);
      }
    },
  );

  it('sets column widths on thead cells, where fixed layout reads them', () => {
    for (const name of fixed) {
      const widths = [...css.matchAll(new RegExp(`\\.${name}\\s+th:nth-child\\([^)]+\\)\\s*\\{[^}]*width:`, 'g'))];
      expect(widths.length, `.${name} is fixed-layout but declares no th widths`).toBeGreaterThan(0);
    }
  });
});

describe('numeric columns', () => {
  it('right-aligns with tabular figures', () => {
    // One rule carries the whole numeric column contract; assert it survives
    // rather than asserting each table's nth-child list separately.
    const body = ruleBody('#cartshift-app .cartshift-num');
    const block = body ?? css.match(/#cartshift-app \.cartshift-num[^{]*\{([^}]*)\}/)?.[1];

    expect(block, 'expected a numeric-column rule anchored on .cartshift-num').toBeTruthy();
    expect(block).toMatch(/text-align:\s*right/);
    expect(block).toMatch(/font-variant-numeric:\s*tabular-nums/);
  });
});
