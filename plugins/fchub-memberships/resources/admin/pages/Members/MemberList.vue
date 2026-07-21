<template>
  <div class="member-list-page">
    <MemberListToolbar
      :exporting="exporting"
      :filters="filters"
      :plan-options="planOptions"
      :has-filters="hasActiveFilters"
      @utility="handleUtility"
      @grant="grantDialogVisible = true"
      @update:search="filters.search = $event"
      @update:plan-id="filters.plan_id = $event"
      @update:status="filters.status = $event"
      @update:expires-within="filters.expires_within = $event"
      @search-input="debouncedFetch"
      @filter-change="resetAndFetch"
      @clear-filters="clearFilters"
    />

    <OperationsSummary label="Access health" :items="summaryItems" />

    <el-card shadow="never" class="list-card">
      <MemberBulkActionsBar
        :selected-count="selectedRows.length"
        @command="handleBulkAction"
        @clear="clearSelection"
      />

      <ListStatePanel
        v-if="errorMessage"
        kind="error"
        title="Member access could not be loaded"
        :description="errorMessage"
        action-label="Try again"
        @action="fetchMembers"
      />

      <el-table
        v-else
        ref="tableRef"
        :data="rows"
        v-loading="loading"
        row-class-name="clickable-row"
        @row-click="handleRowClick"
        @selection-change="onSelectionChange"
      >
        <el-table-column type="selection" width="45" />
        <el-table-column label="Member" min-width="240">
          <template #default="{ row }">
            <div class="member-cell">
              <div class="member-avatar">
                {{ getInitials(row.display_name) }}
              </div>
              <div class="member-info">
                <router-link
                  :to="`/members/${row.user_id}`"
                  class="member-name member-name--link"
                  :aria-label="`Open ${row.display_name} profile`"
                  @click.stop
                >
                  {{ row.display_name }}
                </router-link>
                <div class="member-email">{{ row.user_email }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="Plan" min-width="160">
          <template #default="{ row }">
            {{ row.plan_title }}
          </template>
        </el-table-column>
        <el-table-column label="Status" width="120">
          <template #default="{ row }">
            <el-tag :type="statusTagType(row.status)" size="small">
              {{ row.status }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="Granted" width="160">
          <template #default="{ row }">
            {{ formatDate(row.created_at) }}
          </template>
        </el-table-column>
        <el-table-column label="Expires" width="140">
          <template #default="{ row }">
            {{ row.expires_at ? formatDate(row.expires_at) : 'Lifetime' }}
          </template>
        </el-table-column>
        <el-table-column prop="source_type" label="Source" width="120" />
        <el-table-column label="Manage" width="110" align="right" fixed="right">
          <template #default="{ row }">
            <router-link
              :to="`/members/${row.user_id}`"
              class="manage-link"
              :aria-label="`Manage ${row.display_name} access`"
              @click.stop
            >
              Manage
              <el-icon><ArrowRight /></el-icon>
            </router-link>
          </template>
        </el-table-column>
      </el-table>

      <div v-if="!errorMessage" v-loading="loading" class="mobile-member-list" aria-label="Members">
        <article v-for="row in rows" :key="`${row.user_id}-${row.plan_id}`" class="mobile-record-card">
          <div class="mobile-record-card__topline">
            <label class="mobile-selection">
              <input
                type="checkbox"
                :checked="isRowSelected(row)"
                :aria-label="`Select ${row.display_name} on ${row.plan_title}`"
                @change="toggleMobileSelection(row, $event.target.checked)"
              />
            </label>
            <div class="member-cell mobile-member-cell">
              <div class="member-avatar">{{ getInitials(row.display_name) }}</div>
              <div class="member-info">
                <div class="member-name">{{ row.display_name }}</div>
                <div class="member-email">{{ row.user_email }}</div>
              </div>
            </div>
            <el-tag :type="statusTagType(row.status)" size="small">{{ row.status }}</el-tag>
          </div>
          <dl class="mobile-record-card__facts">
            <div><dt>Plan</dt><dd>{{ row.plan_title }}</dd></div>
            <div><dt>Granted</dt><dd>{{ formatDate(row.created_at) }}</dd></div>
            <div><dt>Expires</dt><dd>{{ row.expires_at ? formatDate(row.expires_at) : 'Lifetime' }}</dd></div>
          </dl>
          <div class="mobile-record-card__footer">
            <span>{{ row.source_type }}</span>
            <router-link :to="`/members/${row.user_id}`">
              Manage profile
              <el-icon><ArrowRight /></el-icon>
            </router-link>
          </div>
        </article>
      </div>

      <ListStatePanel
        v-if="!errorMessage && !loading && rows.length === 0 && hasActiveFilters"
        kind="filtered"
        title="No access matches these filters"
        description="Clear the filters or try a different name, email, or user ID."
        action-label="Clear filters"
        @action="clearFilters"
      />
      <ListStatePanel
        v-else-if="!errorMessage && !loading && rows.length === 0"
        kind="empty"
        title="No member access yet"
        description="Grant a plan to a WordPress user to create the first access assignment."
        action-label="Grant access"
        @action="grantDialogVisible = true"
      />

      <!-- Pagination (FC pattern) -->
      <div class="pagination-bar" v-if="!errorMessage && total > 0">
        <div class="pagination-info">
          <span>Page {{ page }} of {{ totalPages }}</span>
          <el-select v-model="perPage" size="small" class="per-page-select" @change="resetAndFetch">
            <el-option :value="10" label="10 / page" />
            <el-option :value="20" label="20 / page" />
            <el-option :value="50" label="50 / page" />
          </el-select>
          <span>Total {{ total }}</span>
        </div>
        <el-pagination
          v-model:current-page="page"
          :page-size="perPage"
          :total="total"
          layout="prev, pager, next"
          @current-change="fetchMembers"
        />
      </div>
    </el-card>

    <GrantAccessDialog
      :visible="grantDialogVisible"
      :form="grantForm"
      :loading="granting"
      :searching-users="searchingUsers"
      :user-results="userResults"
      :user-search-error="userSearchError"
      :selected-user="selectedGrantUser"
      :plan-options="planOptions"
      :date-picker-format="wpDatePickerFormat"
      :search-users="searchUsers"
      @close="grantDialogVisible = false; resetGrantForm()"
      @confirm="handleGrant"
      @update:user-id="updateGrantUser"
      @update:plan-id="grantForm.plan_id = $event"
      @update:expires-at="grantForm.expires_at = $event"
      @update:reason="grantForm.reason = $event"
    />

    <BulkGrantDialog
      :visible="bulkGrantDialogVisible"
      :selected-count="selectedRows.length"
      :plan-id="bulkForm.plan_id"
      :plan-options="planOptions"
      :loading="bulkLoading"
      @close="bulkGrantDialogVisible = false"
      @confirm="executeBulkGrant"
      @update:plan-id="bulkForm.plan_id = $event"
    />

    <BulkRevokeDialog
      :visible="bulkRevokeDialogVisible"
      :selected-count="selectedRows.length"
      :plan-id="bulkForm.plan_id"
      :reason="bulkForm.reason"
      :plan-options="planOptions"
      :loading="bulkLoading"
      @close="bulkRevokeDialogVisible = false"
      @confirm="executeBulkRevoke"
      @update:plan-id="bulkForm.plan_id = $event"
      @update:reason="bulkForm.reason = $event"
    />

    <BulkExtendDialog
      :visible="bulkExtendDialogVisible"
      :selected-count="selectedRows.length"
      :plan-id="bulkForm.plan_id"
      :expires-at="bulkForm.expires_at"
      :plan-options="planOptions"
      :loading="bulkLoading"
      :date-picker-format="wpDatePickerFormat"
      @close="bulkExtendDialogVisible = false"
      @confirm="executeBulkExtend"
      @update:plan-id="bulkForm.plan_id = $event"
      @update:expires-at="bulkForm.expires_at = $event"
    />
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { ArrowRight } from '@element-plus/icons-vue'
import { members as membersApi, plans } from '@/api/index.js'
import { formatWpDate, wpDatePickerFormat } from '@/utils/wpDate.js'
import { useGrantAccessUserPicker } from '@/composables/members/useGrantAccessUserPicker.js'
import MemberListToolbar from '@/components/members/MemberListToolbar.vue'
import MemberBulkActionsBar from '@/components/members/MemberBulkActionsBar.vue'
import GrantAccessDialog from '@/components/members/GrantAccessDialog.vue'
import BulkGrantDialog from '@/components/members/BulkGrantDialog.vue'
import BulkRevokeDialog from '@/components/members/BulkRevokeDialog.vue'
import BulkExtendDialog from '@/components/members/BulkExtendDialog.vue'
import OperationsSummary from '@/components/workspace/OperationsSummary.vue'
import ListStatePanel from '@/components/workspace/ListStatePanel.vue'

const router = useRouter()

// Table state
const loading = ref(false)
const rows = ref([])
const total = ref(0)
const page = ref(1)
const perPage = ref(20)
const errorMessage = ref('')
const summary = reactive({ active: 0, expiring_soon: 0, paused: 0, ended: 0 })
let requestSequence = 0

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))
const hasActiveFilters = computed(() => Boolean(
  filters.search || filters.plan_id || filters.status || filters.expires_within,
))
const summaryItems = computed(() => [
  { label: 'Active access', value: summary.active, support: 'Member-plan assignments usable now', tone: 'success' },
  { label: 'Expiring in 7 days', value: summary.expiring_soon, support: 'Assignments needing a renewal decision', tone: 'warning' },
  { label: 'Paused access', value: summary.paused, support: 'Recoverable assignments currently held' },
  { label: 'Ended access', value: summary.ended, support: 'Expired or revoked assignments' },
])

// Filters
const filters = reactive({
  search: '',
  plan_id: '',
  status: '',
  expires_within: '',
})

// Plan options
const planOptions = ref([])

// Export
const exporting = ref(false)

// Grant dialog
const grantDialogVisible = ref(false)
const granting = ref(false)
const grantForm = reactive({
  user_id: '',
  plan_id: '',
  expires_at: '',
  reason: '',
})

const selectedGrantUser = ref(null)
const {
  searchingUsers,
  userResults,
  userSearchError,
  searchUsers,
  resetUserSearch,
} = useGrantAccessUserPicker({
  fetchUsers: (params) => membersApi.list(params),
})

// Bulk actions
const selectedRows = ref([])
const tableRef = ref(null)
const bulkLoading = ref(false)
const bulkGrantDialogVisible = ref(false)
const bulkRevokeDialogVisible = ref(false)
const bulkExtendDialogVisible = ref(false)
const bulkForm = reactive({
  plan_id: '',
  expires_at: '',
  reason: '',
})

// Debounce timer
let searchTimer = null

function debouncedFetch() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    page.value = 1
    fetchMembers()
  }, 300)
}

function resetAndFetch() {
  page.value = 1
  fetchMembers()
}

function getInitials(name) {
  if (!name) return '?'
  return name.split(' ').map(p => p[0]).join('').toUpperCase().substring(0, 2)
}

async function fetchMembers() {
  const requestId = ++requestSequence
  loading.value = true
  errorMessage.value = ''
  try {
    const params = {
      page: page.value,
      per_page: perPage.value,
    }
    if (filters.search) params.search = filters.search
    if (filters.plan_id) params.plan_id = filters.plan_id
    if (filters.status) params.status = filters.status
    if (filters.expires_within) params.expires_within = filters.expires_within
    if (filters.expires_within) params.expires_within = filters.expires_within

    const response = await membersApi.list(params)
    if (requestId !== requestSequence) return
    rows.value = response.data || []
    total.value = response.total || 0
    Object.assign(summary, {
      active: Number(response.summary?.active) || 0,
      expiring_soon: Number(response.summary?.expiring_soon) || 0,
      paused: Number(response.summary?.paused) || 0,
      ended: Number(response.summary?.ended) || 0,
    })
    clearSelection()
  } catch (err) {
    if (requestId !== requestSequence) return
    rows.value = []
    total.value = 0
    errorMessage.value = err.message || 'Member access could not be loaded. Please try again.'
  } finally {
    if (requestId === requestSequence) loading.value = false
  }
}

function clearFilters() {
  filters.search = ''
  filters.plan_id = ''
  filters.status = ''
  filters.expires_within = ''
  resetAndFetch()
}

function handleUtility(command) {
  if (command === 'import') {
    router.push('/import')
  } else if (command === 'export') {
    handleExport()
  }
}

async function fetchPlanOptions() {
  try {
    const response = await plans.options()
    const opts = response.data || response || []
    planOptions.value = opts.map((o) => ({ id: o.id ?? o.value, title: o.label ?? o.title }))
  } catch {
    // Silently fail, filter will just be empty
  }
}

function updateGrantUser(userId) {
  grantForm.user_id = userId
  selectedGrantUser.value = userResults.value.find(({ id }) => String(id) === String(userId)) || null
}

async function handleGrant() {
  granting.value = true
  try {
    const payload = {
      user_id: grantForm.user_id,
      plan_id: grantForm.plan_id,
    }
    if (grantForm.expires_at) payload.expires_at = grantForm.expires_at
    if (grantForm.reason) payload.reason = grantForm.reason

    await membersApi.grant(payload)
    ElMessage.success('Access granted successfully')
    grantDialogVisible.value = false
    resetGrantForm()
    fetchMembers()
  } catch (err) {
    ElMessage.error(err.message || 'Failed to grant access')
  } finally {
    granting.value = false
  }
}

async function handleExport() {
  exporting.value = true
  try {
    const params = {}
    if (filters.search) params.search = filters.search
    if (filters.plan_id) params.plan_id = filters.plan_id
    if (filters.status) params.status = filters.status

    const response = await membersApi.export(params)
    const csvContent = response.csv || response.data || response
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `members-export-${new Date().toISOString().slice(0, 10)}.csv`
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    URL.revokeObjectURL(url)
    ElMessage.success('Export downloaded')
  } catch (err) {
    ElMessage.error(err.message || 'Failed to export members')
  } finally {
    exporting.value = false
  }
}

function onSelectionChange(rows) {
  selectedRows.value = rows
}

function rowKey(row) {
  return `${row.user_id}:${row.plan_id ?? 'direct'}`
}

function isRowSelected(row) {
  const key = rowKey(row)
  return selectedRows.value.some((selected) => rowKey(selected) === key)
}

function toggleMobileSelection(row, checked) {
  const key = rowKey(row)
  if (checked && !isRowSelected(row)) {
    selectedRows.value = [...selectedRows.value, row]
  } else if (!checked) {
    selectedRows.value = selectedRows.value.filter((selected) => rowKey(selected) !== key)
  }
}

function handleBulkAction(command) {
  resetBulkForm()
  if (command === 'grant') {
    bulkGrantDialogVisible.value = true
  } else if (command === 'revoke') {
    // Pre-select plan if all selected rows share the same plan
    const planIds = [...new Set(selectedRows.value.map(r => r.plan_id).filter(Boolean))]
    if (planIds.length === 1) bulkForm.plan_id = planIds[0]
    bulkRevokeDialogVisible.value = true
  } else if (command === 'extend') {
    const planIds = [...new Set(selectedRows.value.map(r => r.plan_id).filter(Boolean))]
    if (planIds.length === 1) bulkForm.plan_id = planIds[0]
    bulkExtendDialogVisible.value = true
  } else if (command === 'export') {
    executeBulkExport()
  }
}

async function executeBulkGrant() {
  bulkLoading.value = true
  try {
    const userIds = [...new Set(selectedRows.value.map(r => r.user_id))]
    await membersApi.bulkGrant({ user_ids: userIds, plan_id: bulkForm.plan_id })
    ElMessage.success(`Plan granted to ${userIds.length} members`)
    bulkGrantDialogVisible.value = false
    clearSelection()
    fetchMembers()
  } catch (err) {
    ElMessage.error(err.message || 'Bulk grant failed')
  } finally {
    bulkLoading.value = false
  }
}

async function executeBulkRevoke() {
  bulkLoading.value = true
  try {
    const userIds = [...new Set(selectedRows.value.map(r => r.user_id))]
    await membersApi.bulkRevoke({
      user_ids: userIds,
      plan_id: bulkForm.plan_id,
      reason: bulkForm.reason || 'Bulk revoke',
    })
    ElMessage.success(`Plan revoked for ${userIds.length} members`)
    bulkRevokeDialogVisible.value = false
    clearSelection()
    fetchMembers()
  } catch (err) {
    ElMessage.error(err.message || 'Bulk revoke failed')
  } finally {
    bulkLoading.value = false
  }
}

async function executeBulkExtend() {
  bulkLoading.value = true
  try {
    const userIds = [...new Set(selectedRows.value.map(r => r.user_id))]
    await membersApi.bulkExtend({
      user_ids: userIds,
      plan_id: bulkForm.plan_id,
      expires_at: bulkForm.expires_at,
    })
    ElMessage.success(`Expiry extended for ${userIds.length} members`)
    bulkExtendDialogVisible.value = false
    clearSelection()
    fetchMembers()
  } catch (err) {
    ElMessage.error(err.message || 'Bulk extend failed')
  } finally {
    bulkLoading.value = false
  }
}

async function executeBulkExport() {
  bulkLoading.value = true
  try {
    const userIds = [...new Set(selectedRows.value.map(r => r.user_id))]
    const response = await membersApi.bulkExport({ user_ids: userIds })
    const csvContent = response.csv || ''
    if (!csvContent) {
      ElMessage.warning('No data to export')
      return
    }
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `members-selected-${new Date().toISOString().slice(0, 10)}.csv`
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    URL.revokeObjectURL(url)
    ElMessage.success('Export downloaded')
  } catch (err) {
    ElMessage.error(err.message || 'Bulk export failed')
  } finally {
    bulkLoading.value = false
  }
}

function clearSelection() {
  selectedRows.value = []
  if (tableRef.value) {
    tableRef.value.clearSelection()
  }
}

function resetBulkForm() {
  bulkForm.plan_id = ''
  bulkForm.expires_at = ''
  bulkForm.reason = ''
}

function handleRowClick(row) {
  router.push(`/members/${row.user_id}`)
}

function resetGrantForm() {
  grantForm.user_id = ''
  grantForm.plan_id = ''
  grantForm.expires_at = ''
  grantForm.reason = ''
  selectedGrantUser.value = null
  resetUserSearch()
}

function statusTagType(status) {
  const map = {
    active: 'success',
    paused: 'warning',
    expired: 'warning',
    revoked: 'danger',
  }
  return map[status] || 'info'
}

function formatDate(dateStr) {
  return formatWpDate(dateStr)
}

onMounted(() => {
  fetchMembers()
  fetchPlanOptions()
})
</script>

<style scoped>
.list-card {
  margin-bottom: 20px;
}

/* Member cell with avatar — FC pattern */
:deep(.clickable-row) {
  cursor: pointer;
}

.member-cell {
  display: flex;
  align-items: center;
  gap: 12px;
}

.member-avatar {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: var(--fchub-stat-blue);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  font-weight: 600;
  flex-shrink: 0;
}

.member-info {
  line-height: 1.4;
}

.member-name {
  font-weight: 500;
  color: var(--fchub-text-primary);
}

.member-name--link,
.manage-link {
  color: var(--el-color-primary);
  text-decoration: none;
}

.member-name--link:hover,
.manage-link:hover { text-decoration: underline; }

.manage-link {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  font-weight: 600;
}

.member-email {
  font-size: 12px;
  color: var(--fchub-text-secondary);
}

/* Pagination — FC pattern */
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

.mobile-member-list { display: none; }

.mobile-selection {
  display: grid;
  place-items: center;
  flex: 0 0 26px;
  min-height: 36px;
}

.mobile-selection input {
  width: 17px;
  height: 17px;
  margin: 0;
  border: 1px solid var(--fchub-border-color) !important;
  border-radius: 4px;
  background: var(--fchub-card-bg);
  appearance: auto !important;
  -webkit-appearance: checkbox !important;
  accent-color: var(--el-color-primary);
}

@media (max-width: 782px) {
  .list-card :deep(.el-table) { display: none; }
  .mobile-member-list { display: grid; gap: 12px; }
  .mobile-record-card__topline { align-items: flex-start; }
  .mobile-member-cell { flex: 1; min-width: 0; }
  .member-info { min-width: 0; }
  .member-name, .member-email { overflow-wrap: anywhere; }
  .pagination-bar { align-items: flex-start; flex-direction: column; gap: 12px; }
}
</style>
