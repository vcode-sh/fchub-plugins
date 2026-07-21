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

  it('makes plan scope and every matching plan discoverable without name recall', () => {
    const { planSelectionMode, filterPlans, togglePlanId, selectedPlanSummary } = window.fchubMembershipProtectionUI
    const plans = [
      { id: 5, label: 'Gold Membership' },
      { id: 8, label: 'Creator Pro' },
      { id: 13, label: 'Starter' },
    ]

    expect(planSelectionMode([])).toBe('any')
    expect(planSelectionMode([5])).toBe('specific')
    expect(filterPlans(plans, '')).toEqual(plans)
    expect(filterPlans(plans, 'creator')).toEqual([{ id: 8, label: 'Creator Pro' }])
    expect(togglePlanId([5], 8, true)).toEqual([5, 8])
    expect(togglePlanId([5, 8], 5, false)).toEqual([8])
    expect(selectedPlanSummary({ plans, plan_ids: [5, 8] })).toBe('Gold Membership, Creator Pro')
    expect(selectedPlanSummary({ plans, plan_ids: [] })).toBe('Any active membership plan')
  })

  it('expands the WordPress sidebar wrappers together so no empty strip remains', () => {
    const css = readFileSync(join(process.cwd(), 'assets/css/editor.css'), 'utf8')

    expect(css).toMatch(/interface-complementary-area__fill[^{]*\{[^}]*width:\s*100%\s*!important/)
    expect(css).toMatch(/fchub-protection-sidebar[^{]*\{[^}]*width:\s*100%\s*!important/)
  })

  it('lets the last specific plan be cleared so the scope can return to any plan', () => {
    const source = readFileSync(join(process.cwd(), 'assets/js/blocks.js'), 'utf8')

    expect(source).not.toContain("disabled: checked && config.plan_ids.length === 1")
    expect(source).not.toContain('Choose another plan first, or switch to any active plan.')
  })
})
