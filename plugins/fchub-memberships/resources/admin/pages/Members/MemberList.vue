<template>
  <div class="member-list-page">
    <MemberListToolbar
      :exporting="exporting"
      :filters="filters"
      :plan-options="planOptions"
      @export="handleExport"
      @import="$router.push('/import')"
      @grant="grantDialogVisible = true"
      @update:search="filters.search = $event"
      @update:plan-id="filters.plan_id = $event"
      @update:status="filters.status = $event"
      @search-input="debouncedFetch"
      @filter-change="resetAndFetch"
    />

    <!-- Single card wrapping search + table (FC pattern) -->
    <el-card shadow="never" class="list-card">
      <MemberBulkActionsBar
        :selected-count="selectedRows.length"
        @command="handleBulkAction"
        @clear="clearSelection"
      />

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

    <GrantAccessDialog
      :visible="grantDialogVisible"
      :form="grantForm"
      :loading="granting"
      :searching-users="searchingUsers"
      :user-results="userResults"
      :plan-options="planOptions"
      :date-picker-format="wpDatePickerFormat"
      :search-users="searchUsers"
      @close="grantDialogVisible = false; resetGrantForm()"
      @confirm="handleGrant"
      @update:user-id="grantForm.user_id = $event"
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
import { members as membersApi, plans } from '@/api/index.js'
import { formatWpDate, wpDatePickerFormat } from '@/utils/wpDate.js'
import MemberListToolbar from '@/components/members/MemberListToolbar.vue'
import MemberBulkActionsBar from '@/components/members/MemberBulkActionsBar.vue'
import GrantAccessDialog from '@/components/members/GrantAccessDialog.vue'
import BulkGrantDialog from '@/components/members/BulkGrantDialog.vue'
import BulkRevokeDialog from '@/components/members/BulkRevokeDialog.vue'
import BulkExtendDialog from '@/components/members/BulkExtendDialog.vue'

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
</style>
