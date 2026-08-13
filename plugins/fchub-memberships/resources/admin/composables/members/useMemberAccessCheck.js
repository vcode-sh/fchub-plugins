import { computed, ref, unref, watch } from 'vue'
import { accessCheck, content } from '@/api/index.js'
import { describeAccessReason } from '@/pages/Members/memberProfileUi.js'

const SEARCH_DELAY = 250

/**
 * Answers "can this member open that?" using the same evaluator the front end
 * runs, over the resources the plugin actually protects.
 *
 * The chosen item stays listed whatever a later search returns, so the option
 * backing the selection cannot disappear and leave the check with nothing to
 * act on.
 */
export function useMemberAccessCheck(userId, {
  contentApi = content,
  accessCheckApi = accessCheck,
  setTimer = setTimeout,
  clearTimer = clearTimeout,
  searchDelay = SEARCH_DELAY,
} = {}) {
  const searching = ref(false)
  const checking = ref(false)
  const results = ref([])
  const chosen = ref(null)
  const selected = ref('')
  const result = ref(null)
  const query = ref('')
  let requestId = 0
  let timer = null

  const options = computed(() => {
    if (!chosen.value || results.value.some((item) => item.value === chosen.value.value)) {
      return results.value
    }
    return [chosen.value, ...results.value]
  })

  const emptyText = computed(() => {
    if (searching.value) return 'Searching…'
    return query.value
      ? `No protected content matches “${query.value}”`
      : 'Nothing is protected yet'
  })

  // Pure derivation, flushed synchronously so checking straight after a
  // selection acts on that selection.
  watch(selected, (value) => {
    if (!value) {
      chosen.value = null
      return
    }
    const found = results.value.find((item) => item.value === value)
    if (found) chosen.value = found
  }, { flush: 'sync' })

  function toOption(rule) {
    return {
      value: `${rule.resource_type}:${rule.resource_id}`,
      label: rule.resource_title || `${rule.resource_type} #${rule.resource_id}`,
      typeLabel: rule.resource_type_label || rule.resource_type,
      resourceType: rule.resource_type,
      resourceId: rule.resource_id,
    }
  }

  function search(term) {
    const next = String(term || '').trim()
    if (timer) clearTimer(timer)
    query.value = next
    searching.value = true
    timer = setTimer(() => load(next), searchDelay)
  }

  // Opening the dropdown should show what is protected, not an empty box.
  // Typing can beat the open event, so a query already on its way always wins.
  function browse() {
    if (timer || query.value) return undefined
    searching.value = true
    return load('')
  }

  async function load(term) {
    timer = null
    const id = ++requestId
    try {
      const response = await contentApi.list({ search: term, per_page: 20 })
      const rules = response.data ?? response ?? []
      if (id !== requestId) return
      results.value = rules.map(toOption)
    } catch {
      if (id !== requestId) return
      results.value = []
    } finally {
      if (id === requestId) searching.value = false
    }
  }

  async function check() {
    const option = chosen.value
    if (!option) return

    checking.value = true
    result.value = null
    try {
      const response = await accessCheckApi.check({
        user_id: unref(userId),
        resource_type: option.resourceType,
        resource_id: option.resourceId,
      })
      const data = response.data ?? response
      result.value = { ...describeAccessReason(data), resource: option.label }
    } catch (error) {
      result.value = {
        allowed: false,
        headline: 'Could not be checked',
        detail: error.message || 'The access check did not complete.',
        resource: option.label,
      }
    } finally {
      checking.value = false
    }
  }

  function reset() {
    selected.value = ''
    chosen.value = null
    result.value = null
  }

  return { searching, checking, options, emptyText, selected, result, search, browse, check, reset }
}
