<template>
  <div class="reports-page">
    <div class="page-header">
      <h2 class="fchub-page-title">Reports</h2>
      <el-date-picker
        v-model="dateRange"
        type="daterange"
        range-separator="to"
        start-placeholder="Start date"
        end-placeholder="End date"
        :format="wpDatePickerFormat"
        value-format="YYYY-MM-DD"
        :shortcuts="dateShortcuts"
        @change="onDateRangeChange"
      />
    </div>

    <el-tabs v-model="activeTab" @tab-change="onTabChange">
      <ReportsOverviewTab
        :overview-loading="overviewLoading"
        :chart-loading="membersChartLoading"
        :overview="overview"
        :chart-data="membersChartData"
        :line-chart-options="lineChartOptions"
      />
      <ReportsPlansTab
        :loading="plansLoading"
        :chart-data="planChartData"
        :rows="planRows"
        :doughnut-chart-options="doughnutChartOptions"
      />
      <ReportsChurnTab
        :loading="churnLoading"
        :chart-data="churnChartData"
        :details="churnDetails"
        :chart-options="churnChartOptions"
      />
      <ReportsRevenueTab
        :loading="revenueLoading"
        :chart-data="revenueChartData"
        :metrics="revenueMetrics"
        :chart-options="barChartOptions"
      />
      <ReportsRenewalsTab
        :loading="renewalLoading"
        :data="renewalData"
        :chart-data="renewalChartData"
        :line-chart-options="lineChartOptions"
      />
      <ReportsTrialsTab
        :loading="trialLoading"
        :data="trialData"
      />
      <ReportsContentTab
        :loading="contentLoading"
        :rows="contentRows"
      />
    </el-tabs>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  BarElement,
  ArcElement,
  Title,
  Tooltip,
  Legend,
  Filler,
} from 'chart.js'
import { reports } from '@/api/index.js'
import { currencyTickFormatter } from '@/utils/currency.js'
import { formatReportPeriodLabel, wpDatePickerFormat } from '@/utils/wpDate.js'
import ReportsOverviewTab from '@/components/reports/ReportsOverviewTab.vue'
import ReportsPlansTab from '@/components/reports/ReportsPlansTab.vue'
import ReportsChurnTab from '@/components/reports/ReportsChurnTab.vue'
import ReportsRevenueTab from '@/components/reports/ReportsRevenueTab.vue'
import ReportsRenewalsTab from '@/components/reports/ReportsRenewalsTab.vue'
import ReportsTrialsTab from '@/components/reports/ReportsTrialsTab.vue'
import ReportsContentTab from '@/components/reports/ReportsContentTab.vue'

ChartJS.register(
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  BarElement,
  ArcElement,
  Title,
  Tooltip,
  Legend,
  Filler,
)

const chartColors = [
  '#4D6EF5',
  '#F5A623',
  '#F56C9E',
  '#8B5CF6',
  '#36CFC9',
  '#67C23A',
  '#909399',
  '#FF85C0',
]

const activeTab = ref('overview')

// Date range
const dateRange = ref(null)

const dateShortcuts = [
  {
    text: 'Last 7 days',
    value: () => {
      const end = new Date()
      const start = new Date()
      start.setDate(start.getDate() - 7)
      return [start, end]
    },
  },
  {
    text: 'Last 30 days',
    value: () => {
      const end = new Date()
      const start = new Date()
      start.setDate(start.getDate() - 30)
      return [start, end]
    },
  },
  {
    text: 'Last 90 days',
    value: () => {
      const end = new Date()
      const start = new Date()
      start.setDate(start.getDate() - 90)
      return [start, end]
    },
  },
  {
    text: 'This year',
    value: () => {
      const end = new Date()
      const start = new Date(end.getFullYear(), 0, 1)
      return [start, end]
    },
  },
]

function getDateParams() {
  if (!dateRange.value || dateRange.value.length !== 2) return {}
  return {
    start_date: dateRange.value[0],
    end_date: dateRange.value[1],
  }
}

// Overview
const overviewLoading = ref(false)
const overview = ref({
  active_members: 0,
  new_this_month: 0,
  churned: 0,
  retention_rate: 0,
})
const membersChartLoading = ref(false)
const membersChartData = ref(null)

// Plans
const plansLoading = ref(false)
const planChartData = ref(null)
const planRows = ref([])

// Churn
const churnLoading = ref(false)
const churnChartData = ref(null)
const churnDetails = ref([])

// Revenue
const revenueLoading = ref(false)
const revenueChartData = ref(null)
const revenueMetrics = ref({
  mrr: 0,
  arpm: 0,
  ltv: 0,
})

// Renewals
const renewalLoading = ref(false)
const renewalData = ref({})
const renewalChartData = ref(null)

// Trials
const trialLoading = ref(false)
const trialData = ref({})

// Content
const contentLoading = ref(false)
const contentRows = ref([])

// Chart options
const lineChartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
  },
  scales: {
    y: {
      beginAtZero: true,
      ticks: { precision: 0 },
    },
  },
}

const churnChartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
  },
  scales: {
    y: {
      beginAtZero: true,
      ticks: {
        callback: (value) => value + '%',
      },
    },
  },
}

const doughnutChartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { position: 'bottom' },
  },
}

const barChartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
  },
  scales: {
    y: {
      beginAtZero: true,
      ticks: {
        callback: currencyTickFormatter,
      },
    },
  },
}

// Tab data loaders
const tabLoaded = reactive({
  overview: false,
  plans: false,
  churn: false,
  revenue: false,
  renewals: false,
  trials: false,
  content: false,
})

async function loadOverview() {
  overviewLoading.value = true
  membersChartLoading.value = true
  try {
    const params = getDateParams()
    const response = await reports.overview(params)
    const data = response.data ?? response
    overview.value = {
      active_members: data.active_members ?? 0,
      new_this_month: data.new_this_month ?? 0,
      churned: data.churned_this_month ?? 0,
      retention_rate: data.churn_rate != null ? Math.round(100 - data.churn_rate) : 100,
    }
  } catch {
    // defaults
  } finally {
    overviewLoading.value = false
  }

  try {
    const params = getDateParams()
    const response = await reports.membersOverTime(params)
    const rows = response.data ?? response
    membersChartData.value = {
      labels: rows.map((r) => formatReportPeriodLabel(r.date)),
      datasets: [
        {
          label: 'Members',
          data: rows.map((r) => r.count),
          borderColor: '#4D6EF5',
          backgroundColor: 'rgba(77, 110, 245, 0.1)',
          fill: true,
          tension: 0.3,
        },
      ],
    }
  } catch {
    membersChartData.value = null
  } finally {
    membersChartLoading.value = false
  }

  tabLoaded.overview = true
}

async function loadPlans() {
  plansLoading.value = true
  try {
    const params = getDateParams()
    const response = await reports.planDistribution(params)
    const rows = response.data ?? response

    const labels = rows.map((r) => r.plan_title)
    const values = rows.map((r) => r.count)
    const totalMembers = values.reduce((sum, v) => sum + v, 0)

    planChartData.value = {
      labels,
      datasets: [
        {
          data: values,
          backgroundColor: chartColors.slice(0, labels.length),
        },
      ],
    }

    planRows.value = rows.map((r) => ({
      title: r.plan_title,
      members_count: r.count ?? 0,
      percentage: totalMembers > 0 ? Math.round((r.count / totalMembers) * 100) : 0,
      status: 'active',
    }))
  } catch {
    planChartData.value = null
    planRows.value = []
  } finally {
    plansLoading.value = false
  }

  tabLoaded.plans = true
}

async function loadChurn() {
  churnLoading.value = true
  try {
    const params = getDateParams()
    const response = await reports.churn(params)
    const data = response.data ?? response
    const overTime = data.over_time ?? []

    churnChartData.value = {
      labels: overTime.map((r) => formatReportPeriodLabel(r.month ?? r.period ?? r.date)),
      datasets: [
        {
          label: 'Churn Rate',
          data: overTime.map((r) => r.churn_rate ?? r.rate ?? 0),
          borderColor: '#F56C9E',
          backgroundColor: 'rgba(245, 108, 158, 0.1)',
          fill: true,
          tension: 0.3,
        },
      ],
    }

    churnDetails.value = overTime.map((r) => ({
      period: formatReportPeriodLabel(r.month ?? r.period ?? r.date),
      churned: r.churned ?? 0,
      total: r.active_start ?? r.total ?? 0,
      rate: r.churn_rate ?? r.rate ?? 0,
    }))
  } catch {
    churnChartData.value = null
    churnDetails.value = []
  } finally {
    churnLoading.value = false
  }

  tabLoaded.churn = true
}

async function loadRevenue() {
  revenueLoading.value = true
  try {
    const params = getDateParams()
    const response = await reports.revenue(params)
    const data = response.data ?? response

    revenueMetrics.value = {
      mrr: data.mrr ?? 0,
      arpm: data.arpm ?? 0,
      ltv: (data.ltv ?? []).reduce((sum, r) => sum + (r.ltv ?? 0), 0) / Math.max((data.ltv ?? []).length, 1),
    }

    const perPlan = data.per_plan ?? []
    if (perPlan.length > 0) {
      revenueChartData.value = {
        labels: perPlan.map((r) => r.plan_title ?? r.label),
        datasets: [
          {
            label: 'Revenue',
            data: perPlan.map((r) => r.revenue ?? r.value ?? 0),
            backgroundColor: chartColors.slice(0, perPlan.length),
          },
        ],
      }
    } else {
      revenueChartData.value = null
    }
  } catch {
    revenueMetrics.value = { mrr: 0, arpm: 0, ltv: 0 }
    revenueChartData.value = null
  } finally {
    revenueLoading.value = false
  }

  tabLoaded.revenue = true
}

async function loadRenewals() {
  renewalLoading.value = true
  try {
    const params = getDateParams()
    const res = await reports.renewalRate(params)
    const data = res.data ?? res ?? {}
    renewalData.value = data

    const overTime = data.over_time ?? []
    if (overTime.length > 0) {
      renewalChartData.value = {
        labels: overTime.map((r) => formatReportPeriodLabel(r.month)),
        datasets: [
          {
            label: 'Renewals',
            data: overTime.map((r) => parseInt(r.total_renewals) || 0),
            borderColor: '#4D6EF5',
            backgroundColor: 'rgba(77, 110, 245, 0.1)',
            fill: true,
            tension: 0.3,
          },
        ],
      }
    } else {
      renewalChartData.value = null
    }
  } catch {
    renewalData.value = {}
    renewalChartData.value = null
  } finally {
    renewalLoading.value = false
  }
  tabLoaded.renewals = true
}

async function loadTrials() {
  trialLoading.value = true
  try {
    const params = getDateParams()
    const res = await reports.trialConversion(params)
    trialData.value = res.data ?? res ?? {}
  } catch {
    trialData.value = {}
  } finally {
    trialLoading.value = false
  }
  tabLoaded.trials = true
}

async function loadContent() {
  contentLoading.value = true
  try {
    const params = getDateParams()
    const response = await reports.contentPopularity(params)
    const data = response.data ?? response
    contentRows.value = data.most_accessed ?? data ?? []
  } catch {
    contentRows.value = []
  } finally {
    contentLoading.value = false
  }

  tabLoaded.content = true
}

function loadTabData(tab) {
  switch (tab) {
    case 'overview':
      loadOverview()
      break
    case 'plans':
      loadPlans()
      break
    case 'churn':
      loadChurn()
      break
    case 'revenue':
      loadRevenue()
      break
    case 'renewals':
      loadRenewals()
      break
    case 'trials':
      loadTrials()
      break
    case 'content':
      loadContent()
      break
  }
}

function onTabChange(tab) {
  if (!tabLoaded[tab]) {
    loadTabData(tab)
  }
}

function onDateRangeChange() {
  // Reset all tabs so they reload with new dates
  Object.keys(tabLoaded).forEach((key) => {
    tabLoaded[key] = false
  })
  // Reload current tab
  loadTabData(activeTab.value)
}

onMounted(() => {
  loadOverview()
})
</script>

<style scoped>
.page-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 20px;
}

.page-header :deep(.el-date-editor--daterange) {
  max-width: 420px;
}

.fchub-stat-grid--2 {
  grid-template-columns: repeat(2, 1fr);
}

.fchub-stat-grid--3 {
  grid-template-columns: repeat(3, 1fr);
}

.chart-container {
  height: 320px;
  position: relative;
}

.chart-card {
  margin-bottom: 20px;
}
</style>
