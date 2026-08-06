import { ref, unref } from 'vue'
import { members } from '@/api/index.js'

export function useMemberActivity(userId, { membersApi = members } = {}) {
  const loading = ref(false)
  const loadingMore = ref(false)
  const events = ref([])
  const total = ref(0)
  const page = ref(1)

  async function fetchActivity() {
    loading.value = true
    page.value = 1
    try {
      const response = await membersApi.activity(unref(userId), { page: 1, per_page: 50 })
      const data = response.data ?? response
      events.value = Array.isArray(data) ? data : (data.data ?? data ?? [])
      total.value = response.total ?? data.total ?? events.value.length
    } catch {
      events.value = []
      total.value = 0
    } finally {
      loading.value = false
    }
  }

  async function loadMoreActivity() {
    loadingMore.value = true
    page.value++
    try {
      const response = await membersApi.activity(unref(userId), { page: page.value, per_page: 50 })
      const data = response.data ?? response
      const nextEvents = Array.isArray(data) ? data : (data.data ?? data ?? [])
      events.value = [...events.value, ...nextEvents]
    } catch {
      // Preserve the already-loaded activity feed after a later-page failure.
    } finally {
      loadingMore.value = false
    }
  }

  return { loading, loadingMore, events, total, page, fetchActivity, loadMoreActivity }
}
