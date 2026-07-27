import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import PlanList from '@/pages/Plans/PlanList.vue'

const { listPlans, routerPush } = vi.hoisted(() => ({
  listPlans: vi.fn(),
  routerPush: vi.fn(),
}))

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: routerPush }),
}))

vi.mock('@/api/index.js', () => ({
  plans: {
    list: listPlans,
  },
}))

const RouterLinkStub = {
  props: ['to'],
  template: '<a :href="to"><slot /></a>',
}

describe('plan list action icons', () => {
  beforeEach(() => {
    listPlans.mockReset()
    routerPush.mockReset()
    listPlans.mockResolvedValue({
      data: [{
        id: 5,
        title: 'Gold Plan',
        slug: 'gold-plan',
        status: 'active',
        duration_type: 'lifetime',
        members_count: 1,
        rules_count: 1,
        history_count: 0,
      }],
      total: 1,
      summary: {
        total: 1,
        active: 1,
        needs_content: 0,
        scheduled: 0,
      },
    })
  })

  it('mounts the real plan list without unresolved action icon components', async () => {
    const warnings = []

    const wrapper = mount(PlanList, {
      global: {
        config: {
          warnHandler(message) {
            warnings.push(message)
          },
        },
        stubs: {
          RouterLink: RouterLinkStub,
        },
      },
    })

    await flushPromises()

    expect(wrapper.text()).toContain('Gold Plan')
    expect(
      warnings.filter((message) => message.includes('Failed to resolve component')),
    ).toEqual([])
  })
})
