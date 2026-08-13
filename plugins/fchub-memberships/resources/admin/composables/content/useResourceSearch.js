import { computed, ref } from 'vue'

const SEARCH_DELAY = 250

// Published is the norm, so it earns no badge. The rest explain why a result
// looks unfamiliar.
const STATUS_LABELS = {
  future: 'Scheduled',
  draft: 'Draft',
  pending: 'Pending review',
  private: 'Private',
}

/**
 * Debounced content search behind one resource picker.
 *
 * The selected resource is always listed, whatever the current query returned,
 * so a picker can never fall back to rendering a bare id.
 */
export function useResourceSearch({
  contentApi,
  resourceType,
  typeLabel,
  selection,
  setTimer = setTimeout,
  clearTimer = clearTimeout,
  searchDelay = SEARCH_DELAY,
}) {
  const results = ref([])
  const loading = ref(false)
  const error = ref('')
  const query = ref('')
  const cache = new Map()
  let cachedType = resourceType()
  let requestId = 0
  let timer = null

  const options = computed(() => withSelection(results.value))

  const emptyText = computed(() => {
    if (loading.value) return 'Searching…'
    if (error.value) return error.value
    return query.value
      ? `No ${typeLabel()} match “${query.value}”`
      : `No ${typeLabel()} available`
  })

  // `0` is the all-of-this-type sentinel, not a resource, and the picker
  // renders it as its own option.
  function withSelection(rows) {
    const { id, label } = selection()
    if (!id || id === '0' || rows.some((row) => row.id === String(id))) return rows

    return [{
      id: String(id),
      label: label || `${typeLabel()} #${id}`,
      typeLabel: typeLabel(),
      statusLabel: null,
    }, ...rows]
  }

  function toRow(item) {
    return {
      id: String(item.id),
      label: item.label || item.title || `${typeLabel()} #${item.id}`,
      typeLabel: item.type_label || typeLabel(),
      statusLabel: STATUS_LABELS[item.status] ?? null,
    }
  }

  function search(rawQuery) {
    const next = String(rawQuery ?? '').trim()
    if (timer) clearTimer(timer)
    query.value = next
    loading.value = true
    error.value = ''
    timer = setTimer(() => run(next), searchDelay)
  }

  // Opening the dropdown is already the member's deliberate act, so the recent
  // list loads without waiting out a debounce it does not need. Typing can beat
  // the open event, so a query already on its way always wins.
  function browse() {
    if (timer || query.value) return undefined
    loading.value = true
    error.value = ''
    return run('')
  }

  async function run(next) {
    timer = null
    const type = resourceType()
    if (type !== cachedType) {
      cache.clear()
      cachedType = type
    }

    const id = ++requestId
    if (cache.has(next)) {
      results.value = cache.get(next)
      loading.value = false
      return
    }

    try {
      const response = await contentApi.searchResources({ type, query: next })
      const rows = (response.data ?? response ?? []).map(toRow)
      cache.set(next, rows)
      if (id !== requestId) return
      results.value = rows
    } catch (failure) {
      if (id !== requestId) return
      results.value = []
      error.value = failure.message || 'Content search failed. Try again.'
    } finally {
      if (id === requestId) loading.value = false
    }
  }

  return { options, loading, emptyText, search, browse }
}
