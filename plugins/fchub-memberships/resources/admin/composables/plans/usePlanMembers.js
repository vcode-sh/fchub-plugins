import { ref } from 'vue'

function resolveValue(value) {
  return typeof value === 'function' ? value() : value
}

export function usePlanMembers({ membersApi, planId, isNew, perPage = 10 }) {
  const planMembers = ref([])
  const planMembersLoading = ref(false)
  const planMembersLoaded = ref(false)
  const planMembersError = ref('')
  const planMembersPage = ref(1)
  const planMembersTotal = ref(0)

  async function loadPlanMembers(page = 1) {
    if (resolveValue(isNew)) return

    planMembersLoading.value = true
    planMembersError.value = ''
    try {
      const response = await membersApi.list({
        plan_id: resolveValue(planId),
        per_page: perPage,
        page,
      })
      const data = response.data ?? response
      planMembers.value = Array.isArray(data) ? data : (data.data ?? [])
      planMembersTotal.value = response.total ?? data.total ?? 0
      planMembersPage.value = page
      planMembersLoaded.value = true
    } catch (error) {
      planMembersError.value = error.message || 'Failed to load plan members.'
    } finally {
      planMembersLoading.value = false
    }
  }

  function onMembersPageChange(page) {
    return loadPlanMembers(page)
  }

  return {
    planMembers,
    planMembersLoading,
    planMembersLoaded,
    planMembersError,
    planMembersPage,
    planMembersPerPage: perPage,
    planMembersTotal,
    loadPlanMembers,
    onMembersPageChange,
  }
}
