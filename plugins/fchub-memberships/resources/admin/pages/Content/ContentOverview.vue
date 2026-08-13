<template>
  <div class="content-overview-page">
    <WorkspacePageHeader
      eyebrow="Access rules"
      title="Content Protection"
      description="Choose what is protected, see who can access it, and change rules without hunting through WordPress."
    >
      <template #actions><el-button type="primary" @click="openProtectWizard()">
        <el-icon><Lock /></el-icon>
        Protect Content
      </el-button></template>
    </WorkspacePageHeader>

    <OperationsSummary label="Protection health" :items="summaryItems" />

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
      :special-pages="specialPages"
      :plan-options-loading="planOptionsLoading"
      :plan-options="planOptions"
      :plan-options-map="planOptionsMap"
      :resource-display-name="wizardResourceDisplayName"
      :can-advance="canAdvanceWizard"
      :saving="protectLoading"
      @close="wizardVisible = false; resetWizard()"
      @back="wizardStep--"
      @next="wizardStep++"
      @select-step="wizardStep = $event"
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
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import {
  Lock,
  Document, Folder, Grid, Menu as MenuIcon,
  Link, Star, ChatDotRound,
} from '@element-plus/icons-vue'
import { content, plans } from '@/api/index.js'
import { formatWpDate } from '@/utils/wpDate.js'
import ContentProtectionWizard from '@/components/content/ContentProtectionWizard.vue'
import ContentProtectionEditDrawer from '@/components/content/ContentProtectionEditDrawer.vue'
import ContentProtectionListCard from '@/components/content/ContentProtectionListCard.vue'
import { useContentProtectionEditor } from '@/composables/content/useContentProtectionEditor.js'
import { useContentProtectionWizard } from '@/composables/content/useContentProtectionWizard.js'
import WorkspacePageHeader from '@/components/workspace/WorkspacePageHeader.vue'
import OperationsSummary from '@/components/workspace/OperationsSummary.vue'

// ─── State ───

const loading = ref(false)
const items = ref([])
const total = ref(0)
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
  resourceTypes: 0,
  teaserRules: 0,
  unassignedRules: 0,
  typeCounts: {},
})

const summaryItems = computed(() => [
  {
    label: 'Protected resources',
    value: stats.totalRules,
    support: 'WordPress resources with an active protection rule',
    tone: stats.totalRules > 0 ? 'success' : 'neutral',
  },
  {
    label: 'Resource types',
    value: stats.resourceTypes,
    support: 'Protection categories currently in use',
  },
  {
    label: 'Teasers enabled',
    value: stats.teaserRules,
    support: 'Rules showing a preview before access',
  },
  {
    label: 'Needs a plan',
    value: stats.unassignedRules,
    support: 'Rules not assigned to an access plan',
    tone: stats.unassignedRules > 0 ? 'warning' : 'neutral',
  },
])

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
    const summary = res.summary ?? {}
    stats.totalRules = Number(summary.total_rules) || 0
    stats.resourceTypes = Number(summary.resource_types) || 0
    stats.teaserRules = Number(summary.teaser_rules) || 0
    stats.unassignedRules = Number(summary.unassigned_rules) || 0
    stats.typeCounts = { ...(summary.type_counts ?? {}) }
  } catch (err) {
    ElMessage.error(err.message || 'Failed to load protected content')
  } finally {
    loading.value = false
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
  specialPages,
  wizardForm,
  wizardCategoryTypes,
  wizardResourceDisplayName,
  canAdvanceWizard,
  openProtectWizard: openProtectWizardInternal,
  selectWizardCategory,
  onWizardTypeChange,
  onCommentModeChange,
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

/* Quick Cards */
.quick-cards {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
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

/* Filter Row — inline, single row with breathing room */
.filter-row {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 16px;
}

.filter-search {
  flex: 1;
  min-width: 200px;
}

.filter-select {
  width: 180px;
  flex-shrink: 0;
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

/* Actions Cell */
.actions-cell {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 2px;
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

.pagination-meta {
  display: flex;
  align-items: center;
  gap: 12px;
}

.pagination-total {
  font-size: 13px;
  color: var(--fchub-text-secondary);
  white-space: nowrap;
}

.per-page-select {
  width: 130px;
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

@media (max-width: 1100px) {
  .quick-cards {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}

@media (max-width: 640px) {
  .quick-cards {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
  }

  .quick-card {
    padding: 14px 10px;
  }
}
</style>
