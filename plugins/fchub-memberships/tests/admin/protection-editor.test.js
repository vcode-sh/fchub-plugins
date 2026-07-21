import { readFileSync } from 'node:fs'
import { join } from 'node:path'

describe('membership protection editor plugin', () => {
  beforeEach(() => {
    const registeredPlugins = []
    window.wp = {
      blocks: { registerBlockType: vi.fn() },
      element: {
        createElement: vi.fn((type, props, ...children) => ({ type, props: props || {}, children })),
        Fragment: 'Fragment',
      },
      blockEditor: { InnerBlocks: { Content: 'InnerBlocks.Content' }, InspectorControls: 'InspectorControls' },
      components: {
        PanelBody: 'PanelBody',
        TextControl: 'TextControl',
        TextareaControl: 'TextareaControl',
        SelectControl: 'SelectControl',
      },
      i18n: { __: (value) => value, sprintf: (pattern, value) => pattern.replace('%s', value) },
      plugins: { registerPlugin: vi.fn((name, settings) => registeredPlugins.push({ name, settings })) },
      data: { useSelect: vi.fn(), useDispatch: vi.fn() },
      editor: {},
    }
    window.__registeredMembershipPlugins = registeredPlugins

    const source = readFileSync(join(process.cwd(), 'assets/js/blocks.js'), 'utf8')
    new Function('window', source)(window)
  })

  it('registers a native document panel and dedicated sidebar plugin', () => {
    expect(window.wp.plugins.registerPlugin).toHaveBeenCalledWith(
      'fchub-membership-protection',
      expect.objectContaining({ icon: 'lock', render: expect.any(Function) }),
    )
  })

  it('requires a complete CTA pair and accepts relative destinations', () => {
    const { validateCta } = window.fchubMembershipProtectionUI

    expect(validateCta('', '')).toBe('')
    expect(validateCta('Get access', '/pricing')).toBe('')
    expect(validateCta('Get access', '')).toContain('both')
    expect(validateCta('', '/pricing')).toContain('both')
  })

  it('describes direct, inherited, and public effective states precisely', () => {
    const { statusLabel } = window.fchubMembershipProtectionUI

    expect(statusLabel({ protected: false, mode: 'public' })).toBe('Public')
    expect(statusLabel({ protected: true, mode: 'direct' })).toBe('Protected directly')
    expect(statusLabel({ protected: true, mode: 'inherited' })).toBe('Protected by inherited rules')
    expect(statusLabel({ protected: true, mode: 'mixed' })).toBe('Protected by direct and inherited rules')
  })
})
