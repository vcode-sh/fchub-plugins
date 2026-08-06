import { computed, onMounted, onUnmounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { plans } from '@/api/index.js'
import { usePlanTransfer } from '@/composables/plans/usePlanTransfer.js'
import { normalisePlanListResponse } from '@/pages/Plans/planListUi.js'

export function usePlanList({
  plansApi = plans,
  notify = ElMessage,
  router = useRouter(),
} = {}) {
  const loading = ref(false)
  const plans_data = ref([])
  const total = ref(0)
  const errorMessage = ref('')
  const summary = reactive({ total: 0, active: 0, needs_content: 0, scheduled: 0 })
  let requestSequence = 0
  const filters = reactive({
    page: 1,
    per_page: 20,
    search: '',
    status: '',
  })
  const totalPages = computed(() => Math.max(1, Math.ceil(total.value / filters.per_page)))
  const hasActiveFilters = computed(() => Boolean(filters.search || filters.status))
  const summaryItems = computed(() => [
    { label: 'Total plans', value: summary.total, support: 'All plan records in this workspace' },
    { label: 'Active plans', value: summary.active, support: 'Available for member access', tone: 'success' },
    { label: 'Needs content', value: summary.needs_content, support: 'Active plans without protection rules', tone: 'warning' },
    { label: 'Scheduled changes', value: summary.scheduled, support: 'Lifecycle updates waiting to run' },
  ])
  const deleteDialogVisible = ref(false)
  const deleteLoading = ref(false)
  const planToDelete = ref(null)
  let searchTimer = null

  function debouncedFetch() {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => {
      filters.page = 1
      fetchPlans()
    }, 300)
  }

  function resetAndFetch() {
    filters.page = 1
    fetchPlans()
  }

  function clearFilters() {
    filters.search = ''
    filters.status = ''
    resetAndFetch()
  }

  async function fetchPlans() {
    const requestId = ++requestSequence
    loading.value = true
    errorMessage.value = ''
    try {
      const params = {
        page: filters.page,
        per_page: filters.per_page,
      }
      if (filters.search) params.search = filters.search
      if (filters.status) params.status = filters.status

      const res = await plansApi.list(params)
      if (requestId !== requestSequence) return
      const normalised = normalisePlanListResponse(res)
      plans_data.value = normalised.rows
      total.value = normalised.total
      Object.assign(summary, normalised.summary)
    } catch (err) {
      if (requestId !== requestSequence) return
      plans_data.value = []
      total.value = 0
      errorMessage.value = err.message || 'Plan data could not be loaded. Please try again.'
    } finally {
      if (requestId === requestSequence) loading.value = false
    }
  }

  const transfer = usePlanTransfer({
    plansApi,
    notify,
    refreshPlans: fetchPlans,
  })

  function handleRowClick(row) {
    router.push(`/plans/${row.id}/edit`)
  }

  async function handleAction(command, row) {
    switch (command) {
      case 'edit':
        router.push(`/plans/${row.id}/edit`)
        break
      case 'duplicate':
        await duplicatePlan(row)
        break
      case 'export':
        await transfer.exportPlan(row)
        break
      case 'archive':
        await updatePlanStatus(row, 'archived')
        break
      case 'activate':
        await updatePlanStatus(row, 'active')
        break
      case 'delete':
        if (Number(row.history_count || 0) > 0) {
          notify.warning('Archive plans with access history instead of deleting them')
          break
        }
        planToDelete.value = row
        deleteDialogVisible.value = true
        break
    }
  }

  async function duplicatePlan(row) {
    try {
      await plansApi.duplicate(row.id)
      notify.success('Plan duplicated successfully')
      await fetchPlans()
    } catch (err) {
      notify.error(err.message || 'Failed to duplicate plan')
    }
  }

  async function updatePlanStatus(row, status) {
    try {
      await plansApi.update(row.id, { status })
      notify.success(`Plan ${status === 'archived' ? 'archived' : 'activated'} successfully`)
      await fetchPlans()
    } catch (err) {
      notify.error(err.message || 'Failed to update plan status')
    }
  }

  async function confirmDelete() {
    if (!planToDelete.value) return
    deleteLoading.value = true
    try {
      await plansApi.remove(planToDelete.value.id)
      notify.success('Plan deleted successfully')
      deleteDialogVisible.value = false
      planToDelete.value = null
      await fetchPlans()
    } catch (err) {
      notify.error(err.message || 'Failed to delete plan')
    } finally {
      deleteLoading.value = false
    }
  }

  onMounted(() => {
    fetchPlans()
  })

  onUnmounted(() => {
    clearTimeout(searchTimer)
  })

  return {
    loading,
    plans_data,
    total,
    errorMessage,
    summary,
    filters,
    totalPages,
    hasActiveFilters,
    summaryItems,
    deleteDialogVisible,
    deleteLoading,
    planToDelete,
    ...transfer,
    debouncedFetch,
    resetAndFetch,
    clearFilters,
    fetchPlans,
    handleRowClick,
    handleAction,
    confirmDelete,
  }
}
