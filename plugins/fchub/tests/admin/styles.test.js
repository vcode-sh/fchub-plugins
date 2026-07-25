import { readFileSync, readdirSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * FCHub has two blues, and which one a declaration may use is a contrast rule,
 * not a preference:
 *
 *   --fchub-primary         #4D6EF5   borders, focus rings, the brand mark.
 *                                     Non-text UI at WCAG 1.4.11's 3:1 bar.
 *   --fchub-primary-strong  #3A5BE0   text, and anything with white on top.
 *                                     5.55:1 on white, clearing the 4.5:1 bar.
 *
 * The split was introduced for the primary button and then quietly missed three
 * other sites — a link colour, a link hover/focus colour, and a disabled-hover
 * fill. Every one of them was a declaration that paints text or sits under
 * white. This test reads the sources and fails if that happens again.
 */

// process.cwd(), not import.meta.url: under the jsdom environment the module
// URL is an http:// one and .pathname resolves to nonsense. Vitest runs from
// the package root, which is the same assumption vite.config.js makes.
const ROOT = join(process.cwd(), 'resources/admin')

/**
 * Properties that either paint text or end up with white text on top.
 *
 * The border alternative is spelled out per side rather than left as
 * `border-color`, because the first version of this guard required a space or a
 * brace immediately before the property name — which quietly excluded every
 * `border-bottom-color`, the exact declaration `.fchub-link`'s underline uses.
 * The longest alternatives come first so `color` cannot claim the tail of one
 * of them.
 */
const TEXT_OR_FILL =
  /(^|[\s;{])(border(?:-(?:top|right|bottom|left|block|inline))?-color|background(?:-color|-image)?|outline-color|text-decoration-color|color|fill|stroke)\s*:\s*var\(--fchub-primary\)/g

/** The lighter blue, written out. Legal in exactly one file. Unflagged on
    purpose: a /g/ regex carries lastIndex between .test() calls. */
const LITERAL = /#4D6EF5/i

/**
 * Comments, blanked to spaces rather than deleted, so every index into the
 * source still points where it did. Both guards below read declarations; a
 * paragraph explaining the rule is not a declaration, and global.css contains
 * several that quote the hex.
 */
function withoutComments(source) {
  return source.replace(/\/\*[\s\S]*?\*\//g, (block) => block.replace(/[^\n]/g, ' '))
}

/**
 * The one legitimate exception, named rather than pattern-matched so adding a
 * second one has to be a deliberate act with a reason attached.
 *
 * `.fchub-header__mark` colours the FCHub logo through currentColor. A brand
 * mark is a non-text graphical object — 3.89:1 against the page background,
 * clearing the 3:1 bar — and WCAG exempts logos from contrast requirements
 * outright. The <h1> beside it sets its own --fchub-text-primary.
 */
const ALLOWED = [{ file: 'App.vue', selector: '.fchub-header__mark' }]

function sources(directory = ROOT) {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = join(directory, entry.name)

    if (entry.isDirectory()) {
      return sources(path)
    }

    return /\.(vue|css)$/.test(entry.name) ? [path] : []
  })
}

/** The selector a declaration sits under — the last `{` before the match. */
function selectorAbove(contents, index) {
  const opened = contents.lastIndexOf('{', index)
  const previous = Math.max(
    contents.lastIndexOf('}', opened),
    contents.lastIndexOf('{', opened - 1),
    contents.lastIndexOf('*/', opened),
  )

  return contents.slice(previous + 1, opened).trim().split('\n').pop().trim()
}

function offenders() {
  return sources().flatMap((path) => {
    const contents = withoutComments(readFileSync(path, 'utf8'))
    const file = path.split('/').pop()

    return [...contents.matchAll(TEXT_OR_FILL)].map((match) => ({
      file,
      property: match[2],
      selector: selectorAbove(contents, match.index),
    }))
  })
}

describe('the two blues', () => {
  it('never paints text or a white-backed fill with --fchub-primary', () => {
    const unexpected = offenders().filter(
      (found) =>
        !ALLOWED.some(
          (exception) => exception.file === found.file && exception.selector === found.selector,
        ),
    )

    expect(
      unexpected,
      'Text and white-on-blue fills must use --fchub-primary-strong (#3A5BE0). ' +
        '--fchub-primary (#4D6EF5) is 4.32:1 on white and is for borders, focus rings ' +
        'and the brand mark only. See the rule at the top of styles/global.css.',
    ).toEqual([])
  })

  it('catches the declarations the first version of this guard walked straight past', () => {
    // Fresh instances, because TEXT_OR_FILL is /g/ and .test() would carry
    // lastIndex from one string to the next.
    const matches = (declaration) => new RegExp(TEXT_OR_FILL.source).test(declaration)

    for (const declaration of [
      'a { border-bottom-color: var(--fchub-primary); }',
      'a { border-color: var(--fchub-primary); }',
      'a { outline-color: var(--fchub-primary); }',
      'a { text-decoration-color: var(--fchub-primary); }',
      'a { background-image: var(--fchub-primary); }',
      'svg { fill: var(--fchub-primary); }',
      'svg { stroke: var(--fchub-primary); }',
      'a { color: var(--fchub-primary); }',
    ]) {
      expect(matches(declaration), `${declaration} should be caught`).toBe(true)
    }

    // The focus-ring shorthand is the token's whole remaining job, and a guard
    // that flagged it would be a guard nobody could satisfy.
    expect(matches('a:focus-visible { outline: 2px solid var(--fchub-primary); }')).toBe(false)
    expect(matches('a { border: 1px solid var(--fchub-primary); }')).toBe(false)
    expect(matches(':root { --el-color-primary: var(--fchub-primary); }')).toBe(false)
  })

  it('still allows --fchub-primary for focus rings, which is the whole point of keeping it', () => {
    const outlines = sources()
      .map((path) => readFileSync(path, 'utf8'))
      .join('\n')
      .match(/outline:\s*2px solid var\(--fchub-primary\)/g)

    // If this ever drops to nothing, somebody has replaced the token
    // wholesale rather than applying the split, and the rationale comment in
    // global.css has quietly become fiction.
    expect(outlines?.length).toBeGreaterThan(4)
  })

  it('never writes the lighter blue out by hand, which would dodge the rule entirely', () => {
    const written = sources()
      .filter((path) => !path.endsWith('styles/variables.css'))
      .filter((path) => LITERAL.test(withoutComments(readFileSync(path, 'utf8'))))
      .map((path) => path.split('/').pop())

    expect(
      written,
      '#4D6EF5 belongs in variables.css and nowhere else. A hardcoded copy is invisible ' +
        'to the var(--fchub-primary) guard above and would reintroduce the contrast bug ' +
        'the two-blue split exists to prevent.',
    ).toEqual([])
  })

  it('defines both tokens exactly once, at the agreed values', () => {
    const variables = readFileSync(join(ROOT, 'styles/variables.css'), 'utf8')

    expect(variables).toContain('--fchub-primary: #4D6EF5;')
    expect(variables).toContain('--fchub-primary-strong: #3A5BE0;')
    expect(variables).toContain('--el-color-primary: var(--fchub-primary);')
  })
})
