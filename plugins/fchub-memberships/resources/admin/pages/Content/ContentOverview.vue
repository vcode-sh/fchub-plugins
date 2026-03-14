<template>
  <div class="content-overview-page">
    <!-- Dashboard Header -->
    <div class="page-header">
      <div class="page-header-text">
        <h2 class="fchub-page-title">Content Protection</h2>
        <p class="page-subtitle">Control what content your members can access</p>
      </div>
      <el-button type="primary" @click="openProtectWizard()">
        <el-icon><Lock /></el-icon>
        Protect Content
      </el-button>
    </div>

    <!-- Stats Bar -->
    <div class="stats-bar" v-if="stats.totalRules > 0">
      <div class="stat-item" v-for="stat in statsDisplay" :key="stat.label">
        <span class="stat-value">{{ stat.value }}</span>
        <span class="stat-label">{{ stat.label }}</span>
      </div>
    </div>

    <!-- Quick Protect Cards -->
    <div class="quick-cards" v-if="categoryCards.length > 0">
      <div
        v-for="card in categoryCards"
        :key="card.key"
        class="quick-card"
        :class="{ active: activeCategory === card.key }"
        @click="toggleCategory(card.key)"
      >
        <div class="quick-card-icon">
          <el-icon :size="24"><component :is="card.icon" /></el-icon>
        </div>
        <div class="quick-card-info">
          <span class="quick-card-label">{{ card.label }}</span>
          <el-badge :value="card.count" :hidden="card.count === 0" type="primary" class="quick-card-badge" />
        </div>
      </div>
    </div>

    <ContentProtectionListCard
      :active-tab="activeTab"
      :group-tabs="groupTabs"
      :filters="filters"
      :plan-options="planOptions"
      :resource-type-groups="resourceTypeGroups"
      :selected-rows="selectedRows"
      :loading="loading"
      :items="items"
      :has-active-filters="hasActiveFilters"
      :total="total"
      :total-pages="totalPages"
      :format-date="formatDate"
      :type-tag-color="typeTagColor"
      @update:active-tab="activeTab = $event"
      @update:search="filters.search = $event"
      @update:plan-id="filters.plan_id = $event"
      @update:resource-type="filters.resource_type = $event"
      @update:per-page="filters.per_page = $event"
      @update:page="filters.page = $event"
      @tab-change="onTabChange"
      @search-input="debouncedFetch"
      @filter-change="resetAndFetch"
      @selection-change="onSelectionChange"
      @bulk-unprotect="handleBulkUnprotect"
      @edit="openEditDrawer"
      @unprotect="handleUnprotect"
      @protect="openProtectWizard()"
      @page-change="fetchContent"
    />

    <ContentProtectionWizard
      :visible="wizardVisible"
      :step="wizardStep"
      :form="wizardForm"
      :category-cards="wizardCategoryCards"
      :category-types="wizardCategoryTypes"
      :resource-loading="resourceSearchLoading"
      :resource-options="resourceOptions"
      :plan-options-loading="planOptionsLoading"
      :plan-options="planOptions"
      :plan-options-map="planOptionsMap"
      :resource-display-name="wizardResourceDisplayName"
      :can-advance="canAdvanceWizard"
      :saving="protectLoading"
      :search-resources="searchResources"
      @close="wizardVisible = false; resetWizard()"
      @back="wizardStep--"
      @next="wizardStep++"
      @submit="submitProtect"
      @select-category="selectWizardCategory"
      @type-change="onWizardTypeChange"
      @comment-mode-change="onCommentModeChange"
    />

    <ContentProtectionEditDrawer
      :visible="editDrawerVisible"
      :form="editForm"
      :plan-options="planOptions"
      :saving="editSaving"
      :type-tag-color="typeTagColor"
      @close="editDrawerVisible = false"
      @save="saveEdit"
    />
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { ElMessage } from 'element-plus'
import {
  Search, Lock, Unlock, View,
  Document, Folder, Grid, Menu as MenuIcon,
  Link, Star, ChatDotRound, Files,
} from '@element-plus/icons-vue'
import { content, plans } from '@/api/index.js'
import { formatWpDate } from '@/utils/wpDate.js'
import ContentProtectionWizard from '@/components/content/ContentProtectionWizard.vue'
import ContentProtectionEditDrawer from '@/components/content/ContentProtectionEditDrawer.vue'
import ContentProtectionListCard from '@/components/content/ContentProtectionListCard.vue'
import { useContentProtectionEditor } from '@/composables/content/useContentProtectionEditor.js'
import { useContentProtectionWizard } from '@/composables/content/useContentProtectionWizard.js'

// ─── State ───

const loading = ref(false)
const items = ref([])
const total = ref(0)
const tableRef = ref(null)
const selectedRows = ref([])

const filters = reactive({
  page: 1,
  per_page: 20,
  resource_type: '',
  plan_id: '',
  search: '',
})

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / filters.per_page)))

const hasActiveFilters = computed(() =>
  !!(filters.search || filters.resource_type || filters.plan_id || activeTab.value !== 'all' || activeCategory.value)
)

// Resource types from API
const resourceTypes = ref([])
const resourceTypeGroups = ref([])
const groupLabels = ref({})

// Category cards / quick filter
const activeCategory = ref('')
const activeTab = ref('all')

// Plans
const planOptions = ref([])
const planOptionsLoading = ref(false)
const planOptionsMap = computed(() => {
  const map = {}
  planOptions.value.forEach(p => { map[p.id] = p.title })
  return map
})

// Stats
const stats = reactive({
  totalRules: 0,
  typeCounts: {},
})

// ─── Category Card Definitions ───

const categoryDefs = [
  { key: 'posts_pages', label: 'Posts & Pages', icon: Document, types: ['post', 'page'] },
  { key: 'taxonomies', label: 'Categories & Tags', icon: Folder, types: ['category', 'post_tag'] },
  { key: 'cpt', label: 'Custom Post Types', icon: Grid, matchGroup: 'content', excludeTypes: ['post', 'page'] },
  { key: 'menu', label: 'Menu Items', icon: MenuIcon, types: ['menu_item'] },
  { key: 'url', label: 'URL Restrictions', icon: Link, types: ['url_pattern'] },
  { key: 'special', label: 'Special Pages', icon: Star, types: ['special_page'] },
  { key: 'comments', label: 'Comments', icon: ChatDotRound, types: ['comment'] },
]

const categoryCards = computed(() => {
  return categoryDefs.map(def => {
    let count = 0
    if (def.types) {
      def.types.forEach(t => { count += stats.typeCounts[t] || 0 })
    } else if (def.matchGroup) {
      Object.entries(stats.typeCounts).forEach(([type, c]) => {
        const rt = resourceTypes.value.find(r => r.key === type)
        if (rt && rt.group === def.matchGroup && !(def.excludeTypes || []).includes(type)) {
          count += c
        }
      })
    }
    return { ...def, count }
  }).filter(card => {
    // Only show cards for types that are actually registered
    if (card.types) {
      return card.types.some(t => resourceTypes.value.find(r => r.key === t))
    }
    if (card.matchGroup) {
      return resourceTypes.value.some(r =>
        r.group === card.matchGroup && !(card.excludeTypes || []).includes(r.key)
      )
    }
    return true
  })
})

// Wizard category cards (always show all registered)
const wizardCategoryCards = computed(() => {
  const cards = []
  const allTypes = resourceTypes.value

  // Posts & Pages
  if (allTypes.some(t => t.key === 'post' || t.key === 'page')) {
    cards.push({ key: 'posts_pages', label: 'Posts & Pages', icon: Document })
  }

  // Taxonomies
  if (allTypes.some(t => t.group === 'taxonomy')) {
    cards.push({ key: 'taxonomies', label: 'Categories & Tags', icon: Folder })
  }

  // Custom Post Types (content group, excluding post/page)
  const cptTypes = allTypes.filter(t => t.group === 'content' && !['post', 'page'].includes(t.key))
  if (cptTypes.length > 0) {
    cards.push({ key: 'cpt', label: 'Custom Post Types', icon: Grid })
  }

  // Navigation
  if (allTypes.some(t => t.group === 'navigation')) {
    cards.push({ key: 'menu', label: 'Menu Items', icon: MenuIcon })
  }

  // URL Patterns
  if (allTypes.some(t => t.key === 'url_pattern')) {
    cards.push({ key: 'url', label: 'URL Restrictions', icon: Link })
  }

  // Special Pages
  if (allTypes.some(t => t.key === 'special_page')) {
    cards.push({ key: 'special', label: 'Special Pages', icon: Star })
  }

  // Comments
  if (allTypes.some(t => t.key === 'comment')) {
    cards.push({ key: 'comments', label: 'Comments', icon: ChatDotRound })
  }

  return cards
})

const groupTabs = computed(() => {
  const labels = groupLabels.value
  return Object.entries(labels).map(([key, label]) => ({ key, label }))
})

// ─── Stats Display ───

const statsDisplay = computed(() => {
  const result = []
  const tc = stats.typeCounts

  if (tc.post) result.push({ label: 'Posts', value: tc.post })
  if (tc.page) result.push({ label: 'Pages', value: tc.page })

  const taxCount = (tc.category || 0) + (tc.post_tag || 0)
  if (taxCount > 0) result.push({ label: 'Taxonomies', value: taxCount })

  result.push({ label: 'Total Rules', value: stats.totalRules })

  return result
})

// ─── Debounce ───

let searchTimer = null

function debouncedFetch() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    filters.page = 1
    fetchContent()
  }, 300)
}

function resetAndFetch() {
  filters.page = 1
  fetchContent()
}

// ─── Category filter ───

function toggleCategory(key) {
  if (activeCategory.value === key) {
    activeCategory.value = ''
    filters.resource_type = ''
  } else {
    activeCategory.value = key
    // Set filter to the matching types
    const def = categoryDefs.find(d => d.key === key)
    if (def && def.types && def.types.length === 1) {
      filters.resource_type = def.types[0]
    } else {
      // For multi-type categories, clear type filter and use tab grouping
      filters.resource_type = ''
    }
  }
  resetAndFetch()
}

function onTabChange() {
  activeCategory.value = ''
  filters.resource_type = ''
  resetAndFetch()
}

// ─── Data Loading ───

async function fetchContent() {
  loading.value = true
  try {
    const params = {
      page: filters.page,
      per_page: filters.per_page,
    }
    if (filters.resource_type) params.resource_type = filters.resource_type
    if (filters.plan_id) params.plan_id = filters.plan_id
    if (filters.search) params.search = filters.search

    // Apply category filter for multi-type categories
    if (activeCategory.value && !filters.resource_type) {
      const def = categoryDefs.find(d => d.key === activeCategory.value)
      if (def && def.types && def.types.length > 1) {
        // Send first type; backend supports single type only
        // For multi-type, we skip and rely on tab grouping
      }
    }

    // Tab group filter
    if (activeTab.value !== 'all' && !filters.resource_type) {
      // Filter by group - get all types in this group
      const groupTypes = resourceTypes.value
        .filter(t => t.group === activeTab.value)
        .map(t => t.key)
      if (groupTypes.length === 1) {
        params.resource_type = groupTypes[0]
      }
      // For multiple types in a group, the backend doesn't support
      // multi-type filter, so we filter client-side below
    }

    const res = await content.list(params)
    let data = res.data ?? []

    // Client-side group filter for tabs with multiple types
    if (activeTab.value !== 'all' && !params.resource_type) {
      const groupTypes = resourceTypes.value
        .filter(t => t.group === activeTab.value)
        .map(t => t.key)
      if (groupTypes.length > 0) {
        data = data.filter(item => groupTypes.includes(item.resource_type))
      }
    }

    // Client-side category filter for multi-type categories
    if (activeCategory.value && !params.resource_type) {
      const def = categoryDefs.find(d => d.key === activeCategory.value)
      if (def) {
        let matchTypes = []
        if (def.types) {
          matchTypes = def.types
        } else if (def.matchGroup) {
          matchTypes = resourceTypes.value
            .filter(t => t.group === def.matchGroup && !(def.excludeTypes || []).includes(t.key))
            .map(t => t.key)
        }
        if (matchTypes.length > 0) {
          data = data.filter(item => matchTypes.includes(item.resource_type))
        }
      }
    }

    items.value = data
    total.value = res.total ?? 0
    updateStats(data)
  } catch (err) {
    ElMessage.error(err.message || 'Failed to load protected content')
  } finally {
    loading.value = false
  }
}

function updateStats(data) {
  // Only update stats on unfiltered fetch
  if (!filters.search && !filters.resource_type && !filters.plan_id && activeTab.value === 'all' && !activeCategory.value) {
    stats.totalRules = total.value
    const counts = {}
    data.forEach(item => {
      counts[item.resource_type] = (counts[item.resource_type] || 0) + 1
    })
    stats.typeCounts = counts
  }
}

async function loadResourceTypes() {
  try {
    const res = await content.resourceTypes()
    resourceTypes.value = res.data ?? []
    groupLabels.value = res.groups ?? {}

    // Build grouped select options
    const selectOpts = res.select_options ?? []
    const grouped = {}
    selectOpts.forEach(opt => {
      const grp = opt.group || 'other'
      if (!grouped[grp]) {
        grouped[grp] = {
          label: groupLabels.value[grp] || grp,
          options: [],
        }
      }
      grouped[grp].options.push({ value: opt.value, label: opt.label })
    })
    resourceTypeGroups.value = Object.values(grouped)
  } catch {
    resourceTypes.value = []
  }
}

async function loadPlanOptions() {
  planOptionsLoading.value = true
  try {
    const res = await plans.options()
    const opts = res.data ?? res ?? []
    planOptions.value = opts.map(o => ({ id: o.id ?? o.value, title: o.label ?? o.title }))
  } catch {
    planOptions.value = []
  } finally {
    planOptionsLoading.value = false
  }
}

// ─── Row Actions ───

function onSelectionChange(rows) {
  selectedRows.value = rows
}

async function handleUnprotect(row) {
  try {
    await content.unprotectByResource({
      resource_type: row.resource_type,
      resource_id: row.resource_id,
    })
    ElMessage.success('Content protection removed')
    await fetchContent()
  } catch (err) {
    ElMessage.error(err.message || 'Failed to remove protection')
  }
}

async function handleBulkUnprotect() {
  if (selectedRows.value.length === 0) return
  try {
    // Group by resource type for bulk endpoint
    const byType = {}
    selectedRows.value.forEach(row => {
      if (!byType[row.resource_type]) byType[row.resource_type] = []
      byType[row.resource_type].push(row.resource_id)
    })

    for (const [resourceType, resourceIds] of Object.entries(byType)) {
      await content.bulkUnprotect({ resource_type: resourceType, resource_ids: resourceIds })
    }

    ElMessage.success(`${selectedRows.value.length} items unprotected`)
    selectedRows.value = []
    await fetchContent()
  } catch (err) {
    ElMessage.error(err.message || 'Failed to bulk unprotect')
  }
}

const {
  editDrawerVisible,
  editSaving,
  editForm,
  openEditDrawer,
  saveEdit,
} = useContentProtectionEditor({
  contentApi: content,
  fetchContent,
})

const {
  wizardVisible,
  wizardStep,
  protectLoading,
  resourceSearchLoading,
  resourceOptions,
  wizardForm,
  wizardCategoryTypes,
  wizardResourceDisplayName,
  canAdvanceWizard,
  openProtectWizard: openProtectWizardInternal,
  selectWizardCategory,
  onWizardTypeChange,
  onCommentModeChange,
  searchResources,
  submitProtect,
  resetWizard,
} = useContentProtectionWizard({
  contentApi: content,
  fetchContent,
  resourceTypes,
  planOptionsMap,
  planOptionsLoading,
})

function openProtectWizard(categoryKey) {
  openProtectWizardInternal(categoryKey, wizardCategoryCards.value)
}

// ─── Helpers ───

function formatDate(dateStr) {
  return formatWpDate(dateStr)
}

function typeTagColor(group) {
  const colors = {
    content: '',
    taxonomy: 'success',
    navigation: 'warning',
    advanced: 'danger',
  }
  return colors[group] || 'info'
}

// ─── Init ───

onMounted(async () => {
  await loadResourceTypes()
  fetchContent()
  loadPlanOptions()
})
</script>

<style scoped>
.page-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 20px;
}

.page-header-text {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.page-subtitle {
  font-size: 13px;
  color: var(--fchub-text-secondary);
  margin: 0;
}

/* Stats Bar */
.stats-bar {
  display: flex;
  gap: 24px;
  padding: 16px 20px;
  background: var(--fchub-card-bg);
  border: 1px solid var(--fchub-border-color);
  border-radius: 8px;
  margin-bottom: 20px;
}

.stat-item {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.stat-value {
  font-size: 20px;
  font-weight: 600;
  color: var(--fchub-text-primary);
}

.stat-label {
  font-size: 12px;
  color: var(--fchub-text-secondary);
}

/* Quick Cards */
.quick-cards {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: 12px;
  margin-bottom: 20px;
}

.quick-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 16px 12px;
  background: var(--fchub-card-bg);
  border: 1px solid var(--fchub-border-color);
  border-radius: 8px;
  cursor: pointer;
  transition: border-color 0.15s, box-shadow 0.15s;
  text-align: center;
}

.quick-card:hover {
  border-color: var(--el-color-primary-light-5);
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
}

.quick-card.active {
  border-color: var(--el-color-primary);
  background: var(--el-color-primary-light-9);
}

.quick-card-icon {
  width: 48px;
  height: 48px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 10px;
  background: var(--el-fill-color-lighter, #f5f7fa);
  color: var(--el-color-primary);
}

.quick-card.active .quick-card-icon {
  background: var(--el-color-primary-light-7);
}

.quick-card-info {
  display: flex;
  align-items: center;
  gap: 6px;
}

.quick-card-label {
  font-size: 13px;
  font-weight: 500;
  color: var(--fchub-text-primary);
}

/* List Card */
.list-card {
  margin-bottom: 20px;
}

.search-bar {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-bottom: 16px;
}

.search-input {
  flex: 1;
}

.filter-controls {
  display: flex;
  gap: 8px;
}

.filter-controls .el-select {
  width: 160px;
}

/* Bulk Bar */
.bulk-bar {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 12px;
  background: var(--el-color-primary-light-9);
  border: 1px solid var(--el-color-primary-light-7);
  border-radius: 6px;
  margin-bottom: 12px;
}

.bulk-count {
  font-size: 13px;
  font-weight: 500;
  color: var(--el-color-primary);
}

/* Table */
.resource-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}

.content-title-link {
  color: var(--el-color-primary);
  text-decoration: none;
}

.content-title-link:hover {
  text-decoration: underline;
}

.content-title-text {
  color: var(--fchub-text-primary);
}

.plans-cell {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}

.plan-tag {
  margin: 0;
}

.text-muted {
  color: var(--fchub-text-secondary);
  font-size: 13px;
}

.view-link {
  text-decoration: none;
}

/* Pagination */
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

/* Empty State */
.empty-state {
  padding: 40px 0;
}

.empty-title {
  font-size: 16px;
  font-weight: 600;
  color: var(--fchub-text-primary);
  margin: 0 0 4px 0;
}

.empty-text {
  font-size: 13px;
  color: var(--fchub-text-secondary);
  margin: 0 0 16px 0;
}

/* Wizard Dialog */
.wizard-steps {
  margin-bottom: 24px;
}

.wizard-body {
  min-height: 200px;
}

.wizard-instruction {
  font-size: 14px;
  color: var(--fchub-text-secondary);
  margin: 0 0 16px 0;
}

.wizard-category-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
  gap: 10px;
}

.wizard-category-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 16px 8px;
  border: 2px solid var(--fchub-border-color);
  border-radius: 8px;
  cursor: pointer;
  transition: border-color 0.15s, background-color 0.15s;
  color: var(--fchub-text-secondary);
}

.wizard-category-card:hover {
  border-color: var(--el-color-primary-light-5);
  color: var(--el-color-primary);
}

.wizard-category-card.selected {
  border-color: var(--el-color-primary);
  background: var(--el-color-primary-light-9);
  color: var(--el-color-primary);
}

.wizard-category-label {
  font-size: 12px;
  font-weight: 500;
  text-align: center;
}

.wizard-form-item {
  margin-bottom: 16px;
}

.wizard-step-content .field-hint {
  font-size: 12px;
  color: var(--fchub-text-secondary);
  margin-top: 4px;
}

.wizard-footer {
  display: flex;
  justify-content: space-between;
  width: 100%;
}

.wizard-footer-right {
  display: flex;
  gap: 8px;
}

/* Review Summary */
.review-summary {
  border: 1px solid var(--fchub-border-color);
  border-radius: 8px;
  overflow: hidden;
}

.review-row {
  display: flex;
  padding: 12px 16px;
  border-bottom: 1px solid var(--fchub-border-color);
}

.review-row:last-child {
  border-bottom: none;
}

.review-label {
  width: 100px;
  flex-shrink: 0;
  font-size: 13px;
  font-weight: 500;
  color: var(--fchub-text-secondary);
}

.review-value {
  flex: 1;
  font-size: 13px;
  color: var(--fchub-text-primary);
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  align-items: center;
}

.review-message {
  white-space: pre-wrap;
  word-break: break-word;
}

/* Edit Drawer */
.edit-drawer-body {
  padding: 0 4px;
}

.edit-resource-header {
  margin-bottom: 20px;
  padding-bottom: 16px;
  border-bottom: 1px solid var(--fchub-border-color);
}

.edit-resource-title {
  margin: 8px 0 0 0;
  font-size: 15px;
  font-weight: 600;
  color: var(--fchub-text-primary);
}
</style>
