<template>
  <div class="dashboard-page">
    <WorkspacePageHeader
      eyebrow="Membership workspace"
      title="Dashboard"
      description="See what needs action, check setup health, and keep member access moving."
    >
      <template v-if="!loading && !errorMessage" #actions>
        <router-link
          v-if="!hasActivePlan"
          to="/plans/new"
          class="dashboard-action dashboard-action--primary"
        >
          <el-icon><Plus /></el-icon>
          Create first plan
        </router-link>
        <template v-else>
          <router-link to="/members" class="dashboard-action dashboard-action--primary">
            <el-icon><UserFilled /></el-icon>
            Grant access
          </router-link>
          <router-link to="/content" class="dashboard-action dashboard-action--primary">
            <el-icon><Lock /></el-icon>
            Protect content
          </router-link>
        </template>
      </template>
    </WorkspacePageHeader>

    <div v-if="loading" class="dashboard-skeleton" aria-label="Loading dashboard">
      <div class="skeleton-line skeleton-line--short" />
      <div class="skeleton-grid">
        <div v-for="index in 4" :key="index" class="skeleton-card">
          <div class="skeleton-line" />
          <div class="skeleton-value" />
        </div>
      </div>
      <div class="skeleton-panels">
        <div class="skeleton-panel" />
        <div class="skeleton-panel" />
      </div>
    </div>

    <section v-else-if="errorMessage" class="dashboard-error" role="alert">
      <div class="error-icon" aria-hidden="true">
        <el-icon><WarningFilled /></el-icon>
      </div>
      <div class="error-copy">
        <h2>Dashboard unavailable</h2>
        <p>{{ errorMessage }}</p>
      </div>
      <button type="button" class="dashboard-action dashboard-action--primary" @click="loadDashboard">
        <el-icon><RefreshRight /></el-icon>
        Try again
      </button>
    </section>

    <main v-else-if="dashboardData" class="dashboard-content">
      <section class="attention-section panel" aria-labelledby="attention-heading">
        <div class="section-heading">
          <div>
            <p class="section-eyebrow">Operations</p>
            <h2 id="attention-heading">Needs attention</h2>
          </div>
          <span v-if="attentionItems.length" class="section-count">
            {{ attentionItems.length }} {{ attentionItems.length === 1 ? 'item' : 'items' }}
          </span>
        </div>

        <div v-if="attentionItems.length" class="attention-list">
          <router-link
            v-for="item in attentionItems"
            :key="item.key"
            :to="safeDestination(item.destination)"
            class="attention-item"
            :class="`attention-item--${safeSeverity(item.severity)}`"
          >
            <span class="severity-label">{{ severityLabel(item.severity) }}</span>
            <span class="attention-copy">
              <strong>{{ item.title }}</strong>
              <span>{{ item.description }}</span>
            </span>
            <span v-if="Number(item.count) > 0" class="attention-count">{{ item.count }}</span>
            <el-icon class="attention-arrow" aria-hidden="true"><ArrowRight /></el-icon>
          </router-link>
        </div>

        <div v-else class="healthy-state">
          <el-icon aria-hidden="true"><CircleCheck /></el-icon>
          <div>
            <strong>Nothing urgent</strong>
            <span>No membership issues need attention right now.</span>
          </div>
        </div>
      </section>

      <ProviderHealthCards compact />

      <section class="summary-grid" aria-label="Membership summary">
        <router-link to="/members" class="summary-metric">
          <span class="summary-icon summary-icon--blue" aria-hidden="true"><el-icon><UserFilled /></el-icon></span>
          <span class="summary-label">Active members</span>
          <strong class="summary-value">{{ formatCount(summary.active_members) }}</strong>
          <span class="summary-support">Current access</span>
        </router-link>
        <router-link to="/members" class="summary-metric">
          <span class="summary-icon summary-icon--orange" aria-hidden="true"><el-icon><Plus /></el-icon></span>
          <span class="summary-label">New in 30 days</span>
          <strong class="summary-value">{{ formatCount(summary.new_members_30d) }}</strong>
          <span class="summary-support">{{ formatCount(summary.grants_30d) }} access grants issued</span>
        </router-link>
        <router-link to="/members" class="summary-metric">
          <span class="summary-icon summary-icon--purple" aria-hidden="true"><el-icon><Clock /></el-icon></span>
          <span class="summary-label">Expiring in 7 days</span>
          <strong class="summary-value">{{ formatCount(summary.expiring_7d) }}</strong>
          <span class="summary-support">Review upcoming expiry</span>
        </router-link>
        <router-link to="/drip" class="summary-metric">
          <span class="summary-icon summary-icon--pink" aria-hidden="true"><el-icon><Bell /></el-icon></span>
          <span class="summary-label">Failed notifications</span>
          <strong class="summary-value">{{ formatCount(summary.failed_notifications) }}</strong>
          <span class="summary-support">Check delivery queue</span>
        </router-link>
      </section>

      <div class="dashboard-row dashboard-row--readiness">
        <section class="panel readiness-panel" aria-labelledby="readiness-heading">
          <div class="section-heading">
            <div>
              <p class="section-eyebrow">Setup health</p>
              <h2 id="readiness-heading">Readiness</h2>
            </div>
            <span class="readiness-score">{{ completedReadinessSteps }}/3 ready</span>
          </div>

          <div class="readiness-list">
            <div v-for="step in readinessSteps" :key="step.key" class="readiness-step">
              <span class="readiness-icon" :class="{ 'is-complete': step.complete }" aria-hidden="true">
                <el-icon><Check v-if="step.complete" /><ArrowRight v-else /></el-icon>
              </span>
              <div class="readiness-copy">
                <strong>{{ step.title }}</strong>
                <span>{{ step.description }}</span>
              </div>
              <strong class="readiness-value">{{ formatCount(step.count) }}</strong>
              <router-link v-if="!step.complete" :to="step.destination" class="text-action">
                {{ step.action }}
                <el-icon aria-hidden="true"><ArrowRight /></el-icon>
              </router-link>
            </div>
          </div>
        </section>

        <section
          class="panel trend-panel"
          aria-labelledby="trend-heading"
          :style="{ '--dashboard-chart-primary': chartColours.primary }"
        >
          <div class="section-heading">
            <div>
              <p class="section-eyebrow">Last 30 days</p>
              <h2 id="trend-heading">Member trend</h2>
            </div>
            <span v-if="hasTrend" class="trend-change">{{ trendChangeLabel }}</span>
          </div>
          <div v-if="hasTrend" class="trend-chart">
            <div class="trend-plot">
              <Line
                :data="membersChartData"
                :options="lineChartOptions"
                role="img"
                aria-label="Member count over the last 30 days"
                aria-describedby="member-trend-summary"
              />
            </div>
            <p id="member-trend-summary" class="trend-summary">{{ trendSummary }}</p>
          </div>
          <div v-else class="compact-empty">
            <el-icon aria-hidden="true"><DataLine /></el-icon>
            <div>
              <strong>Not enough history yet</strong>
              <span>Trend appears after at least two daily snapshots.</span>
            </div>
            <router-link to="/members" class="text-action">View members <el-icon><ArrowRight /></el-icon></router-link>
          </div>
        </section>
      </div>

      <div class="dashboard-row dashboard-row--details">
        <section class="panel distribution-panel" aria-labelledby="distribution-heading">
          <div class="section-heading">
            <div>
              <p class="section-eyebrow">Membership mix</p>
              <h2 id="distribution-heading">Plan distribution</h2>
            </div>
          </div>
          <div v-if="rankedPlans.length" class="distribution-list">
            <div v-for="(plan, index) in rankedPlans" :key="plan.plan_id" class="distribution-row">
              <div class="distribution-meta">
                <span class="distribution-rank">{{ index + 1 }}</span>
                <strong>{{ plan.plan_title || 'Untitled plan' }}</strong>
                <span>{{ formatCount(plan.count) }}</span>
              </div>
              <div class="distribution-track" aria-hidden="true">
                <span :style="{ width: `${distributionWidth(plan.count)}%` }" />
              </div>
            </div>
          </div>
          <div v-else class="compact-empty">
            <el-icon aria-hidden="true"><Tickets /></el-icon>
            <div>
              <strong>No active members to compare yet</strong>
              <span>Plan distribution appears after access is granted.</span>
            </div>
            <router-link v-if="hasActivePlan" to="/members" class="text-action">Grant access <el-icon><ArrowRight /></el-icon></router-link>
            <router-link v-else to="/plans/new" class="text-action">Create first plan <el-icon><ArrowRight /></el-icon></router-link>
          </div>
        </section>

        <section class="panel activity-panel" aria-labelledby="activity-heading">
          <div class="section-heading">
            <div>
              <p class="section-eyebrow">Audit trail</p>
              <h2 id="activity-heading">Recent activity</h2>
            </div>
          </div>
          <ol v-if="recentActivity.length" class="activity-list">
            <li v-for="entry in recentActivity" :key="entry.id" class="activity-item">
              <span class="activity-icon" aria-hidden="true"><el-icon><Tickets /></el-icon></span>
              <div class="activity-copy">
                <strong>{{ actionLabel(entry) }}</strong>
                <span>{{ entityLabel(entry) }} · {{ actorLabel(entry) }}</span>
              </div>
              <time :datetime="toIsoDateTime(entry.occurred_at) || undefined">{{ formatWpDateTime(entry.occurred_at, 'Date unavailable') }}</time>
            </li>
          </ol>
          <div v-else class="compact-empty">
            <el-icon aria-hidden="true"><Tickets /></el-icon>
            <div>
              <strong>No recorded activity yet</strong>
              <span>Plan, protection, and access changes will appear here.</span>
            </div>
          </div>
        </section>
      </div>
    </main>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import {
  ArrowRight,
  Bell,
  Check,
  CircleCheck,
  Clock,
  DataLine,
  Lock,
  Plus,
  RefreshRight,
  Tickets,
  UserFilled,
  WarningFilled,
} from '@element-plus/icons-vue'
import { Line } from 'vue-chartjs'
import {
  CategoryScale,
  Chart as ChartJS,
  Filler,
  LinearScale,
  LineElement,
  PointElement,
  Tooltip,
} from 'chart.js'
import { dashboard } from '@/api/dashboard.js'
import ProviderHealthCards from '@/components/dashboard/ProviderHealthCards.vue'
import WorkspacePageHeader from '@/components/workspace/WorkspacePageHeader.vue'
import { formatWpDate, formatWpDateTime } from '@/utils/wpDate.js'

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Tooltip, Filler)

const loading = ref(true)
const errorMessage = ref('')
const dashboardData = ref(null)
const chartColours = ref({ primary: '', fill: '', text: '', grid: '' })
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

const summary = computed(() => ({ ...emptySummary, ...(dashboardData.value?.summary || {}) }))
const readiness = computed(() => ({ ...emptyReadiness, ...(dashboardData.value?.readiness || {}) }))
const attentionItems = computed(() => Array.isArray(dashboardData.value?.attention) ? dashboardData.value.attention : [])
const trendPoints = computed(() => Array.isArray(dashboardData.value?.trend) ? dashboardData.value.trend : [])
const activityItems = computed(() => Array.isArray(dashboardData.value?.activity) ? dashboardData.value.activity : [])
const hasActivePlan = computed(() => readiness.value.has_active_plan || Number(readiness.value.active_plans) > 0)
const hasTrend = computed(() => trendPoints.value.length >= 2)

const readinessSteps = computed(() => [
  {
    key: 'plans',
    title: 'Active plans',
    count: readiness.value.active_plans,
    complete: hasActivePlan.value,
    description: hasActivePlan.value ? 'Plans are ready to grant.' : 'Create and activate the first membership plan.',
    action: 'Create plan',
    destination: '/plans/new',
  },
  {
    key: 'content',
    title: 'Protected items',
    count: readiness.value.protected_items,
    complete: readiness.value.has_protected_content,
    description: readiness.value.has_protected_content ? 'Member-only content is protected.' : 'Choose what active plans should unlock.',
    action: 'Protect content',
    destination: '/content',
  },
  {
    key: 'members',
    title: 'Active members',
    count: summary.value.active_members,
    complete: readiness.value.has_active_members,
    description: readiness.value.has_active_members ? 'Members currently have active access.' : 'Grant a plan to the first member.',
    action: hasActivePlan.value ? 'Grant access' : 'Create a plan first',
    destination: hasActivePlan.value ? '/members' : '/plans/new',
  },
])

const completedReadinessSteps = computed(() => readinessSteps.value.filter((step) => step.complete).length)

const rankedPlans = computed(() => {
  const rows = Array.isArray(dashboardData.value?.plan_distribution) ? dashboardData.value.plan_distribution : []
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

function isObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

function isCount(value) {
  return typeof value === 'number' && Number.isFinite(value) && value >= 0
}

function hasKeys(value, keys) {
  return isObject(value) && keys.every((key) => Object.prototype.hasOwnProperty.call(value, key))
}

function isDashboardResponse(response) {
  if (!hasKeys(response, ['data']) || !isObject(response.data)) return false
  const payload = response.data
  if (!hasKeys(payload, ['summary', 'readiness', 'attention', 'trend', 'plan_distribution', 'activity'])) return false

  const summaryKeys = [
    'active_members',
    'new_members_30d',
    'grants_30d',
    'expiring_7d',
    'failed_notifications',
  ]
  const validSummary = hasKeys(payload.summary, summaryKeys)
    && summaryKeys.every((key) => isCount(payload.summary[key]))

  const validReadiness = hasKeys(payload.readiness, [
    'active_plans',
    'protected_items',
    'has_active_plan',
    'has_protected_content',
    'has_active_members',
  ])
    && isCount(payload.readiness.active_plans)
    && isCount(payload.readiness.protected_items)
    && ['has_active_plan', 'has_protected_content', 'has_active_members']
      .every((key) => typeof payload.readiness[key] === 'boolean')

  const validAttention = Array.isArray(payload.attention) && payload.attention.every((item) => (
    hasKeys(item, ['key', 'severity', 'title', 'description', 'count', 'destination'])
      && ['key', 'severity', 'title', 'description', 'destination'].every((key) => typeof item[key] === 'string')
      && isCount(item.count)
      && item.destination.startsWith('/')
  ))

  const validTrend = Array.isArray(payload.trend) && payload.trend.every((point) => (
    hasKeys(point, ['date', 'count'])
      && typeof point.date === 'string'
      && point.date.length > 0
      && isCount(point.count)
  ))

  const validDistribution = Array.isArray(payload.plan_distribution) && payload.plan_distribution.every((plan) => (
    hasKeys(plan, ['plan_id', 'plan_title', 'count'])
      && Number.isInteger(plan.plan_id)
      && typeof plan.plan_title === 'string'
      && isCount(plan.count)
  ))

  const validActivity = Array.isArray(payload.activity) && payload.activity.every((entry) => (
    hasKeys(entry, ['id', 'action', 'entity_type', 'entity_id', 'actor_type', 'actor_id', 'occurred_at'])
      && Number.isInteger(entry.id)
      && Number.isInteger(entry.entity_id)
      && Number.isInteger(entry.actor_id)
      && ['action', 'entity_type', 'actor_type'].every((key) => typeof entry[key] === 'string')
      && (entry.occurred_at === null || typeof entry.occurred_at === 'string')
  ))

  return validSummary && validReadiness && validAttention && validTrend && validDistribution && validActivity
}

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

function withOpacity(colour, opacity) {
  const hex = colour.match(/^#([\da-f]{2})([\da-f]{2})([\da-f]{2})$/i)
  if (hex) {
    return `rgba(${Number.parseInt(hex[1], 16)}, ${Number.parseInt(hex[2], 16)}, ${Number.parseInt(hex[3], 16)}, ${opacity})`
  }
  const rgb = colour.match(/^rgba?\((\d+),\s*(\d+),\s*(\d+)/i)
  return rgb ? `rgba(${rgb[1]}, ${rgb[2]}, ${rgb[3]}, ${opacity})` : colour
}

function formatCount(value) {
  return new Intl.NumberFormat().format(Number(value) || 0)
}

function distributionWidth(count) {
  return Math.max(0, Math.min(100, (Number(count) / largestPlanCount.value) * 100))
}

function safeSeverity(severity) {
  if (severity === 'error') return 'critical'
  return ['critical', 'warning', 'info'].includes(severity) ? severity : 'info'
}

function severityLabel(severity) {
  const labels = { critical: 'Critical', warning: 'Warning', info: 'Notice' }
  return labels[safeSeverity(severity)]
}

function safeDestination(destination) {
  return typeof destination === 'string' && destination.startsWith('/') ? destination : '/'
}

function actionLabel(entry) {
  let action = String(entry.action || '')
  let entityType = String(entry.entity_type || 'record')
  const compound = action.match(/^(grant|plan|protection|protection_rule|notification)_(.+)$/)
  if (compound) {
    entityType = compound[1]
    action = compound[2]
  }

  const labels = {
    grant: {
      created: 'Access granted',
      updated: 'Access updated',
      renewed: 'Access renewed',
      revoked: 'Access revoked',
      expired: 'Access expired',
      deleted: 'Access removed',
    },
    plan: {
      created: 'Plan created',
      updated: 'Plan updated',
      renewed: 'Plan renewed',
      revoked: 'Plan revoked',
      expired: 'Plan expired',
      deleted: 'Plan deleted',
    },
    protection: {
      created: 'Content protected',
      updated: 'Protection updated',
      revoked: 'Protection removed',
      expired: 'Protection expired',
      deleted: 'Protection removed',
    },
    protection_rule: {
      created: 'Content protected',
      updated: 'Protection updated',
      revoked: 'Protection removed',
      expired: 'Protection expired',
      deleted: 'Protection removed',
    },
    notification: {
      created: 'Notification created',
      updated: 'Notification updated',
      failed: 'Notification failed',
      sent: 'Notification sent',
      deleted: 'Notification removed',
    },
  }
  if (labels[entityType]?.[action]) return labels[entityType][action]

  const subject = entityTypeLabel(entityType)
  const verb = action.replaceAll('_', ' ') || 'activity recorded'
  return `${subject} ${verb}`.replace(/^./, (letter) => letter.toUpperCase())
}

function entityLabel(entry) {
  const type = entityTypeLabel(entry.entity_type)
  return entry.entity_id ? `${type} #${entry.entity_id}` : type
}

function entityTypeLabel(entityType) {
  const labels = {
    grant: 'Access',
    plan: 'Plan',
    protection: 'Protection',
    protection_rule: 'Protection',
    notification: 'Notification',
  }
  const value = String(entityType || 'record')
  return labels[value] || value.replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase())
}

function actorLabel(entry) {
  const type = String(entry.actor_type || 'system')
    .replaceAll('_', ' ')
    .replace(/^./, (letter) => letter.toUpperCase())
  return entry.actor_id ? `${type} #${entry.actor_id}` : type
}

function toIsoDateTime(value) {
  if (typeof value !== 'string' || !value.trim()) return ''
  const input = value.trim()
  const wpDateTime = input.match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(?::(\d{2}))?$/)
  if (wpDateTime) return `${wpDateTime[1]}T${wpDateTime[2]}:${wpDateTime[3] || '00'}`

  const parsed = new Date(input)
  return Number.isNaN(parsed.getTime()) ? '' : parsed.toISOString()
}

onMounted(() => {
  window.addEventListener(THEME_CHANGE_EVENT, resolveChartColours)
  loadDashboard()
})

onBeforeUnmount(() => {
  window.removeEventListener(THEME_CHANGE_EVENT, resolveChartColours)
})
</script>

<style scoped>
.dashboard-page,
.dashboard-content,
.dashboard-row,
.panel,
.summary-metric,
.readiness-step,
.activity-item {
  min-width: 0;
}

.dashboard-action {
  min-height: 34px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
  padding: 7px 13px;
  border: 1px solid var(--el-color-primary);
  border-radius: 7px;
  background: transparent;
  color: var(--el-color-primary);
  font: inherit;
  font-size: 13px;
  font-weight: 600;
  line-height: 1.35;
  text-decoration: none;
  cursor: pointer;
}

.dashboard-action--primary {
  background: var(--el-color-primary);
  color: #fff;
}

.dashboard-action:hover {
  border-color: var(--el-color-primary-light-3);
  background: var(--el-color-primary-light-9);
  color: var(--el-color-primary);
}

.dashboard-action--primary:hover {
  background: var(--el-color-primary-light-3);
  color: #fff;
}

.dashboard-action:focus-visible,
.summary-metric:focus-visible,
.attention-item:focus-visible,
.text-action:focus-visible {
  outline: 3px solid var(--el-color-primary-light-5);
  outline-offset: 2px;
}

.dashboard-content {
  display: grid;
  gap: 16px;
}

.panel,
.summary-metric,
.dashboard-error,
.skeleton-card,
.skeleton-panel {
  border: 1px solid var(--fchub-border-color);
  border-radius: 12px;
  background: var(--fchub-card-bg);
}

.panel {
  padding: 18px;
}

.section-heading {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 14px;
  margin-bottom: 14px;
}

.section-eyebrow {
  margin: 0 0 4px;
  color: var(--fchub-text-secondary);
  font-size: 10px;
  font-weight: 700;
  letter-spacing: .08em;
  text-transform: uppercase;
}

h2 {
  margin: 0;
  color: var(--fchub-text-primary);
  font-size: 17px;
  line-height: 1.3;
}

.section-count,
.readiness-score,
.trend-change {
  flex: 0 0 auto;
  padding: 4px 8px;
  border-radius: 999px;
  background: var(--fchub-page-bg);
  color: var(--fchub-text-secondary);
  font-size: 11px;
  font-weight: 600;
}

.attention-list {
  display: grid;
  gap: 8px;
}

.attention-item {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto auto;
  align-items: center;
  gap: 12px;
  padding: 11px 12px;
  border: 1px solid var(--fchub-border-color);
  border-left-width: 3px;
  border-radius: 8px;
  color: var(--fchub-text-primary);
  text-decoration: none;
}

.attention-item--critical { border-left-color: var(--el-color-danger); }
.attention-item--warning { border-left-color: var(--el-color-warning); }
.attention-item--info { border-left-color: var(--el-color-primary); }

.severity-label {
  color: var(--fchub-text-secondary);
  font-size: 10px;
  font-weight: 700;
  letter-spacing: .04em;
  text-transform: uppercase;
}

.attention-item--critical .severity-label {
  color: color-mix(in srgb, var(--el-color-danger) 78%, var(--fchub-text-primary));
}

.attention-item--warning .severity-label {
  color: color-mix(in srgb, var(--el-color-warning) 72%, var(--fchub-text-primary));
}

.attention-copy {
  display: flex;
  min-width: 0;
  flex-direction: column;
  gap: 2px;
}

.attention-copy strong,
.attention-copy span {
  overflow-wrap: anywhere;
}

.attention-copy strong { font-size: 13px; }
.attention-copy span { color: var(--fchub-text-secondary); font-size: 12px; line-height: 1.4; }

.attention-count {
  min-width: 26px;
  padding: 3px 7px;
  border-radius: 999px;
  background: var(--fchub-page-bg);
  color: var(--fchub-text-primary);
  font-size: 11px;
  font-weight: 700;
  text-align: center;
}

.attention-arrow { color: var(--fchub-text-secondary); }

.healthy-state {
  display: flex;
  align-items: center;
  gap: 10px;
  color: color-mix(in srgb, var(--el-color-success) 78%, var(--fchub-text-primary));
}

.healthy-state > .el-icon { flex: 0 0 auto; font-size: 20px; }
.healthy-state div { display: flex; flex-direction: column; gap: 1px; }
.healthy-state strong { color: var(--fchub-text-primary); font-size: 13px; }
.healthy-state span { color: var(--fchub-text-secondary); font-size: 12px; }

.summary-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
}

.summary-metric {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  column-gap: 10px;
  row-gap: 3px;
  padding: 15px;
  color: var(--fchub-text-primary);
  text-decoration: none;
  transition: border-color .15s, transform .15s;
}

.summary-metric:hover {
  border-color: var(--el-color-primary-light-5);
  transform: translateY(-1px);
}

.summary-icon {
  grid-row: 1 / 4;
  width: 34px;
  height: 34px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 9px;
}

.summary-icon--blue { background: var(--fchub-stat-blue-bg); color: var(--fchub-stat-blue); }
.summary-icon--orange { background: var(--fchub-stat-orange-bg); color: var(--fchub-stat-orange); }
.summary-icon--purple { background: var(--fchub-stat-purple-bg); color: var(--fchub-stat-purple); }
.summary-icon--pink { background: var(--fchub-stat-pink-bg); color: var(--fchub-stat-pink); }

.summary-label { color: var(--fchub-text-secondary); font-size: 11px; font-weight: 600; }
.summary-value { font-size: 23px; line-height: 1.05; }
.summary-support { color: var(--fchub-text-secondary); font-size: 10px; line-height: 1.35; }

.dashboard-row {
  display: grid;
  gap: 16px;
}

.dashboard-row--readiness { grid-template-columns: minmax(0, 1.1fr) minmax(0, .9fr); }
.dashboard-row--details { grid-template-columns: minmax(280px, .8fr) minmax(0, 1.2fr); }

.readiness-list,
.distribution-list,
.activity-list {
  display: grid;
  gap: 0;
  margin: 0;
  padding: 0;
  list-style: none;
}

.readiness-step {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  align-items: center;
  gap: 10px;
  padding: 10px 0;
  border-top: 1px solid var(--fchub-border-color);
}

.readiness-step:first-child,
.activity-item:first-child { border-top: 0; padding-top: 0; }
.readiness-step:last-child,
.activity-item:last-child { padding-bottom: 0; }

.readiness-icon {
  width: 25px;
  height: 25px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  background: var(--fchub-page-bg);
  color: var(--fchub-text-secondary);
}

.readiness-icon.is-complete {
  background: color-mix(in srgb, var(--el-color-success) 14%, var(--fchub-card-bg));
  color: color-mix(in srgb, var(--el-color-success) 78%, var(--fchub-text-primary));
}
.readiness-copy { display: flex; min-width: 0; flex-direction: column; gap: 2px; }
.readiness-copy strong { font-size: 12px; }
.readiness-copy span { color: var(--fchub-text-secondary); font-size: 11px; line-height: 1.35; }
.readiness-value { font-size: 13px; }

.text-action {
  display: inline-flex;
  grid-column: 2 / 4;
  align-items: center;
  gap: 4px;
  width: fit-content;
  color: var(--el-color-primary);
  font-size: 11px;
  font-weight: 600;
  text-decoration: none;
}

.trend-panel { display: flex; min-height: 220px; flex-direction: column; }
.trend-chart {
  height: 168px;
  display: flex;
  flex-direction: column;
  gap: 5px;
  margin-top: auto;
}

.trend-plot {
  position: relative;
  min-height: 0;
  flex: 1;
}

.trend-summary {
  margin: 0;
  color: var(--fchub-text-secondary);
  font-size: 10px;
  line-height: 1.35;
}

.compact-empty {
  display: flex;
  min-height: 96px;
  align-items: center;
  gap: 11px;
  margin: auto 0;
  padding: 12px;
  border-radius: 8px;
  background: var(--fchub-page-bg);
}

.compact-empty > .el-icon { flex: 0 0 auto; color: var(--fchub-text-secondary); font-size: 21px; }
.compact-empty > div { display: flex; min-width: 0; flex: 1; flex-direction: column; gap: 2px; }
.compact-empty strong { font-size: 12px; }
.compact-empty span { color: var(--fchub-text-secondary); font-size: 11px; line-height: 1.4; }
.compact-empty .text-action { flex: 0 0 auto; }

.distribution-row { padding: 9px 0; }
.distribution-row:first-child { padding-top: 0; }
.distribution-row:last-child { padding-bottom: 0; }
.distribution-meta { display: grid; grid-template-columns: 20px minmax(0, 1fr) auto; align-items: center; gap: 8px; margin-bottom: 6px; }
.distribution-rank { color: var(--fchub-text-secondary); font-size: 10px; font-weight: 700; }
.distribution-meta strong { overflow: hidden; font-size: 12px; text-overflow: ellipsis; white-space: nowrap; }
.distribution-meta > span:last-child { color: var(--fchub-text-secondary); font-size: 11px; font-weight: 600; }
.distribution-track { height: 5px; margin-left: 28px; overflow: hidden; border-radius: 999px; background: var(--fchub-page-bg); }
.distribution-track span { display: block; height: 100%; border-radius: inherit; background: var(--el-color-primary); }

.activity-item {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  align-items: center;
  gap: 10px;
  padding: 9px 0;
  border-top: 1px solid var(--fchub-border-color);
}

.activity-icon {
  width: 28px;
  height: 28px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 8px;
  background: var(--fchub-page-bg);
  color: var(--el-color-primary);
}

.activity-copy { display: flex; min-width: 0; flex-direction: column; gap: 2px; }
.activity-copy strong { font-size: 12px; }
.activity-copy span { color: var(--fchub-text-secondary); font-size: 10px; overflow-wrap: anywhere; }
.activity-item time { color: var(--fchub-text-secondary); font-size: 10px; white-space: nowrap; }

.dashboard-error {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 18px;
}

.error-icon {
  width: 38px;
  height: 38px;
  display: inline-flex;
  flex: 0 0 auto;
  align-items: center;
  justify-content: center;
  border-radius: 9px;
  background: color-mix(in srgb, var(--el-color-danger) 12%, var(--fchub-card-bg));
  color: color-mix(in srgb, var(--el-color-danger) 78%, var(--fchub-text-primary));
  font-size: 19px;
}

.error-copy { min-width: 0; flex: 1; }
.error-copy h2 { margin-bottom: 3px; }
.error-copy p { margin: 0; color: var(--fchub-text-secondary); font-size: 12px; overflow-wrap: anywhere; }

.dashboard-skeleton { display: grid; gap: 16px; }
.skeleton-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
.skeleton-card { height: 108px; padding: 18px; }
.skeleton-panels { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
.skeleton-panel { height: 220px; }
.skeleton-line,
.skeleton-value {
  border-radius: 999px;
  background: linear-gradient(90deg, var(--fchub-page-bg), var(--fchub-border-color), var(--fchub-page-bg));
  background-size: 200% 100%;
  animation: skeleton-pulse 1.4s ease-in-out infinite;
}
.skeleton-line { width: 65%; height: 9px; }
.skeleton-line--short { width: 120px; }
.skeleton-value { width: 42%; height: 24px; margin-top: 17px; }

@keyframes skeleton-pulse {
  from { background-position: 200% 0; }
  to { background-position: -200% 0; }
}

@media (prefers-reduced-motion: reduce) {
  .skeleton-line,
  .skeleton-value { animation: none; }
}

@media (max-width: 980px) {
  .summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .dashboard-row--readiness,
  .dashboard-row--details { grid-template-columns: 1fr; }
}

@media (max-width: 782px) {
  .dashboard-content { gap: 12px; }
  .panel { padding: 14px; }
  .attention-section { order: 1; }
  .provider-health { order: 2; }
  .summary-grid { order: 2; gap: 8px; }
  .dashboard-row--readiness { order: 3; gap: 12px; }
  .dashboard-row--details { order: 4; gap: 12px; }
  .summary-metric { padding: 12px; column-gap: 8px; }
  .summary-icon { width: 30px; height: 30px; }
  .summary-value { font-size: 20px; }
  .summary-support { font-size: 9px; }
  .trend-panel { min-height: 0; }
  .trend-chart { height: 148px; }
  .compact-empty { min-height: 0; align-items: flex-start; flex-wrap: wrap; }
  .compact-empty .text-action { margin-left: 32px; }
  .attention-item { grid-template-columns: minmax(0, 1fr) auto; gap: 6px 10px; }
  .severity-label { grid-column: 1; }
  .attention-copy { grid-column: 1; }
  .attention-count { grid-column: 2; grid-row: 1; }
  .attention-arrow { grid-column: 2; grid-row: 2; }
  .activity-item { grid-template-columns: auto minmax(0, 1fr); }
  .activity-item time { grid-column: 2; white-space: normal; }
  .dashboard-error { align-items: flex-start; flex-wrap: wrap; }
  .dashboard-error .dashboard-action { width: 100%; }
  .skeleton-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .skeleton-panels { grid-template-columns: 1fr; }
  .skeleton-panel { height: 150px; }
}

@media (max-width: 390px) {
  .summary-metric { grid-template-columns: 1fr; }
  .summary-icon { display: none; }
  .readiness-step { grid-template-columns: auto minmax(0, 1fr) auto; }
}
</style>
