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
      <!-- Overview Tab -->
      <el-tab-pane label="Overview" name="overview">
        <div class="fchub-stat-grid" v-loading="overviewLoading">
          <div class="fchub-stat-widget">
            <div class="fchub-stat-icon blue">
              <el-icon :size="20"><UserFilled /></el-icon>
            </div>
            <div class="fchub-stat-title">Active Members</div>
            <div class="fchub-stat-value">{{ overview.active_members }}</div>
          </div>
          <div class="fchub-stat-widget">
            <div class="fchub-stat-icon orange">
              <el-icon :size="20"><Plus /></el-icon>
            </div>
            <div class="fchub-stat-title">New This Month</div>
            <div class="fchub-stat-value">{{ overview.new_this_month }}</div>
          </div>
          <div class="fchub-stat-widget">
            <div class="fchub-stat-icon pink">
              <el-icon :size="20"><Remove /></el-icon>
            </div>
            <div class="fchub-stat-title">Churned</div>
            <div class="fchub-stat-value">{{ overview.churned }}</div>
          </div>
          <div class="fchub-stat-widget">
            <div class="fchub-stat-icon purple">
              <el-icon :size="20"><TrendCharts /></el-icon>
            </div>
            <div class="fchub-stat-title">Retention Rate</div>
            <div class="fchub-stat-value">{{ overview.retention_rate }}%</div>
          </div>
        </div>

        <el-card shadow="never" v-loading="membersChartLoading">
          <template #header>
            <span>Members Over Time</span>
          </template>
          <div class="chart-container">
            <Line v-if="membersChartData" :data="membersChartData" :options="lineChartOptions" />
            <el-empty v-else-if="!membersChartLoading" description="No data available" />
          </div>
        </el-card>
      </el-tab-pane>

      <!-- Plans Tab -->
      <el-tab-pane label="Plans" name="plans">
        <el-row :gutter="20" v-loading="plansLoading">
          <el-col :span="10">
            <el-card shadow="never">
              <template #header>
                <span>Plan Distribution</span>
              </template>
              <div class="chart-container">
                <Doughnut v-if="planChartData" :data="planChartData" :options="doughnutChartOptions" />
                <el-empty v-else-if="!plansLoading" description="No data available" />
              </div>
            </el-card>
          </el-col>
          <el-col :span="14">
            <el-card shadow="never">
              <template #header>
                <span>Plans by Member Count</span>
              </template>
              <el-table :data="planRows" style="width: 100%">
                <el-table-column prop="title" label="Plan" min-width="180" />
                <el-table-column prop="members_count" label="Members" width="120" align="center" />
                <el-table-column label="Share" width="120" align="center">
                  <template #default="{ row }">
                    {{ row.percentage }}%
                  </template>
                </el-table-column>
                <el-table-column label="Status" width="100">
                  <template #default="{ row }">
                    <el-tag :type="row.status === 'active' ? 'success' : 'info'" size="small">
                      {{ row.status }}
                    </el-tag>
                  </template>
                </el-table-column>
              </el-table>
            </el-card>
          </el-col>
        </el-row>
      </el-tab-pane>

      <!-- Churn Tab -->
      <el-tab-pane label="Churn" name="churn">
        <el-card shadow="never" v-loading="churnLoading" class="chart-card">
          <template #header>
            <span>Churn Rate Over Time</span>
          </template>
          <div class="chart-container">
            <Line v-if="churnChartData" :data="churnChartData" :options="churnChartOptions" />
            <el-empty v-else-if="!churnLoading" description="No data available" />
          </div>
        </el-card>

        <el-card shadow="never" v-loading="churnLoading" style="margin-top: 20px">
          <template #header>
            <span>Churn Details</span>
          </template>
          <el-table :data="churnDetails" style="width: 100%">
            <el-table-column prop="period" label="Period" min-width="140" />
            <el-table-column prop="churned" label="Churned" width="120" align="center" />
            <el-table-column prop="total" label="Total Members" width="140" align="center" />
            <el-table-column label="Churn Rate" width="130" align="center">
              <template #default="{ row }">
                {{ row.rate }}%
              </template>
            </el-table-column>
          </el-table>
        </el-card>
      </el-tab-pane>

      <!-- Revenue Tab -->
      <el-tab-pane label="Revenue" name="revenue">
        <div class="fchub-stat-grid fchub-stat-grid--3" v-loading="revenueLoading">
          <div class="fchub-stat-widget">
            <div class="fchub-stat-icon blue">
              <el-icon :size="20"><Money /></el-icon>
            </div>
            <div class="fchub-stat-title">MRR</div>
            <div class="fchub-stat-value">${{ revenueMetrics.mrr.toFixed(2) }}</div>
          </div>
          <div class="fchub-stat-widget">
            <div class="fchub-stat-icon orange">
              <el-icon :size="20"><UserFilled /></el-icon>
            </div>
            <div class="fchub-stat-title">Avg Revenue Per Member</div>
            <div class="fchub-stat-value">${{ revenueMetrics.arpm.toFixed(2) }}</div>
          </div>
          <div class="fchub-stat-widget">
            <div class="fchub-stat-icon pink">
              <el-icon :size="20"><TrendCharts /></el-icon>
            </div>
            <div class="fchub-stat-title">Lifetime Value</div>
            <div class="fchub-stat-value">${{ revenueMetrics.ltv.toFixed(2) }}</div>
          </div>
        </div>

        <el-card shadow="never" v-loading="revenueLoading" class="chart-card">
          <template #header>
            <span>Revenue Per Plan</span>
          </template>
          <div class="chart-container">
            <Bar v-if="revenueChartData" :data="revenueChartData" :options="barChartOptions" />
            <el-empty v-else-if="!revenueLoading" description="No data available" />
          </div>
        </el-card>
      </el-tab-pane>

      <!-- Renewals Tab -->
      <el-tab-pane label="Renewals" name="renewals" :lazy="true">
        <div v-loading="renewalLoading">
          <div class="fchub-stat-grid fchub-stat-grid--3" style="margin-bottom: 20px">
            <div class="fchub-stat-widget">
              <div class="fchub-stat-icon blue">
                <el-icon :size="20"><TrendCharts /></el-icon>
              </div>
              <div class="fchub-stat-title">Renewal Rate</div>
              <div class="fchub-stat-value">{{ renewalData.overall_rate || 0 }}%</div>
            </div>
            <div class="fchub-stat-widget">
              <div class="fchub-stat-icon orange">
                <el-icon :size="20"><UserFilled /></el-icon>
              </div>
              <div class="fchub-stat-title">Renewed Members</div>
              <div class="fchub-stat-value">{{ renewalData.renewed_members || 0 }}</div>
            </div>
            <div class="fchub-stat-widget">
              <div class="fchub-stat-icon purple">
                <el-icon :size="20"><TrendCharts /></el-icon>
              </div>
              <div class="fchub-stat-title">Avg Renewals Per Member</div>
              <div class="fchub-stat-value">{{ renewalData.avg_renewals_per_member || 0 }}</div>
            </div>
          </div>

          <el-card shadow="never" class="chart-card" v-if="renewalChartData">
            <template #header>
              <span>Renewals Over Time</span>
            </template>
            <div class="chart-container">
              <Line :data="renewalChartData" :options="lineChartOptions" />
            </div>
          </el-card>

          <el-card shadow="never" style="margin-top: 20px">
            <template #header>
              <span>Renewals by Plan</span>
            </template>
            <el-table :data="renewalData.by_plan || []">
              <el-table-column prop="plan_title" label="Plan" />
              <el-table-column prop="total_members" label="Total Members" width="140" align="center" />
              <el-table-column prop="renewed_members" label="Renewed" width="120" align="center" />
              <el-table-column label="Rate" width="100" align="center">
                <template #default="{ row }">
                  {{ row.total_members > 0 ? ((row.renewed_members / row.total_members) * 100).toFixed(1) : 0 }}%
                </template>
              </el-table-column>
              <el-table-column label="Avg Renewals" width="130" align="center">
                <template #default="{ row }">
                  {{ parseFloat(row.avg_renewals || 0).toFixed(1) }}
                </template>
              </el-table-column>
            </el-table>
          </el-card>
        </div>
      </el-tab-pane>

      <!-- Trials Tab -->
      <el-tab-pane label="Trials" name="trials" :lazy="true">
        <div v-loading="trialLoading">
          <div class="fchub-stat-grid" style="margin-bottom: 20px">
            <div class="fchub-stat-widget">
              <div class="fchub-stat-icon blue">
                <el-icon :size="20"><TrendCharts /></el-icon>
              </div>
              <div class="fchub-stat-title">Conversion Rate</div>
              <div class="fchub-stat-value">{{ trialData.overall_rate || 0 }}%</div>
            </div>
            <div class="fchub-stat-widget">
              <div class="fchub-stat-icon orange">
                <el-icon :size="20"><UserFilled /></el-icon>
              </div>
              <div class="fchub-stat-title">Total Trials</div>
              <div class="fchub-stat-value">{{ trialData.total_trials || 0 }}</div>
            </div>
            <div class="fchub-stat-widget">
              <div class="fchub-stat-icon purple">
                <el-icon :size="20"><Plus /></el-icon>
              </div>
              <div class="fchub-stat-title">Converted</div>
              <div class="fchub-stat-value">{{ trialData.total_converted || 0 }}</div>
            </div>
            <div class="fchub-stat-widget">
              <div class="fchub-stat-icon pink">
                <el-icon :size="20"><Remove /></el-icon>
              </div>
              <div class="fchub-stat-title">Expired</div>
              <div class="fchub-stat-value">{{ trialData.total_dropped || 0 }}</div>
            </div>
          </div>
          <el-card shadow="never">
            <template #header>
              <span>Trial Conversion by Plan</span>
            </template>
            <el-table :data="trialData.by_plan || []">
              <el-table-column prop="plan_title" label="Plan" />
              <el-table-column prop="total_trials" label="Trials" width="100" align="center" />
              <el-table-column prop="converted" label="Converted" width="120" align="center" />
              <el-table-column prop="dropped" label="Expired" width="100" align="center" />
              <el-table-column label="Rate" width="100" align="center">
                <template #default="{ row }">
                  {{ row.total_trials > 0 ? ((row.converted / row.total_trials) * 100).toFixed(1) : 0 }}%
                </template>
              </el-table-column>
            </el-table>
          </el-card>
        </div>
      </el-tab-pane>

      <!-- Content Tab -->
      <el-tab-pane label="Content" name="content">
        <el-card shadow="never" v-loading="contentLoading">
          <template #header>
            <span>Content Popularity</span>
          </template>
          <el-table :data="contentRows" style="width: 100%">
            <el-table-column prop="title" label="Content" min-width="240" />
            <el-table-column prop="resource_type" label="Type" width="120">
              <template #default="{ row }">
                <el-tag size="small">{{ row.resource_type }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="member_count" label="Members" width="120" align="center" sortable />
          </el-table>
        </el-card>
      </el-tab-pane>
    </el-tabs>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { Line, Bar, Doughnut } from 'vue-chartjs'
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
import { formatReportPeriodLabel, wpDatePickerFormat } from '@/utils/wpDate.js'

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
        callback: (value) => '$' + value,
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
