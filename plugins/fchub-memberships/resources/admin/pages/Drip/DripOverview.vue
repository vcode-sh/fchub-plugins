<template>
  <div class="drip-overview-page">
    <div class="page-header">
      <h2 class="fchub-page-title">Drip Content</h2>
      <el-button @click="$router.push('/drip/calendar')">
        <el-icon><Calendar /></el-icon>
        Calendar View
      </el-button>
    </div>

    <!-- Stats Row -->
    <div class="fchub-stat-grid" v-loading="statsLoading">
      <div class="fchub-stat-widget">
        <div class="fchub-stat-icon blue">
          <el-icon :size="20"><List /></el-icon>
        </div>
        <div class="fchub-stat-title">Total Drip Rules</div>
        <div class="fchub-stat-value">{{ stats.total_rules }}</div>
      </div>
      <div class="fchub-stat-widget">
        <div class="fchub-stat-icon orange">
          <el-icon :size="20"><Clock /></el-icon>
        </div>
        <div class="fchub-stat-title">Pending Notifications</div>
        <div class="fchub-stat-value">{{ stats.pending }}</div>
      </div>
      <div class="fchub-stat-widget">
        <div class="fchub-stat-icon pink">
          <el-icon :size="20"><Check /></el-icon>
        </div>
        <div class="fchub-stat-title">Sent Today</div>
        <div class="fchub-stat-value">{{ stats.sent_today }}</div>
      </div>
      <div class="fchub-stat-widget">
        <div class="fchub-stat-icon purple">
          <el-icon :size="20"><WarningFilled /></el-icon>
        </div>
        <div class="fchub-stat-title">Failed</div>
        <div class="fchub-stat-value">
          {{ stats.failed }}
          <span v-if="stats.failed > 0" class="failed-indicator" />
        </div>
      </div>
    </div>

    <!-- Notifications Queue -->
    <el-card shadow="never" class="queue-card">
      <template #header>
        <span>Notifications Queue</span>
      </template>

      <el-table
        v-loading="queueLoading"
        :data="queue"
      >
        <el-table-column prop="user_email" label="User" min-width="200" />

        <el-table-column prop="content_title" label="Content" min-width="200" />

        <el-table-column label="Scheduled" width="180">
          <template #default="{ row }">
            {{ formatDateTime(row.scheduled_at) }}
          </template>
        </el-table-column>

        <el-table-column label="Status" width="120">
          <template #default="{ row }">
            <el-tag :type="statusTagType(row.status)" size="small">
              {{ row.status }}
            </el-tag>
          </template>
        </el-table-column>

        <el-table-column label="Actions" width="100" align="center" fixed="right">
          <template #default="{ row }">
            <el-button
              v-if="row.status === 'failed'"
              type="primary"
              text
              size="small"
              :loading="retryingId === row.id"
              @click="handleRetry(row)"
            >
              <el-icon><RefreshRight /></el-icon>
              Retry
            </el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination-bar" v-if="queueTotal > 0">
        <div class="pagination-info">
          <span>Page {{ queueFilters.page }} of {{ queueTotalPages }}</span>
          <el-select v-model="queueFilters.per_page" size="small" class="per-page-select" @change="resetAndFetchQueue">
            <el-option :value="10" label="10 / page" />
            <el-option :value="20" label="20 / page" />
            <el-option :value="50" label="50 / page" />
          </el-select>
          <span>Total {{ queueTotal }}</span>
        </div>
        <el-pagination
          v-model:current-page="queueFilters.page"
          :page-size="queueFilters.per_page"
          :total="queueTotal"
          layout="prev, pager, next"
          @current-change="fetchQueue"
        />
      </div>
    </el-card>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { Calendar, Check, Clock, List, RefreshRight, WarningFilled } from '@element-plus/icons-vue'
import { ElMessage } from 'element-plus'
import { drip } from '@/api/index.js'
import { formatWpDateTime } from '@/utils/wpDate.js'

const statsLoading = ref(false)
const stats = ref({
  total_rules: 0,
  pending: 0,
  sent_today: 0,
  failed: 0,
})

const queueLoading = ref(false)
const queue = ref([])
const queueTotal = ref(0)
const retryingId = ref(null)

const queueFilters = reactive({
  page: 1,
  per_page: 20,
})

const queueTotalPages = computed(() => Math.max(1, Math.ceil(queueTotal.value / queueFilters.per_page)))

function resetAndFetchQueue() {
  queueFilters.page = 1
  fetchQueue()
}

function statusTagType(status) {
  const map = {
    pending: 'warning',
    sent: 'success',
    failed: 'danger',
    scheduled: 'info',
  }
  return map[status] || 'info'
}

function formatDateTime(dateStr) {
  return formatWpDateTime(dateStr)
}

async function fetchStats() {
  statsLoading.value = true
  try {
    const response = await drip.overview()
    const data = response.data ?? response
    stats.value = {
      total_rules: data.total_rules ?? 0,
      pending: data.pending ?? 0,
      sent_today: data.sent_today ?? 0,
      failed: data.failed ?? 0,
    }
  } catch {
    // stats remain at defaults
  } finally {
    statsLoading.value = false
  }
}

async function fetchQueue() {
  queueLoading.value = true
  try {
    const res = await drip.queue({
      page: queueFilters.page,
      per_page: queueFilters.per_page,
    })
    queue.value = res.data ?? []
    queueTotal.value = res.total ?? 0
  } catch (err) {
    ElMessage.error(err.message || 'Failed to load notification queue')
  } finally {
    queueLoading.value = false
  }
}

async function handleRetry(row) {
  retryingId.value = row.id
  try {
    await drip.retry(row.id)
    ElMessage.success('Notification queued for retry')
    await fetchQueue()
    await fetchStats()
  } catch (err) {
    ElMessage.error(err.message || 'Failed to retry notification')
  } finally {
    retryingId.value = null
  }
}

onMounted(() => {
  fetchStats()
  fetchQueue()
})
</script>

<style scoped>
.page-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 20px;
}

.failed-indicator {
  display: inline-block;
  width: 8px;
  height: 8px;
  background: var(--el-color-danger);
  border-radius: 50%;
  margin-left: 4px;
  vertical-align: middle;
}

.queue-card {
  margin-bottom: 20px;
}

.pagination-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 16px;
  padding-top: 16px;
  border-top: 1px solid var(--fchub-border-color);
}

.pagination-info {
  display: flex;
  align-items: center;
  gap: 12px;
  font-size: 13px;
  color: var(--fchub-text-secondary);
}

.per-page-select {
  width: 120px;
}
</style>
