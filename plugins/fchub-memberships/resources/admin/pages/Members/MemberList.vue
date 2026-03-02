<template>
  <div class="member-list-page">
    <!-- Header -->
    <div class="page-header">
      <h2 class="fchub-page-title">Members</h2>
      <div class="header-actions">
        <el-button @click="handleExport" :loading="exporting">
          <el-icon><Download /></el-icon>
          Export CSV
        </el-button>
        <el-button @click="$router.push('/import')">
          <el-icon><Upload /></el-icon>
          Import Members
        </el-button>
        <el-button type="primary" @click="grantDialogVisible = true">
          <el-icon><Plus /></el-icon>
          Grant Access
        </el-button>
      </div>
    </div>

    <!-- Single card wrapping search + table (FC pattern) -->
    <el-card shadow="never" class="list-card">
      <!-- Search & Filters -->
      <div class="search-bar">
        <el-input
          v-model="filters.search"
          placeholder="Search"
          clearable
          :prefix-icon="Search"
          class="search-input"
          @input="debouncedFetch"
        />
        <div class="filter-controls">
          <el-select
            v-model="filters.plan_id"
            placeholder="All Plans"
            clearable
            @change="resetAndFetch"
          >
            <el-option
              v-for="plan in planOptions"
              :key="plan.id"
              :label="plan.title"
              :value="plan.id"
            />
          </el-select>
          <el-select
            v-model="filters.status"
            placeholder="All Statuses"
            clearable
            @change="resetAndFetch"
          >
            <el-option label="Active" value="active" />
            <el-option label="Paused" value="paused" />
            <el-option label="Expired" value="expired" />
            <el-option label="Revoked" value="revoked" />
          </el-select>
        </div>
      </div>
      <div class="search-hint">Search by name, email or user ID</div>

      <div v-if="selectedRows.length > 0" class="bulk-actions">
        <span class="bulk-count">{{ selectedRows.length }} selected</span>
        <el-dropdown @command="handleBulkAction">
          <el-button size="small">
            Bulk Actions <el-icon><ArrowDown /></el-icon>
          </el-button>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item command="grant">Grant Plan</el-dropdown-item>
              <el-dropdown-item command="revoke">Revoke Plan</el-dropdown-item>
              <el-dropdown-item command="extend">Extend Expiry</el-dropdown-item>
              <el-dropdown-item command="export" divided>Export Selected</el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
        <el-button size="small" text @click="clearSelection">Clear Selection</el-button>
      </div>

      <!-- Table -->
      <el-table
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
                <div class="member-name">{{ row.display_name }}</div>
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
      </el-table>

      <el-empty v-if="!loading && rows.length === 0" description="No members found" />

      <!-- Pagination (FC pattern) -->
      <div class="pagination-bar" v-if="total > 0">
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

    <!-- Grant Access Dialog -->
    <el-dialog
      v-model="grantDialogVisible"
      title="Grant Access"
      width="500px"
      @close="resetGrantForm"
    >
      <el-form :model="grantForm" label-position="top">
        <el-form-item label="User" required>
          <el-select
            v-model="grantForm.user_id"
            filterable
            remote
            :remote-method="searchUsers"
            :loading="searchingUsers"
            placeholder="Search WordPress users..."
            class="full-width"
          >
            <el-option
              v-for="user in userResults"
              :key="user.id"
              :label="`${user.display_name} (${user.email})`"
              :value="user.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="Plan" required>
          <el-select
            v-model="grantForm.plan_id"
            placeholder="Select plan..."
            class="full-width"
          >
            <el-option
              v-for="plan in planOptions"
              :key="plan.id"
              :label="plan.title"
              :value="plan.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="Expiry Date">
          <el-date-picker
            v-model="grantForm.expires_at"
            type="date"
            placeholder="Leave empty for plan default"
            :format="wpDatePickerFormat"
            value-format="YYYY-MM-DD"
            class="full-width"
          />
        </el-form-item>
        <el-form-item label="Reason">
          <el-input
            v-model="grantForm.reason"
            type="textarea"
            :rows="2"
            placeholder="Optional reason for granting access"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="grantDialogVisible = false">Cancel</el-button>
        <el-button
          type="primary"
          @click="handleGrant"
          :loading="granting"
          :disabled="!grantForm.user_id || !grantForm.plan_id"
        >
          Grant Access
        </el-button>
      </template>
    </el-dialog>

    <!-- Bulk Grant Dialog -->
    <el-dialog
      v-model="bulkGrantDialogVisible"
      title="Bulk Grant Plan"
      width="450px"
    >
      <p class="bulk-dialog-info">Grant a plan to {{ selectedRows.length }} selected members.</p>
      <el-form label-position="top">
        <el-form-item label="Plan" required>
          <el-select
            v-model="bulkForm.plan_id"
            placeholder="Select plan..."
            class="full-width"
          >
            <el-option
              v-for="plan in planOptions"
              :key="plan.id"
              :label="plan.title"
              :value="plan.id"
            />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="bulkGrantDialogVisible = false">Cancel</el-button>
        <el-button
          type="primary"
          @click="executeBulkGrant"
          :loading="bulkLoading"
          :disabled="!bulkForm.plan_id"
        >
          Grant to {{ selectedRows.length }} Members
        </el-button>
      </template>
    </el-dialog>

    <!-- Bulk Revoke Dialog -->
    <el-dialog
      v-model="bulkRevokeDialogVisible"
      title="Bulk Revoke Plan"
      width="450px"
    >
      <p class="bulk-dialog-info">Revoke a plan from {{ selectedRows.length }} selected members.</p>
      <el-form label-position="top">
        <el-form-item label="Plan" required>
          <el-select
            v-model="bulkForm.plan_id"
            placeholder="Select plan..."
            class="full-width"
          >
            <el-option
              v-for="plan in planOptions"
              :key="plan.id"
              :label="plan.title"
              :value="plan.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="Reason">
          <el-input
            v-model="bulkForm.reason"
            type="textarea"
            :rows="2"
            placeholder="Optional reason for revoking"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="bulkRevokeDialogVisible = false">Cancel</el-button>
        <el-button
          type="danger"
          @click="executeBulkRevoke"
          :loading="bulkLoading"
          :disabled="!bulkForm.plan_id"
        >
          Revoke from {{ selectedRows.length }} Members
        </el-button>
      </template>
    </el-dialog>

    <!-- Bulk Extend Dialog -->
    <el-dialog
      v-model="bulkExtendDialogVisible"
      title="Bulk Extend Expiry"
      width="450px"
    >
      <p class="bulk-dialog-info">Extend expiry for {{ selectedRows.length }} selected members.</p>
      <el-form label-position="top">
        <el-form-item label="Plan" required>
          <el-select
            v-model="bulkForm.plan_id"
            placeholder="Select plan..."
            class="full-width"
          >
            <el-option
              v-for="plan in planOptions"
              :key="plan.id"
              :label="plan.title"
              :value="plan.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="New Expiry Date" required>
          <el-date-picker
            v-model="bulkForm.expires_at"
            type="date"
            placeholder="Select new expiry date"
            :format="wpDatePickerFormat"
            value-format="YYYY-MM-DD"
            class="full-width"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="bulkExtendDialogVisible = false">Cancel</el-button>
        <el-button
          type="primary"
          @click="executeBulkExtend"
          :loading="bulkLoading"
          :disabled="!bulkForm.plan_id || !bulkForm.expires_at"
        >
          Extend {{ selectedRows.length }} Members
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { Search, ArrowDown, Download, Upload, Plus } from '@element-plus/icons-vue'
import { members as membersApi, plans } from '@/api/index.js'
import { formatWpDate, wpDatePickerFormat } from '@/utils/wpDate.js'

const router = useRouter()

// Table state
const loading = ref(false)
const rows = ref([])
const total = ref(0)
const page = ref(1)
const perPage = ref(20)

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))

// Filters
const filters = reactive({
  search: '',
  plan_id: '',
  status: '',
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

// User search
const searchingUsers = ref(false)
const userResults = ref([])

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
  loading.value = true
  try {
    const params = {
      page: page.value,
      per_page: perPage.value,
    }
    if (filters.search) params.search = filters.search
    if (filters.plan_id) params.plan_id = filters.plan_id
    if (filters.status) params.status = filters.status

    const response = await membersApi.list(params)
    rows.value = response.data || []
    total.value = response.total || 0
  } catch (err) {
    ElMessage.error(err.message || 'Failed to load members')
  } finally {
    loading.value = false
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

async function searchUsers(query) {
  if (!query || query.length < 2) {
    userResults.value = []
    return
  }
  searchingUsers.value = true
  try {
    const response = await membersApi.list({ search: query, per_page: 10, users_only: true })
    userResults.value = response.data || response || []
  } catch {
    userResults.value = []
  } finally {
    searchingUsers.value = false
  }
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
  userResults.value = []
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
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
}

.header-actions {
  display: flex;
  gap: 10px;
}

.list-card {
  margin-bottom: 20px;
}

/* Search bar — FC pattern: full-width search with filters on right */
.search-bar {
  display: flex;
  align-items: center;
  gap: 16px;
}

.search-input {
  flex: 1;
}

.filter-controls {
  display: flex;
  gap: 8px;
}

.filter-controls .el-select {
  width: 150px;
}

.search-hint {
  font-size: 12px;
  color: var(--fchub-text-secondary);
  margin-top: 6px;
  margin-bottom: 16px;
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

.full-width {
  width: 100%;
}

.bulk-actions {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 0;
  margin-bottom: 8px;
}

.bulk-count {
  font-size: 13px;
  color: var(--el-color-primary);
  font-weight: 500;
}

.bulk-dialog-info {
  font-size: 14px;
  color: var(--fchub-text-secondary);
  margin-bottom: 16px;
}
</style>
