import { ref, unref } from 'vue'
import { accessCheck, content } from '@/api/index.js'
import { describeAccessReason } from '@/pages/Members/memberProfileUi.js'

/**
 * Answers "can this member open that?" using the same evaluator the front end
 * runs, over the resources the plugin actually protects.
 */
export function useMemberAccessCheck(userId, {
  contentApi = content,
  accessCheckApi = accessCheck,
} = {}) {
  const searching = ref(false)
  const checking = ref(false)
  const options = ref([])
  const selected = ref('')
  const result = ref(null)

  async function search(term) {
    const query = String(term || '').trim()
    if (query.length < 2) {
      options.value = []
      return
    }

    searching.value = true
    try {
      const response = await contentApi.list({ search: query, per_page: 20 })
      const rules = response.data ?? response ?? []
      options.value = rules.map((rule) => ({
        value: `${rule.resource_type}:${rule.resource_id}`,
        label: rule.title || rule.resource_label || `${rule.resource_type} #${rule.resource_id}`,
        resourceType: rule.resource_type,
        resourceId: rule.resource_id,
      }))
    } catch {
      options.value = []
    } finally {
      searching.value = false
    }
  }

  async function check() {
    const option = options.value.find((item) => item.value === selected.value)
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
    result.value = null
  }

  return { searching, checking, options, selected, result, search, check, reset }
}
