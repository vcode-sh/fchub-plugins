import { ref, computed } from 'vue'
import { getMyAccess } from '../api/client.js'

export function useMyAccess() {
  const plans = ref([])
  const history = ref([])
  const community = ref(emptyCommunity())
  const loading = ref(true)
  const error = ref(null)

  const hasPlans = computed(() => plans.value.length > 0)
  const hasHistory = computed(() => history.value.length > 0)
  const hasCommunity = computed(() => (
    community.value.spaces.length > 0
    || community.value.courses.length > 0
    || community.value.pending_access_count > 0
    || community.value.profile !== null
  ))

  async function refresh() {
    loading.value = true
    error.value = null

    try {
      const data = await getMyAccess()
      plans.value = data.plans || []
      history.value = data.history || []
      community.value = normaliseCommunity(data.community)
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
    community,
    loading,
    error,
    refresh,
    hasPlans,
    hasHistory,
    hasCommunity,
  }
}

function emptyCommunity() {
  return {
    state: 'inactive',
    profile: null,
    spaces: [],
    courses: [],
    pending_access_count: 0,
    capabilities: {},
  }
}

function normaliseCommunity(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return emptyCommunity()
  }

  return {
    ...emptyCommunity(),
    ...value,
    profile: value.profile && typeof value.profile === 'object' ? value.profile : null,
    spaces: Array.isArray(value.spaces) ? value.spaces : [],
    courses: Array.isArray(value.courses) ? value.courses : [],
    pending_access_count: Math.max(0, Number(value.pending_access_count) || 0),
    capabilities: value.capabilities && typeof value.capabilities === 'object'
      ? value.capabilities
      : {},
  }
}
