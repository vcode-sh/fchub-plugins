import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import WorkspacePageHeader from '@/components/workspace/WorkspacePageHeader.vue'

const RouterLinkStub = {
  props: ['to'],
  template: '<a :href="to"><slot /></a>',
}

describe('workspace page header', () => {
  it('owns accessible back navigation beside the title block', () => {
    const wrapper = mount(WorkspacePageHeader, {
      props: {
        eyebrow: 'Memberships',
        title: 'Edit membership plan',
        description: 'Build the offer.',
        backTo: '/plans',
        backLabel: 'Back to plans',
      },
      global: {
        components: { RouterLink: RouterLinkStub },
      },
    })

    const back = wrapper.get('.workspace-back-button')
    expect(back.attributes('href')).toBe('/plans')
    expect(back.attributes('aria-label')).toBe('Back to plans')
    expect(back.attributes('title')).toBe('Back to plans')
    expect(wrapper.get('.workspace-page-title-row h1').text()).toBe('Edit membership plan')
  })

  it('does not reserve back-button space on overview pages', () => {
    const wrapper = mount(WorkspacePageHeader, {
      props: { title: 'Plans' },
      global: {
        components: { RouterLink: RouterLinkStub },
      },
    })

    expect(wrapper.find('.workspace-back-button').exists()).toBe(false)
    expect(wrapper.get('h1').text()).toBe('Plans')
  })
})
