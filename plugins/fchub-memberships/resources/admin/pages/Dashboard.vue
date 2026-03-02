<template>
  <div class="dashboard-page">
    <h2 class="fchub-page-title">Dashboard</h2>

    <!-- Stats Row -->
    <div class="fchub-stat-grid" v-loading="statsLoading">
      <div class="fchub-stat-widget">
        <div class="fchub-stat-icon blue">
          <el-icon :size="20"><UserFilled /></el-icon>
        </div>
        <div class="fchub-stat-title">Active Members</div>
        <div class="fchub-stat-value">{{ stats.active_members }}</div>
      </div>
      <div class="fchub-stat-widget">
        <div class="fchub-stat-icon orange">
          <el-icon :size="20"><Plus /></el-icon>
        </div>
        <div class="fchub-stat-title">New This Month</div>
        <div class="fchub-stat-value">{{ stats.new_this_month }}</div>
      </div>
      <div class="fchub-stat-widget">
        <div class="fchub-stat-icon pink">
          <el-icon :size="20"><RemoveFilled /></el-icon>
        </div>
        <div class="fchub-stat-title">Churned This Month</div>
        <div class="fchub-stat-value">{{ stats.churned_this_month }}</div>
      </div>
      <div class="fchub-stat-widget">
        <div class="fchub-stat-icon purple">
          <el-icon :size="20"><TrendCharts /></el-icon>
        </div>
        <div class="fchub-stat-title">Churn Rate</div>
        <div class="fchub-stat-value">{{ stats.churn_rate }}%</div>
      </div>
    </div>

    <!-- Quick Actions -->
    <div class="quick-actions">
      <router-link to="/plans/create">
        <el-button type="primary" size="small"><el-icon><Plus /></el-icon> Create New Plan</el-button>
      </router-link>
      <router-link to="/members">
        <el-button size="small" text type="primary">View All Members →</el-button>
      </router-link>
    </div>

    <!-- Charts Row -->
    <el-row :gutter="20" class="charts-row">
      <el-col :span="16">
        <el-card shadow="never" v-loading="membersChartLoading">
          <template #header>
            <span>Members Over Time</span>
          </template>
          <div class="chart-container">
            <Line v-if="membersChartData" :data="membersChartData" :options="lineChartOptions" />
          </div>
        </el-card>
      </el-col>
      <el-col :span="8">
        <el-card shadow="never" v-loading="planChartLoading">
          <template #header>
            <span>Plan Distribution</span>
          </template>
          <div class="chart-container">
            <Doughnut v-if="planChartData" :data="planChartData" :options="doughnutChartOptions" />
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- Expiring Soon -->
    <el-card shadow="never" class="expiring-soon" v-loading="expiringLoading">
      <template #header>
        <span>Expiring Soon</span>
      </template>
      <el-table v-if="expiringSoon.length > 0" :data="expiringSoon" stripe style="width: 100%">
        <el-table-column prop="user_name" label="Member" min-width="160">
          <template #default="{ row }">
            <div>{{ row.user_name }}</div>
            <div style="font-size: 12px; color: #909399">{{ row.user_email }}</div>
          </template>
        </el-table-column>
        <el-table-column prop="plan_title" label="Plan" min-width="140" />
        <el-table-column prop="expires_at" label="Expires" width="160" />
      </el-table>
      <el-empty v-else description="No memberships expiring in the next 7 days" :image-size="40" />
    </el-card>

    <!-- Recent Activity -->
    <el-card shadow="never" class="recent-activity" v-loading="activityLoading">
      <template #header>
        <span>Recent Activity</span>
      </template>
      <el-table :data="recentGrants" stripe style="width: 100%">
        <el-table-column prop="user_email" label="Member" min-width="200" />
        <el-table-column prop="plan_title" label="Plan" min-width="160" />
        <el-table-column prop="status" label="Status" width="120">
          <template #default="{ row }">
            <el-tag
              :type="statusTagType(row.status)"
              size="small"
            >
              {{ row.status }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="expires_at" label="Expires" width="160">
          <template #default="{ row }">
            {{ row.expires_at || 'Never' }}
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="Granted" width="160" />
      </el-table>
    </el-card>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { Line, Doughnut } from 'vue-chartjs'
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  ArcElement,
  Title,
  Tooltip,
  Legend,
  Filler,
} from 'chart.js'
import { reports, members } from '@/api/index.js'

ChartJS.register(
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  ArcElement,
  Title,
  Tooltip,
  Legend,
  Filler,
)

const stats = ref({
  active_members: 0,
  new_this_month: 0,
  churned_this_month: 0,
  churn_rate: 0,
})
const statsLoading = ref(false)

const membersChartData = ref(null)
const membersChartLoading = ref(false)

const planChartData = ref(null)
const planChartLoading = ref(false)

const expiringSoon = ref([])
const expiringLoading = ref(false)

const recentGrants = ref([])
const activityLoading = ref(false)

const lineChartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: {
      display: false,
    },
  },
  scales: {
    y: {
      beginAtZero: true,
      ticks: {
        precision: 0,
      },
    },
  },
}

const doughnutChartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: {
      position: 'bottom',
    },
  },
}

function statusTagType(status) {
  const map = {
    active: 'success',
    expired: 'info',
    revoked: 'danger',
    pending: 'warning',
  }
  return map[status] || 'info'
}

async function loadStats() {
  statsLoading.value = true
  try {
    const response = await reports.overview()
    const data = response.data ?? response
    stats.value = {
      active_members: data.active_members ?? 0,
      new_this_month: data.new_this_month ?? 0,
      churned_this_month: data.churned_this_month ?? 0,
      churn_rate: data.churn_rate ?? 0,
    }
  } catch {
    // stats remain at defaults
  } finally {
    statsLoading.value = false
  }
}

async function loadMembersChart() {
  membersChartLoading.value = true
  try {
    const response = await reports.membersOverTime({ period: '30d' })
    const rows = response.data ?? response
    membersChartData.value = {
      labels: rows.map((r) => r.date),
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
    // chart not rendered
  } finally {
    membersChartLoading.value = false
  }
}

async function loadPlanDistribution() {
  planChartLoading.value = true
  try {
    const response = await reports.planDistribution()
    const rows = response.data ?? response
    if (rows.length > 0) {
      planChartData.value = {
        labels: rows.map((r) => r.plan_title),
        datasets: [
          {
            data: rows.map((r) => r.count),
            backgroundColor: [
              '#4D6EF5',
              '#F5A623',
              '#F56C9E',
              '#8B5CF6',
              '#36CFC9',
              '#67C23A',
              '#909399',
              '#FF85C0',
            ],
          },
        ],
      }
    }
  } catch {
    // chart not rendered
  } finally {
    planChartLoading.value = false
  }
}

async function loadExpiringSoon() {
  expiringLoading.value = true
  try {
    const response = await reports.expiringSoon({ days: 7, limit: 10 })
    expiringSoon.value = response.data ?? response
  } catch {
    expiringSoon.value = []
  } finally {
    expiringLoading.value = false
  }
}

async function loadRecentActivity() {
  activityLoading.value = true
  try {
    const response = await members.list({ per_page: 10 })
    recentGrants.value = response.data ?? response
  } catch {
    recentGrants.value = []
  } finally {
    activityLoading.value = false
  }
}

onMounted(() => {
  loadStats()
  loadMembersChart()
  loadPlanDistribution()
  loadExpiringSoon()
  loadRecentActivity()
})
</script>

<style scoped>
.charts-row {
  margin-bottom: 20px;
}

.chart-container {
  height: 300px;
  position: relative;
}

.quick-actions {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 20px;
}

.expiring-soon {
  margin-bottom: 20px;
}

.recent-activity {
  margin-bottom: 20px;
}
</style>
