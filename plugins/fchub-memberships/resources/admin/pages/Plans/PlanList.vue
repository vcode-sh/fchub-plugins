<template>
  <div class="plan-list-page">
    <WorkspacePageHeader
      eyebrow="Access products"
      title="Plans"
      description="Create, publish, and maintain the access packages your members receive."
    >
      <template #actions>
        <el-dropdown @command="handleUtility">
          <el-button aria-label="Plan utilities">
            More
            <el-icon><ArrowDown /></el-icon>
          </el-button>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item command="import"><el-icon><Upload /></el-icon>Import plans</el-dropdown-item>
              <el-dropdown-item command="export" :disabled="bulkExporting"><el-icon><Download /></el-icon>Export all plans</el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
        <router-link to="/plans/new" class="primary-action-link">
          <el-icon><Plus /></el-icon>
          Create Plan
        </router-link>
      </template>
    </WorkspacePageHeader>

    <OperationsSummary label="Plan health" :items="summaryItems" />

    <el-card shadow="never" class="list-card">
      <!-- Search & Filters -->
      <div class="search-bar">
        <el-input
          v-model="filters.search"
          aria-label="Search plans"
          placeholder="Search title or slug"
          clearable
          :prefix-icon="Search"
          class="search-input"
          @input="debouncedFetch"
        />
        <div class="filter-controls">
          <el-select
            v-model="filters.status"
            aria-label="Plan status"
            placeholder="All statuses"
            clearable
            @change="resetAndFetch"
          >
            <el-option label="Active" value="active" />
            <el-option label="Inactive" value="inactive" />
            <el-option label="Archived" value="archived" />
          </el-select>
        </div>
        <el-button v-if="hasActiveFilters" text @click="clearFilters">Clear filters</el-button>
      </div>
      <div class="search-hint">Use readiness to spot active plans that do not protect any content yet.</div>

      <ListStatePanel
        v-if="errorMessage"
        kind="error"
        title="Plans could not be loaded"
        :description="errorMessage"
        action-label="Try again"
        @action="fetchPlans"
      />

      <el-table
        v-else
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
            <span class="duration-label">{{ durationLabel(row) }}</span>
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

        <el-table-column label="Readiness" min-width="150">
          <template #default="{ row }">
            <span class="readiness-state" :data-state="readinessState(row)">
              {{ readinessLabel(row) }}
            </span>
          </template>
        </el-table-column>

        <el-table-column label="Actions" width="105" align="right" fixed="right">
          <template #default="{ row }">
            <el-dropdown trigger="click" @command="(cmd) => handleAction(cmd, row)" @click.stop>
              <el-button text size="small" :aria-label="`Plan actions for ${row.title}`" @click.stop>
                Manage
                <el-icon><ArrowDown /></el-icon>
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
                  <el-dropdown-item v-if="Number(row.history_count || 0) === 0" command="delete" divided>
                    <el-icon><Delete /></el-icon>
                    <span style="color: var(--el-color-danger)">Delete</span>
                  </el-dropdown-item>
                </el-dropdown-menu>
              </template>
            </el-dropdown>
          </template>
        </el-table-column>
      </el-table>

      <div v-if="!errorMessage" v-loading="loading" class="mobile-plan-list" aria-label="Plans">
        <article v-for="plan in plans_data" :key="plan.id" class="mobile-record-card">
          <div class="mobile-record-card__topline">
            <div>
              <router-link :to="`/plans/${plan.id}/edit`" class="mobile-record-card__title">{{ plan.title }}</router-link>
              <p class="mobile-record-card__subtitle">/{{ plan.slug }}</p>
            </div>
            <el-tag :type="statusTagType(plan.status)" size="small">{{ plan.status }}</el-tag>
          </div>
          <dl class="mobile-record-card__facts">
            <div><dt>Members</dt><dd>{{ plan.members_count ?? 0 }}</dd></div>
            <div><dt>Rules</dt><dd>{{ plan.rules_count ?? 0 }}</dd></div>
            <div><dt>Duration</dt><dd>{{ durationLabel(plan) }}</dd></div>
            <div><dt>Readiness</dt><dd>{{ readinessLabel(plan) }}</dd></div>
          </dl>
          <div class="mobile-record-card__footer">
            <router-link :to="`/plans/${plan.id}/edit`">Edit plan</router-link>
            <el-dropdown trigger="click" @command="(cmd) => handleAction(cmd, plan)">
              <el-button text :aria-label="`Plan actions for ${plan.title}`">
                Manage
                <el-icon><ArrowDown /></el-icon>
              </el-button>
              <template #dropdown>
                <el-dropdown-menu>
                  <el-dropdown-item command="duplicate">Duplicate</el-dropdown-item>
                  <el-dropdown-item command="export">Export</el-dropdown-item>
                  <el-dropdown-item :command="plan.status === 'archived' ? 'activate' : 'archive'">
                    {{ plan.status === 'archived' ? 'Activate' : 'Archive' }}
                  </el-dropdown-item>
                  <el-dropdown-item v-if="Number(plan.history_count || 0) === 0" command="delete" divided>Delete</el-dropdown-item>
                </el-dropdown-menu>
              </template>
            </el-dropdown>
          </div>
        </article>
      </div>

      <ListStatePanel
        v-if="!errorMessage && !loading && plans_data.length === 0 && hasActiveFilters"
        kind="filtered"
        title="No plans match these filters"
        description="Clear the filters or search for a different title or slug."
        action-label="Clear filters"
        @action="clearFilters"
      />
      <ListStatePanel
        v-else-if="!errorMessage && !loading && plans_data.length === 0"
        kind="empty"
        title="Create the first access plan"
        description="Plans package content rules, duration, and member access into one manageable product."
        action-label="Create plan"
        @action="$router.push('/plans/new')"
      />

      <!-- Pagination -->
      <div class="pagination-bar" v-if="!errorMessage && total > 0">
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
import {
  ArrowDown,
  CircleCheck,
  CopyDocument,
  Delete,
  Download,
  Edit,
  FolderOpened,
  Plus,
  Search,
  Upload,
} from '@element-plus/icons-vue'
import { plans } from '@/api/index.js'
import { formatWpDate } from '@/utils/wpDate.js'
import WorkspacePageHeader from '@/components/workspace/WorkspacePageHeader.vue'
import OperationsSummary from '@/components/workspace/OperationsSummary.vue'
import ListStatePanel from '@/components/workspace/ListStatePanel.vue'

const router = useRouter()

const loading = ref(false)
const plans_data = ref([])
const total = ref(0)
const errorMessage = ref('')
const summary = reactive({ total: 0, active: 0, needs_content: 0, scheduled: 0 })
let requestSequence = 0

const filters = reactive({
  page: 1,
  per_page: 20,
  search: '',
  status: '',
})

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / filters.per_page)))
const hasActiveFilters = computed(() => Boolean(filters.search || filters.status))
const summaryItems = computed(() => [
  { label: 'Total plans', value: summary.total, support: 'All plan records in this workspace' },
  { label: 'Active plans', value: summary.active, support: 'Available for member access', tone: 'success' },
  { label: 'Needs content', value: summary.needs_content, support: 'Active plans without protection rules', tone: 'warning' },
  { label: 'Scheduled changes', value: summary.scheduled, support: 'Lifecycle updates waiting to run' },
])

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

function durationLabel(plan) {
  if (plan.duration_type === 'fixed_days') return `${plan.duration_days || 0} days access`
  if (plan.duration_type === 'subscription_mirror') return 'Subscription access'
  if (plan.duration_type === 'fixed_anchor') return 'Calendar anchored'
  return 'Lifetime access'
}

function readinessState(plan) {
  if (plan.status === 'archived') return 'archived'
  if (plan.scheduled_status && plan.scheduled_at) return 'scheduled'
  if (plan.status === 'active' && Number(plan.rules_count || 0) === 0) return 'attention'
  if (plan.status === 'active') return 'ready'
  return 'inactive'
}

function readinessLabel(plan) {
  const labels = {
    archived: 'Archived',
    scheduled: 'Change scheduled',
    attention: 'Needs content',
    ready: 'Ready to grant',
    inactive: 'Not published',
  }
  return labels[readinessState(plan)]
}

function clearFilters() {
  filters.search = ''
  filters.status = ''
  resetAndFetch()
}

function handleUtility(command) {
  if (command === 'import') handleImportDialog()
  if (command === 'export') handleBulkExport()
}

async function fetchPlans() {
  const requestId = ++requestSequence
  loading.value = true
  errorMessage.value = ''
  try {
    const params = {
      page: filters.page,
      per_page: filters.per_page,
    }
    if (filters.search) params.search = filters.search
    if (filters.status) params.status = filters.status

    const res = await plans.list(params)
    if (requestId !== requestSequence) return
    plans_data.value = res.data ?? []
    total.value = res.total ?? 0
    Object.assign(summary, {
      total: Number(res.summary?.total) || 0,
      active: Number(res.summary?.active) || 0,
      needs_content: Number(res.summary?.needs_content) || 0,
      scheduled: Number(res.summary?.scheduled) || 0,
    })
  } catch (err) {
    if (requestId !== requestSequence) return
    plans_data.value = []
    total.value = 0
    errorMessage.value = err.message || 'Plan data could not be loaded. Please try again.'
  } finally {
    if (requestId === requestSequence) loading.value = false
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
      if (Number(row.history_count || 0) > 0) {
        ElMessage.warning('Archive plans with access history instead of deleting them')
        break
      }
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

.primary-action-link {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  min-height: 32px;
  padding: 8px 15px;
  border: 1px solid var(--el-color-primary);
  border-radius: var(--el-border-radius-base);
  color: #fff;
  background: var(--el-color-primary);
  font-size: 14px;
  line-height: 1;
  text-decoration: none;
  box-sizing: border-box;
}

.primary-action-link:hover {
  border-color: var(--el-color-primary-light-3);
  color: #fff;
  background: var(--el-color-primary-light-3);
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

.duration-label {
  color: var(--fchub-text-primary);
  font-size: 12px;
}

.readiness-state {
  display: inline-flex;
  align-items: center;
  min-height: 24px;
  padding: 3px 8px;
  border-radius: 999px;
  color: var(--fchub-text-secondary);
  background: var(--fchub-page-bg);
  font-size: 11px;
  font-weight: 650;
}

.readiness-state[data-state='ready'] {
  color: color-mix(in srgb, var(--el-color-success) 78%, var(--fchub-text-primary));
  background: color-mix(in srgb, var(--el-color-success) 10%, var(--fchub-card-bg));
}

.readiness-state[data-state='attention'] {
  color: color-mix(in srgb, var(--el-color-warning) 78%, var(--fchub-text-primary));
  background: color-mix(in srgb, var(--el-color-warning) 12%, var(--fchub-card-bg));
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

.mobile-plan-list { display: none; }

@media (max-width: 782px) {
  .list-card :deep(.el-table) { display: none; }
  .mobile-plan-list { display: grid; gap: 12px; }
  .search-bar, .filter-controls { align-items: stretch; flex-direction: column; }
  .filter-controls .el-select { width: 100%; }
  .pagination-bar { align-items: flex-start; flex-direction: column; gap: 12px; }
  .primary-action-link { flex: 1; }
}
</style>
