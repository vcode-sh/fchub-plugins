import { describe, expect, it, vi } from 'vitest'
import { usePlanMembers } from '@/composables/plans/usePlanMembers.js'
import { usePlanProducts } from '@/composables/plans/usePlanProducts.js'
import { usePlanSchedule } from '@/composables/plans/usePlanSchedule.js'

function messages() {
  return { success: vi.fn(), error: vi.fn() }
}

describe('plan products', () => {
  it('loads once, reports failures truthfully, and permits retry', async () => {
    const linkedProducts = vi.fn()
      .mockRejectedValueOnce(new Error('Temporarily unavailable.'))
      .mockResolvedValueOnce({ data: [{ product_id: 11, title: 'Gold' }] })
    const products = usePlanProducts({
      plansApi: { linkedProducts },
      planId: () => 5,
      isNew: () => false,
      messageApi: messages(),
    })

    await products.loadLinkedProducts()
    expect(products.linkedProducts.value).toEqual([])
    expect(products.linkedProductsError.value).toBe('Temporarily unavailable.')
    expect(products.productsLoaded.value).toBe(false)

    await products.loadLinkedProducts()
    await products.loadLinkedProducts()
    expect(linkedProducts).toHaveBeenCalledTimes(2)
    expect(linkedProducts).toHaveBeenLastCalledWith(5)
    expect(products.linkedProducts.value).toEqual([{ product_id: 11, title: 'Gold' }])
  })

  it('marks linked search results and refreshes after link and unlink', async () => {
    const plansApi = {
      linkedProducts: vi.fn()
        .mockResolvedValueOnce({ data: [{ product_id: 11, title: 'Gold', feed_id: 70 }] })
        .mockResolvedValueOnce({ data: [{ product_id: 11, title: 'Gold', feed_id: 70 }, { product_id: 12, title: 'Workshop', feed_id: 71 }] })
        .mockResolvedValueOnce({ data: [{ product_id: 12, title: 'Workshop', feed_id: 71 }] }),
      searchProducts: vi.fn().mockResolvedValue({ data: [{ id: 11, title: 'Gold' }, { id: 12, title: 'Workshop' }] }),
      linkProduct: vi.fn().mockResolvedValue({}),
      unlinkProduct: vi.fn().mockResolvedValue({}),
    }
    const messageApi = messages()
    const products = usePlanProducts({ plansApi, planId: () => 5, isNew: () => false, messageApi })

    await products.loadLinkedProducts()
    await products.searchProducts()
    expect(products.productSearchResults.value).toEqual([
      { id: 11, title: 'Gold', already_linked: true },
      { id: 12, title: 'Workshop', already_linked: false },
    ])

    products.selectedProduct.value = { id: 12, title: 'Workshop' }
    await products.confirmLinkProduct()
    expect(plansApi.linkProduct).toHaveBeenCalledWith(5, { product_id: 12 })
    expect(products.linkProductVisible.value).toBe(false)
    expect(products.linkedProducts.value).toHaveLength(2)

    await products.confirmUnlinkProduct({ feed_id: 70 })
    expect(plansApi.unlinkProduct).toHaveBeenCalledWith(5, 70)
    expect(products.linkedProducts.value).toEqual([{ product_id: 12, title: 'Workshop', feed_id: 71 }])
    expect(messageApi.success.mock.calls).toEqual([
      ['Product linked successfully'],
      ['Product unlinked successfully'],
    ])
  })

  it('preserves loaded products when unlinking fails', async () => {
    const messageApi = messages()
    const products = usePlanProducts({
      plansApi: {
        linkedProducts: vi.fn().mockResolvedValue({ data: [{ product_id: 11, feed_id: 70 }] }),
        unlinkProduct: vi.fn().mockRejectedValue(new Error('Cannot unlink.')),
      },
      planId: () => 5,
      isNew: () => false,
      messageApi,
    })
    await products.loadLinkedProducts()

    await products.confirmUnlinkProduct({ feed_id: 70 })

    expect(products.linkedProducts.value).toEqual([{ product_id: 11, feed_id: 70 }])
    expect(products.productsLoading.value).toBe(false)
    expect(messageApi.error).toHaveBeenCalledWith('Cannot unlink.')
  })
})

describe('plan schedule', () => {
  it('hydrates, saves and clears the schedule with exact payloads', async () => {
    const plansApi = {
      schedule: vi.fn()
        .mockResolvedValueOnce({ data: { scheduled_status: 'active', scheduled_at: '2030-01-01 09:00:00' } })
        .mockResolvedValueOnce({}),
    }
    const messageApi = messages()
    const feature = usePlanSchedule({ plansApi, planId: () => 5, messageApi })
    feature.hydrateSchedule({ scheduled_status: 'inactive', scheduled_at: '2029-01-01 09:00:00' })
    feature.schedule.new_status = 'active'
    feature.schedule.new_at = '2030-01-01 09:00:00'

    await feature.saveSchedule()
    expect(plansApi.schedule).toHaveBeenNthCalledWith(1, 5, {
      scheduled_status: 'active',
      scheduled_at: '2030-01-01 09:00:00',
    })
    expect(feature.schedule).toMatchObject({
      scheduled_status: 'active',
      scheduled_at: '2030-01-01 09:00:00',
      new_status: '',
      new_at: '',
    })

    await feature.clearSchedule()
    expect(plansApi.schedule).toHaveBeenNthCalledWith(2, 5, { scheduled_status: '', scheduled_at: '' })
    expect(feature.schedule.scheduled_status).toBeNull()
    expect(feature.schedule.scheduled_at).toBeNull()
    expect(messageApi.success.mock.calls).toEqual([
      ['Status change scheduled'],
      ['Schedule cleared'],
    ])
  })
})

describe('plan members', () => {
  it('loads exact pages once and retains recoverable error state', async () => {
    const list = vi.fn()
      .mockRejectedValueOnce(new Error('Members unavailable.'))
      .mockResolvedValueOnce({ data: [{ id: 21 }], total: 31 })
      .mockResolvedValueOnce({ data: [{ id: 22 }], total: 31 })
    const members = usePlanMembers({
      membersApi: { list },
      planId: () => 5,
      isNew: () => false,
      perPage: 10,
    })

    await members.loadPlanMembers(1)
    expect(members.planMembersError.value).toBe('Members unavailable.')
    expect(members.planMembersLoaded.value).toBe(false)

    await members.loadPlanMembers(1)
    expect(list).toHaveBeenNthCalledWith(2, { plan_id: 5, per_page: 10, page: 1 })
    expect(members.planMembers.value).toEqual([{ id: 21 }])
    expect(members.planMembersTotal.value).toBe(31)

    await members.loadPlanMembers(2)
    expect(list).toHaveBeenNthCalledWith(3, { plan_id: 5, per_page: 10, page: 2 })
    expect(members.planMembersPage.value).toBe(2)
    expect(members.planMembers.value).toEqual([{ id: 22 }])
  })

  it('does not load members for an unsaved plan', async () => {
    const list = vi.fn()
    const members = usePlanMembers({
      membersApi: { list },
      planId: () => null,
      isNew: () => true,
    })

    await members.loadPlanMembers()
    expect(list).not.toHaveBeenCalled()
  })
})
