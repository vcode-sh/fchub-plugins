import { ref, computed } from 'vue'
import { getMyAccess } from '../api/client.js'

export function useMyAccess() {
  const plans = ref([])
  const history = ref([])
  const loading = ref(true)
  const error = ref(null)

  const hasPlans = computed(() => plans.value.length > 0)
  const hasHistory = computed(() => history.value.length > 0)

  async function refresh() {
    loading.value = true
    error.value = null

    try {
      const data = await getMyAccess()
      plans.value = data.plans || []
      history.value = data.history || []
    } catch (err) {
      error.value = err.message || 'Failed to load membership data'
    } finally {
      loading.value = false
    }
  }

  // Fetch immediately
  refresh()

  return {
    plans,
    history,
    loading,
    error,
    refresh,
    hasPlans,
    hasHistory,
  }
}
