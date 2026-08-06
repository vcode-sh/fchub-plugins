import { reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'

function resolveValue(value) {
  return typeof value === 'function' ? value() : value
}

export function usePlanSchedule({ plansApi, planId, messageApi = ElMessage }) {
  const schedule = reactive({
    scheduled_status: null,
    scheduled_at: null,
    new_status: '',
    new_at: '',
  })
  const scheduleSaving = ref(false)

  function hydrateSchedule(plan = {}) {
    schedule.scheduled_status = plan.scheduled_status || null
    schedule.scheduled_at = plan.scheduled_at || null
  }

  async function saveSchedule() {
    if (!schedule.new_status || !schedule.new_at) return

    scheduleSaving.value = true
    try {
      const response = await plansApi.schedule(resolveValue(planId), {
        scheduled_status: schedule.new_status,
        scheduled_at: schedule.new_at,
      })
      const data = response.data ?? response
      schedule.scheduled_status = data.scheduled_status || schedule.new_status
      schedule.scheduled_at = data.scheduled_at || schedule.new_at
      schedule.new_status = ''
      schedule.new_at = ''
      messageApi.success('Status change scheduled')
    } catch (error) {
      messageApi.error(error.message || 'Failed to schedule status change')
    } finally {
      scheduleSaving.value = false
    }
  }

  async function clearSchedule() {
    scheduleSaving.value = true
    try {
      await plansApi.schedule(resolveValue(planId), { scheduled_status: '', scheduled_at: '' })
      schedule.scheduled_status = null
      schedule.scheduled_at = null
      messageApi.success('Schedule cleared')
    } catch (error) {
      messageApi.error(error.message || 'Failed to clear schedule')
    } finally {
      scheduleSaving.value = false
    }
  }

  return {
    schedule,
    scheduleSaving,
    hydrateSchedule,
    saveSchedule,
    clearSchedule,
  }
}
