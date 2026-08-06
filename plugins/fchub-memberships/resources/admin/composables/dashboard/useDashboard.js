import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { dashboard } from '@/api/dashboard.js'
import { formatWpDate } from '@/utils/wpDate.js'
import {
  formatCount,
  isDashboardResponse,
  withOpacity,
} from '@/pages/dashboardUi.js'

const THEME_CHANGE_EVENT = 'onFluentCartThemeChange'

const emptySummary = {
  active_members: 0,
  new_members_30d: 0,
  grants_30d: 0,
  expiring_7d: 0,
  failed_notifications: 0,
}

const emptyReadiness = {
  active_plans: 0,
  protected_items: 0,
  has_active_plan: false,
  has_protected_content: false,
  has_active_members: false,
}

export function useDashboard() {
  const loading = ref(true)
  const errorMessage = ref('')
  const dashboardData = ref(null)
  const chartColours = ref({ primary: '', fill: '', text: '', grid: '' })

  const summary = computed(() => ({ ...emptySummary, ...(dashboardData.value?.summary || {}) }))
  const readiness = computed(() => ({ ...emptyReadiness, ...(dashboardData.value?.readiness || {}) }))
  const attentionItems = computed(() => (
    Array.isArray(dashboardData.value?.attention) ? dashboardData.value.attention : []
  ))
  const trendPoints = computed(() => (
    Array.isArray(dashboardData.value?.trend) ? dashboardData.value.trend : []
  ))
  const activityItems = computed(() => (
    Array.isArray(dashboardData.value?.activity) ? dashboardData.value.activity : []
  ))
  const hasActivePlan = computed(() => (
    readiness.value.has_active_plan || Number(readiness.value.active_plans) > 0
  ))
  const hasTrend = computed(() => trendPoints.value.length >= 2)

  const readinessSteps = computed(() => [
    {
      key: 'plans',
      title: 'Active plans',
      count: readiness.value.active_plans,
      complete: hasActivePlan.value,
      description: hasActivePlan.value
        ? 'Plans are ready to grant.'
        : 'Create and activate the first membership plan.',
      action: 'Create plan',
      destination: '/plans/new',
    },
    {
      key: 'content',
      title: 'Protected items',
      count: readiness.value.protected_items,
      complete: readiness.value.has_protected_content,
      description: readiness.value.has_protected_content
        ? 'Member-only content is protected.'
        : 'Choose what active plans should unlock.',
      action: 'Protect content',
      destination: '/content',
    },
    {
      key: 'members',
      title: 'Active members',
      count: summary.value.active_members,
      complete: readiness.value.has_active_members,
      description: readiness.value.has_active_members
        ? 'Members currently have active access.'
        : 'Grant a plan to the first member.',
      action: hasActivePlan.value ? 'Grant access' : 'Create a plan first',
      destination: hasActivePlan.value ? '/members' : '/plans/new',
    },
  ])

  const completedReadinessSteps = computed(() => (
    readinessSteps.value.filter((step) => step.complete).length
  ))

  const rankedPlans = computed(() => {
    const rows = Array.isArray(dashboardData.value?.plan_distribution)
      ? dashboardData.value.plan_distribution
      : []

    return [...rows]
      .filter((row) => Number(row.count) > 0)
      .sort((left, right) => Number(right.count) - Number(left.count))
  })

  const largestPlanCount = computed(() => Number(rankedPlans.value[0]?.count) || 1)
  const recentActivity = computed(() => activityItems.value.slice(0, 6))

  const membersChartData = computed(() => ({
    labels: trendPoints.value.map((point) => formatWpDate(point.date, point.date)),
    datasets: [{
      data: trendPoints.value.map((point) => Number(point.count) || 0),
      borderColor: chartColours.value.primary,
      backgroundColor: chartColours.value.fill,
      pointBackgroundColor: chartColours.value.primary,
      pointRadius: 2,
      pointHoverRadius: 4,
      borderWidth: 2,
      fill: true,
      tension: 0.35,
    }],
  }))

  const lineChartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    interaction: { intersect: false, mode: 'index' },
    plugins: {
      legend: { display: false },
      tooltip: { displayColors: false },
    },
    scales: {
      x: {
        grid: { display: false },
        ticks: { maxTicksLimit: 4, color: chartColours.value.text },
        border: { display: false },
      },
      y: {
        beginAtZero: true,
        grid: { color: chartColours.value.grid },
        ticks: { precision: 0, maxTicksLimit: 4, color: chartColours.value.text },
        border: { display: false },
      },
    },
  }))

  const trendChangeLabel = computed(() => {
    if (!hasTrend.value) return ''
    const first = Number(trendPoints.value[0]?.count) || 0
    const last = Number(trendPoints.value.at(-1)?.count) || 0
    const change = last - first
    if (change === 0) return 'No net change'
    return `${change > 0 ? '+' : ''}${formatCount(change)} members`
  })

  const trendSummary = computed(() => {
    if (!hasTrend.value) return ''
    const first = Number(trendPoints.value[0]?.count) || 0
    const last = Number(trendPoints.value.at(-1)?.count) || 0
    const direction = last > first ? 'increased' : last < first ? 'decreased' : 'remained steady'
    if (direction === 'remained steady') {
      return `Members remained steady at ${formatCount(last)} across ${trendPoints.value.length} recorded days.`
    }
    return `Members ${direction} from ${formatCount(first)} to ${formatCount(last)} across ${trendPoints.value.length} recorded days.`
  })

  function resolveChartColours() {
    const target = document.querySelector('#fchub-memberships-app') || document.body
    const styles = getComputedStyle(target)
    const primary = styles.getPropertyValue('--fchub-chart-primary').trim()
      || styles.getPropertyValue('--el-color-primary').trim()
    const text = styles.getPropertyValue('--fchub-text-secondary').trim()
    chartColours.value = {
      primary,
      fill: withOpacity(primary, 0.1),
      text,
      grid: withOpacity(text, 0.14),
    }
  }

  async function loadDashboard() {
    loading.value = true
    errorMessage.value = ''
    dashboardData.value = null

    try {
      const response = await dashboard.load()
      if (!isDashboardResponse(response)) {
        throw new Error('Dashboard data is incomplete. Please try again.')
      }
      resolveChartColours()
      dashboardData.value = response.data
    } catch (error) {
      errorMessage.value = error?.message || 'Membership data could not be loaded. Please try again.'
    } finally {
      loading.value = false
    }
  }

  function distributionWidth(count) {
    return Math.max(0, Math.min(100, (Number(count) / largestPlanCount.value) * 100))
  }

  onMounted(() => {
    window.addEventListener(THEME_CHANGE_EVENT, resolveChartColours)
    loadDashboard()
  })

  onBeforeUnmount(() => {
    window.removeEventListener(THEME_CHANGE_EVENT, resolveChartColours)
  })

  return {
    attentionItems,
    chartColours,
    completedReadinessSteps,
    dashboardData,
    distributionWidth,
    errorMessage,
    hasActivePlan,
    hasTrend,
    lineChartOptions,
    loadDashboard,
    loading,
    membersChartData,
    rankedPlans,
    readinessSteps,
    recentActivity,
    summary,
    trendChangeLabel,
    trendSummary,
  }
}
