import { defineComponent, h, nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

const plansApi = vi.hoisted(() => ({
  list: vi.fn(),
  duplicate: vi.fn(),
  update: vi.fn(),
  remove: vi.fn(),
}))
const router = vi.hoisted(() => ({ push: vi.fn() }))
const notify = vi.hoisted(() => ({
  success: vi.fn(),
  error: vi.fn(),
  warning: vi.fn(),
}))

vi.mock('@/api/index.js', () => ({ plans: plansApi }))
vi.mock('vue-router', () => ({ useRouter: () => router }))
vi.mock('element-plus', () => ({ ElMessage: notify }))

import { usePlanList } from '@/composables/plans/usePlanList.js'

function deferred() {
  let resolve
  const promise = new Promise((done) => {
    resolve = done
  })
  return { promise, resolve }
}

function mountPlanList() {
  let planList
  const wrapper = mount(defineComponent({
    setup() {
      planList = usePlanList()
      return () => h('div')
    },
  }))
  return { planList, wrapper }
}

afterEach(() => {
  vi.clearAllMocks()
  vi.useRealTimers()
})

describe('plan list composable', () => {
  it('keeps the newest list response when requests finish out of order', async () => {
    const first = deferred()
    const second = deferred()
    plansApi.list.mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise)
    const { planList, wrapper } = mountPlanList()

    planList.filters.search = 'gold'
    const newestRequest = planList.fetchPlans()
    second.resolve({
      data: [{ id: 2, title: 'Gold' }],
      total: 1,
      summary: { total: 1, active: 1 },
    })
    await newestRequest
    first.resolve({
      data: [{ id: 1, title: 'Old result' }],
      total: 99,
      summary: { total: 99, active: 99 },
    })
    await nextTick()

    expect(plansApi.list).toHaveBeenNthCalledWith(1, { page: 1, per_page: 20 })
    expect(plansApi.list).toHaveBeenNthCalledWith(2, { page: 1, per_page: 20, search: 'gold' })
    expect(planList.plans_data.value).toEqual([{ id: 2, title: 'Gold' }])
    expect(planList.total.value).toBe(1)
    expect(planList.summary.active).toBe(1)
    wrapper.unmount()
  })

  it('cancels a pending search refresh when its owner unmounts', async () => {
    vi.useFakeTimers()
    plansApi.list.mockResolvedValue({ data: [], total: 0, summary: {} })
    const { planList, wrapper } = mountPlanList()
    await nextTick()

    planList.filters.search = 'scheduled'
    planList.debouncedFetch()
    wrapper.unmount()
    await vi.advanceTimersByTimeAsync(300)

    expect(plansApi.list).toHaveBeenCalledTimes(1)
  })

  it('archives plans through the update boundary and blocks deleting plans with history', async () => {
    plansApi.list.mockResolvedValue({ data: [], total: 0, summary: {} })
    plansApi.update.mockResolvedValue({})
    const { planList, wrapper } = mountPlanList()
    await nextTick()

    await planList.handleAction('delete', { id: 7, history_count: 2 })

    expect(notify.warning).toHaveBeenCalledWith(
      'Archive plans with access history instead of deleting them',
    )
    expect(planList.deleteDialogVisible.value).toBe(false)
    expect(plansApi.remove).not.toHaveBeenCalled()

    await planList.handleAction('archive', { id: 7, history_count: 0 })

    expect(plansApi.update).toHaveBeenCalledWith(7, { status: 'archived' })
    expect(notify.success).toHaveBeenCalledWith('Plan archived successfully')
    expect(plansApi.list).toHaveBeenCalledTimes(2)
    wrapper.unmount()
  })
})
