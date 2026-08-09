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

/**
 * The stylesheet with its comments removed.
 *
 * Selector parsing needs this. A CSS comment sits between the previous rule's
 * `}` and the next selector, so any regex that captures "everything up to the
 * next `{`" swallows it — and a comment that happens to mention `#cartshift-app`
 * (they all do, explaining why the rule is anchored) makes the rule beneath it
 * look anchored when it is not. That is how the dark-mode check below passed
 * over the exact regression it was written for.
 */
const cssBare = css.replace(/\/\*[\s\S]*?\*\//g, '');

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

describe('paragraph styling outranks the body-paragraph rule', () => {
  /**
   * `#cartshift-app p` sets a colour and a margin, and WP admin CSS is
   * unlayered — so a bare class selector on a `<p>` loses to it on specificity
   * whatever it declares. That is how `.cartshift-audit-warn` and the
   * reconciliation alarm ending "do not stage this cohort" came to render in
   * the same grey as the body text around them.
   *
   * Every class that lands on a `<p>` and then declares `color` or `margin`
   * must therefore carry the `#cartshift-app` prefix, which is what the file's
   * own convention already does three rules away.
   */
  const paragraphClasses = new Set();

  for (const file of componentFiles()) {
    const source = readFileSync(file, 'utf8');

    for (const [, classes] of source.matchAll(/<p\b[^>]*\bclass="([^"]+)"/g)) {
      for (const name of classes.split(/\s+/)) {
        if (name.startsWith('cartshift-')) paragraphClasses.add(name);
      }
    }
  }

  it('finds the paragraph classes it is meant to be checking', () => {
    expect(paragraphClasses.size).toBeGreaterThan(0);
  });

  it.each([...paragraphClasses])('.%s is anchored on #cartshift-app', (name) => {
    const pattern = new RegExp(
      `(?:^|[},])\\s*([^{}]*\\.${name}(?![a-zA-Z0-9_-])[^{}]*)\\{([^}]*)\\}`,
      'g',
    );

    for (const [, selectors, body] of cssBare.matchAll(pattern)) {
      if (!/(?:^|;)\s*(?:color|margin)\s*:/.test(body)) continue;

      expect(
        selectors.includes('#cartshift-app'),
        `"${selectors.trim()}" sets a colour or margin on a <p> and would lose to "#cartshift-app p"`,
      ).toBe(true);
    }
  });
});

describe('dark-mode overrides outrank their light rules', () => {
  /**
   * An ID beats any number of classes. So the moment a light rule is anchored
   * on `#cartshift-app` (1,1,0), a `body.dark .thing` override (0,2,1) is dead
   * and the element renders its light colours on a dark background — which is
   * exactly what happened to the log's active-filter banner when this wave
   * prefixed its light rule.
   *
   * Every dark override for a class that has an ID-anchored light rule must
   * therefore carry the ID too, the form `body.dark #cartshift-app .notice-*`
   * already uses.
   */
  it('anchors every dark override whose light rule carries the app ID', () => {
    const anchored = new Set();
    const darkBare = new Map();

    for (const [, selectors] of cssBare.matchAll(/([^{}]+)\{[^{}]*\}/g)) {
      for (const selector of selectors.split(',')) {
        const trimmed = selector.trim();
        if (!trimmed.startsWith('body.dark') && trimmed.includes('#cartshift-app')) {
          for (const [, name] of trimmed.matchAll(/\.([a-zA-Z0-9_-]+)/g)) anchored.add(name);
        }
      }
    }

    for (const [, selectors] of cssBare.matchAll(/([^{}]+)\{[^{}]*\}/g)) {
      for (const selector of selectors.split(',')) {
        const trimmed = selector.trim();
        if (!trimmed.startsWith('body.dark') || trimmed.includes('#cartshift-app')) continue;
        for (const [, name] of trimmed.matchAll(/\.([a-zA-Z0-9_-]+)/g)) {
          if (name !== 'dark' && anchored.has(name)) darkBare.set(name, trimmed);
        }
      }
    }

    expect(
      [...darkBare.entries()].map(([name, selector]) => `${selector} (.${name} has an ID-anchored light rule)`),
      'these dark overrides lose to their own light rule on specificity',
    ).toEqual([]);
  });
});
