<template>
  <div class="plan-list-page">
    <div class="page-header">
      <h2 class="fchub-page-title">Plans</h2>
      <div class="header-actions">
        <el-button @click="handleBulkExport" :loading="bulkExporting">
          <el-icon><Download /></el-icon>
          Export All
        </el-button>
        <el-button @click="handleImportDialog">
          <el-icon><Upload /></el-icon>
          Import
        </el-button>
        <el-button type="primary" @click="$router.push('/plans/new')">
          <el-icon><Plus /></el-icon>
          Create Plan
        </el-button>
      </div>
    </div>

    <el-card shadow="never" class="list-card">
      <!-- Search & Filters -->
      <div class="search-bar">
        <el-input
          v-model="filters.search"
          placeholder="Search plans..."
          clearable
          :prefix-icon="Search"
          class="search-input"
          @input="debouncedFetch"
        />
        <div class="filter-controls">
          <el-select
            v-model="filters.status"
            placeholder="All Statuses"
            clearable
            @change="resetAndFetch"
          >
            <el-option label="Active" value="active" />
            <el-option label="Inactive" value="inactive" />
            <el-option label="Archived" value="archived" />
          </el-select>
        </div>
      </div>
      <div class="search-hint">Search by plan title or slug</div>

      <!-- Table -->
      <el-table
        v-loading="loading"
        :data="plans_data"
        row-class-name="clickable-row"
        @row-click="handleRowClick"
      >
        <el-table-column label="Title" min-width="200">
          <template #default="{ row }">
            <router-link
              :to="`/plans/${row.id}/edit`"
              class="plan-title-link"
              @click.stop
            >
              {{ row.title }}
            </router-link>
            <!-- T17: Scheduled badge -->
            <el-tooltip
              v-if="row.scheduled_status && row.scheduled_at"
              :content="`Scheduled: ${row.scheduled_status} on ${formatDate(row.scheduled_at)}`"
              placement="top"
            >
              <el-tag type="warning" size="small" class="schedule-badge">
                Scheduled
              </el-tag>
            </el-tooltip>
          </template>
        </el-table-column>

        <el-table-column prop="slug" label="Slug" min-width="150" />

        <el-table-column label="Status" width="120">
          <template #default="{ row }">
            <el-tag
              :type="statusTagType(row.status)"
              size="small"
            >
              {{ row.status }}
            </el-tag>
          </template>
        </el-table-column>

        <el-table-column label="Duration" width="160">
          <template #default="{ row }">
            <el-tag v-if="row.duration_type === 'lifetime'" size="small">Lifetime</el-tag>
            <el-tag v-else-if="row.duration_type === 'fixed_days'" type="warning" size="small">
              {{ row.duration_days }} days
            </el-tag>
            <el-tag v-else-if="row.duration_type === 'subscription_mirror'" type="info" size="small">
              Subscription
            </el-tag>
            <el-tag v-else size="small">Lifetime</el-tag>
          </template>
        </el-table-column>

        <el-table-column label="Members" width="100" align="center">
          <template #default="{ row }">
            {{ row.members_count ?? 0 }}
          </template>
        </el-table-column>

        <el-table-column label="Rules" width="100" align="center">
          <template #default="{ row }">
            {{ row.rules_count ?? 0 }}
          </template>
        </el-table-column>

        <el-table-column label="Created" width="160">
          <template #default="{ row }">
            {{ formatDate(row.created_at) }}
          </template>
        </el-table-column>

        <el-table-column label="Actions" width="80" align="center" fixed="right">
          <template #default="{ row }">
            <el-dropdown trigger="click" @command="(cmd) => handleAction(cmd, row)" @click.stop>
              <el-button text size="small" @click.stop>
                <el-icon><MoreFilled /></el-icon>
              </el-button>
              <template #dropdown>
                <el-dropdown-menu>
                  <el-dropdown-item command="edit">
                    <el-icon><Edit /></el-icon>
                    Edit
                  </el-dropdown-item>
                  <el-dropdown-item command="duplicate">
                    <el-icon><CopyDocument /></el-icon>
                    Duplicate
                  </el-dropdown-item>
                  <el-dropdown-item command="export">
                    <el-icon><Download /></el-icon>
                    Export
                  </el-dropdown-item>
                  <el-dropdown-item
                    v-if="row.status !== 'archived'"
                    command="archive"
                  >
                    <el-icon><FolderOpened /></el-icon>
                    Archive
                  </el-dropdown-item>
                  <el-dropdown-item
                    v-if="row.status === 'archived'"
                    command="activate"
                  >
                    <el-icon><CircleCheck /></el-icon>
                    Activate
                  </el-dropdown-item>
                  <el-dropdown-item command="delete" divided>
                    <el-icon><Delete /></el-icon>
                    <span style="color: var(--el-color-danger)">Delete</span>
                  </el-dropdown-item>
                </el-dropdown-menu>
              </template>
            </el-dropdown>
          </template>
        </el-table-column>
      </el-table>

      <el-empty v-if="!loading && plans_data.length === 0" description="No plans found" />

      <!-- Pagination -->
      <div class="pagination-bar" v-if="total > 0">
        <div class="pagination-info">
          <span>Page {{ filters.page }} of {{ totalPages }}</span>
          <el-select v-model="filters.per_page" size="small" class="per-page-select" @change="resetAndFetch">
            <el-option :value="10" label="10 / page" />
            <el-option :value="20" label="20 / page" />
            <el-option :value="50" label="50 / page" />
          </el-select>
          <span>Total {{ total }}</span>
        </div>
        <el-pagination
          v-model:current-page="filters.page"
          :page-size="filters.per_page"
          :total="total"
          layout="prev, pager, next"
          @current-change="fetchPlans"
        />
      </div>
    </el-card>

    <el-dialog
      v-model="deleteDialogVisible"
      title="Delete Plan"
      width="420px"
      :close-on-click-modal="false"
    >
      <p>Are you sure you want to delete <strong>{{ planToDelete?.title }}</strong>? This action cannot be undone.</p>
      <template #footer>
        <el-button @click="deleteDialogVisible = false">Cancel</el-button>
        <el-button
          type="danger"
          :loading="deleteLoading"
          @click="confirmDelete"
        >
          Delete
        </el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="importDialogVisible" title="Import Plan" width="520px">
      <el-tabs v-model="importMode">
        <el-tab-pane label="Paste JSON" name="paste">
          <el-form label-position="top">
            <el-form-item label="Plan JSON">
              <el-input
                v-model="importJson"
                type="textarea"
                :rows="10"
                placeholder="Paste plan JSON data here..."
              />
            </el-form-item>
          </el-form>
        </el-tab-pane>
        <el-tab-pane label="Upload File" name="file">
          <div class="import-file-area">
            <input
              ref="fileInputRef"
              type="file"
              accept=".json"
              style="display: none"
              @change="onFileSelected"
            />
            <el-button @click="fileInputRef?.click()">
              <el-icon><Upload /></el-icon>
              Select JSON File
            </el-button>
            <span v-if="importFileName" class="import-file-name">{{ importFileName }}</span>
          </div>
        </el-tab-pane>
      </el-tabs>
      <template #footer>
        <el-button @click="importDialogVisible = false">Cancel</el-button>
        <el-button type="primary" @click="handleImport" :loading="importing">Import</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { Search, Upload, Download } from '@element-plus/icons-vue'
import { plans } from '@/api/index.js'
import { formatWpDate } from '@/utils/wpDate.js'

const router = useRouter()

const loading = ref(false)
const plans_data = ref([])
const total = ref(0)

const filters = reactive({
  page: 1,
  per_page: 20,
  search: '',
  status: '',
})

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / filters.per_page)))

const deleteDialogVisible = ref(false)
const deleteLoading = ref(false)
const planToDelete = ref(null)

const importDialogVisible = ref(false)
const importJson = ref('')
const importing = ref(false)
const importMode = ref('paste')
const importFileName = ref('')
const fileInputRef = ref(null)
const bulkExporting = ref(false)

let searchTimer = null

function debouncedFetch() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    filters.page = 1
    fetchPlans()
  }, 300)
}

function resetAndFetch() {
  filters.page = 1
  fetchPlans()
}

function statusTagType(status) {
  const map = {
    active: 'success',
    inactive: 'info',
    archived: 'warning',
  }
  return map[status] || 'info'
}

function formatDate(dateStr) {
  return formatWpDate(dateStr)
}

async function fetchPlans() {
  loading.value = true
  try {
    const params = {
      page: filters.page,
      per_page: filters.per_page,
    }
    if (filters.search) params.search = filters.search
    if (filters.status) params.status = filters.status

    const res = await plans.list(params)
    plans_data.value = res.data ?? []
    total.value = res.total ?? 0
  } catch (err) {
    ElMessage.error(err.message || 'Failed to load plans')
  } finally {
    loading.value = false
  }
}

function handleRowClick(row) {
  router.push(`/plans/${row.id}/edit`)
}

async function handleAction(command, row) {
  switch (command) {
    case 'edit':
      router.push(`/plans/${row.id}/edit`)
      break
    case 'duplicate':
      await duplicatePlan(row)
      break
    case 'export':
      await exportPlan(row)
      break
    case 'archive':
      await updatePlanStatus(row, 'archived')
      break
    case 'activate':
      await updatePlanStatus(row, 'active')
      break
    case 'delete':
      planToDelete.value = row
      deleteDialogVisible.value = true
      break
  }
}

async function duplicatePlan(row) {
  try {
    await plans.duplicate(row.id)
    ElMessage.success('Plan duplicated successfully')
    await fetchPlans()
  } catch (err) {
    ElMessage.error(err.message || 'Failed to duplicate plan')
  }
}

// T16: Export single plan
async function exportPlan(row) {
  try {
    const res = await plans.export(row.id)
    const data = res.data ?? res
    downloadJson(data, `plan-${row.slug || row.id}.json`)
    ElMessage.success('Plan exported')
  } catch (err) {
    ElMessage.error(err.message || 'Failed to export plan')
  }
}

// T16: Bulk export
async function handleBulkExport() {
  bulkExporting.value = true
  try {
    const res = await plans.exportAll()
    const data = res.data ?? res
    downloadJson(data, 'plans-export.json')
    ElMessage.success(`${Array.isArray(data) ? data.length : 0} plan(s) exported`)
  } catch (err) {
    ElMessage.error(err.message || 'Failed to export plans')
  } finally {
    bulkExporting.value = false
  }
}

function downloadJson(data, filename) {
  const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

async function updatePlanStatus(row, status) {
  try {
    await plans.update(row.id, { status })
    ElMessage.success(`Plan ${status === 'archived' ? 'archived' : 'activated'} successfully`)
    await fetchPlans()
  } catch (err) {
    ElMessage.error(err.message || 'Failed to update plan status')
  }
}

async function confirmDelete() {
  if (!planToDelete.value) return
  deleteLoading.value = true
  try {
    await plans.remove(planToDelete.value.id)
    ElMessage.success('Plan deleted successfully')
    deleteDialogVisible.value = false
    planToDelete.value = null
    await fetchPlans()
  } catch (err) {
    ElMessage.error(err.message || 'Failed to delete plan')
  } finally {
    deleteLoading.value = false
  }
}

function handleImportDialog() {
  importJson.value = ''
  importFileName.value = ''
  importMode.value = 'paste'
  importDialogVisible.value = true
}

// T16: File upload handler
function onFileSelected(event) {
  const file = event.target.files?.[0]
  if (!file) return

  importFileName.value = file.name

  const reader = new FileReader()
  reader.onload = (e) => {
    importJson.value = e.target.result
  }
  reader.readAsText(file)

  // Reset the input so the same file can be selected again
  event.target.value = ''
}

async function handleImport() {
  if (!importJson.value.trim()) {
    ElMessage.warning('Please provide plan JSON data')
    return
  }
  importing.value = true
  try {
    const raw = JSON.parse(importJson.value)

    // Support both single plan and array of plans
    const items = Array.isArray(raw) ? raw : [raw]
    let imported = 0
    let errors = 0

    for (const data of items) {
      try {
        await plans.import(data)
        imported++
      } catch {
        errors++
      }
    }

    if (errors > 0) {
      ElMessage.warning(`Imported ${imported} plan(s), ${errors} failed`)
    } else {
      ElMessage.success(`${imported} plan(s) imported successfully`)
    }

    importDialogVisible.value = false
    fetchPlans()
  } catch (err) {
    ElMessage.error(err.message || 'Invalid JSON data')
  } finally {
    importing.value = false
  }
}

onMounted(() => {
  fetchPlans()
})
</script>

<style scoped>
.page-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 20px;
}

.header-actions {
  display: flex;
  gap: 10px;
}

.list-card {
  margin-bottom: 20px;
}

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

:deep(.clickable-row) {
  cursor: pointer;
}

.plan-title-link {
  color: var(--el-color-primary);
  text-decoration: none;
  font-weight: 500;
}

.plan-title-link:hover {
  text-decoration: underline;
}

.schedule-badge {
  margin-left: 8px;
  vertical-align: middle;
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

.import-file-area {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 20px 0;
}

.import-file-name {
  font-size: 13px;
  color: var(--fchub-text-secondary);
}
</style>
