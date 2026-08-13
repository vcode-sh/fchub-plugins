import { ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import { useResourceSearch } from '@/composables/content/useResourceSearch.js'

function deferred() {
  let resolve
  let reject
  const promise = new Promise((resolvePromise, rejectPromise) => {
    resolve = resolvePromise
    reject = rejectPromise
  })

  return { promise, resolve, reject }
}

function setup({ searchResources, selection = { id: '', label: null }, resourceType = 'page' } = {}) {
  const type = ref(resourceType)
  const current = ref(selection)
  const pending = []
  const contentApi = {
    searchResources: searchResources ?? vi.fn().mockResolvedValue({ data: [] }),
  }

  const engine = useResourceSearch({
    contentApi,
    resourceType: () => type.value,
    typeLabel: () => 'Pages',
    selection: () => current.value,
    setTimer: (callback) => {
      pending.push(callback)
      return callback
    },
    clearTimer: (callback) => {
      const index = pending.indexOf(callback)
      if (index >= 0) pending.splice(index, 1)
    },
  })

  // Drain whatever the debounce scheduled, newest first, as a real timer would.
  const flush = () => {
    const due = pending.splice(0, pending.length)
    return Promise.all(due.map((callback) => callback()))
  }

  return { engine, contentApi, type, current, flush, pending }
}

const PAGE_ROWS = [
  { id: '7', label: 'Checkout', status: 'draft', type_label: 'Pages' },
  { id: '8', label: 'Pricing', status: 'publish', type_label: 'Pages' },
]

describe('resource search', () => {
  it('browses without a query and keeps the row context the provider sent', async () => {
    const searchResources = vi.fn().mockResolvedValue({ data: PAGE_ROWS })
    const { engine, flush } = setup({ searchResources })

    engine.search('')
    await flush()

    expect(searchResources).toHaveBeenCalledWith({ type: 'page', query: '' })
    expect(engine.options.value).toEqual([
      { id: '7', label: 'Checkout', typeLabel: 'Pages', statusLabel: 'Draft' },
      { id: '8', label: 'Pricing', typeLabel: 'Pages', statusLabel: null },
    ])
  })

  it('browses immediately on open rather than parking on a debounce', async () => {
    const searchResources = vi.fn().mockResolvedValue({ data: PAGE_ROWS })
    const { engine, pending } = setup({ searchResources })

    await engine.browse()

    expect(pending).toHaveLength(0)
    expect(searchResources).toHaveBeenCalledWith({ type: 'page', query: '' })
    expect(engine.loading.value).toBe(false)
  })

  // Typing can open the dropdown, so the open event can arrive after the first
  // keystroke. Browsing must not cancel the query it raced.
  it('never cancels a query that is already on its way', async () => {
    const searchResources = vi.fn().mockResolvedValue({ data: PAGE_ROWS })
    const { engine, flush } = setup({ searchResources })

    engine.search('checkout')
    await engine.browse()
    await flush()

    expect(searchResources).toHaveBeenCalledTimes(1)
    expect(searchResources).toHaveBeenCalledWith({ type: 'page', query: 'checkout' })

    // Reopening while a query is showing keeps that query's results.
    await engine.browse()
    expect(searchResources).toHaveBeenCalledTimes(1)
  })

  it('coalesces a burst of keystrokes into a single request', async () => {
    const searchResources = vi.fn().mockResolvedValue({ data: PAGE_ROWS })
    const { engine, flush } = setup({ searchResources })

    engine.search('c')
    engine.search('ch')
    engine.search('che')
    await flush()

    expect(searchResources).toHaveBeenCalledTimes(1)
    expect(searchResources).toHaveBeenCalledWith({ type: 'page', query: 'che' })
  })

  it('ignores a slow response once a newer query has been issued', async () => {
    const slow = deferred()
    const fast = deferred()
    const searchResources = vi.fn()
      .mockReturnValueOnce(slow.promise)
      .mockReturnValueOnce(fast.promise)
    const { engine, flush } = setup({ searchResources })

    engine.search('checkout')
    const first = flush()
    engine.search('pricing')
    const second = flush()

    fast.resolve({ data: [PAGE_ROWS[1]] })
    await second
    slow.resolve({ data: [PAGE_ROWS[0]] })
    await first

    expect(engine.options.value.map((row) => row.id)).toEqual(['8'])
    expect(engine.loading.value).toBe(false)
  })

  it('keeps the current selection reachable when a search excludes it', async () => {
    const searchResources = vi.fn().mockResolvedValue({ data: [PAGE_ROWS[1]] })
    const { engine, flush } = setup({
      searchResources,
      selection: { id: '7', label: 'Checkout' },
    })

    engine.search('pricing')
    await flush()

    expect(engine.options.value).toEqual([
      { id: '7', label: 'Checkout', typeLabel: 'Pages', statusLabel: null },
      { id: '8', label: 'Pricing', typeLabel: 'Pages', statusLabel: null },
    ])
  })

  it('does not duplicate a selection the search already returned', async () => {
    const searchResources = vi.fn().mockResolvedValue({ data: PAGE_ROWS })
    const { engine, flush } = setup({
      searchResources,
      selection: { id: '7', label: 'Checkout' },
    })

    engine.search('')
    await flush()

    expect(engine.options.value.filter((row) => row.id === '7')).toHaveLength(1)
  })

  it('names an unlabelled legacy selection instead of rendering a blank row', async () => {
    const { engine, flush } = setup({ selection: { id: '7', label: null } })

    engine.search('')
    await flush()

    expect(engine.options.value).toEqual([
      { id: '7', label: 'Pages #7', typeLabel: 'Pages', statusLabel: null },
    ])
  })

  it('treats the all-of-this-type sentinel as no selection at all', async () => {
    const searchResources = vi.fn().mockResolvedValue({ data: [PAGE_ROWS[1]] })
    const { engine, flush } = setup({ searchResources, selection: { id: '0', label: null } })

    engine.search('pricing')
    await flush()

    expect(engine.options.value.map((row) => row.id)).toEqual(['8'])
  })

  it('reuses a cached query but refetches when the resource type changes', async () => {
    const searchResources = vi.fn().mockResolvedValue({ data: PAGE_ROWS })
    const { engine, contentApi, type, flush } = setup({ searchResources })

    engine.search('check')
    await flush()
    engine.search('check')
    await flush()
    expect(contentApi.searchResources).toHaveBeenCalledTimes(1)

    type.value = 'post'
    engine.search('check')
    await flush()

    expect(contentApi.searchResources).toHaveBeenCalledTimes(2)
    expect(contentApi.searchResources).toHaveBeenLastCalledWith({ type: 'post', query: 'check' })
  })

  it('reports a failed search in the dropdown and keeps the selection listed', async () => {
    const searchResources = vi.fn().mockRejectedValue(new Error('Network down'))
    const { engine, flush } = setup({
      searchResources,
      selection: { id: '7', label: 'Checkout' },
    })

    engine.search('checkout')
    await flush()

    expect(engine.loading.value).toBe(false)
    expect(engine.emptyText.value).toBe('Network down')
    expect(engine.options.value.map((row) => row.id)).toEqual(['7'])
  })

  it('tells the administrator which state the dropdown is in', async () => {
    const pendingSearch = deferred()
    const searchResources = vi.fn().mockReturnValue(pendingSearch.promise)
    const { engine, flush } = setup({ searchResources })

    expect(engine.emptyText.value).toBe('No Pages available')

    engine.search('checkout')
    const inFlight = flush()
    expect(engine.loading.value).toBe(true)
    expect(engine.emptyText.value).toBe('Searching…')

    pendingSearch.resolve({ data: [] })
    await inFlight

    expect(engine.emptyText.value).toBe('No Pages match “checkout”')
  })
})
