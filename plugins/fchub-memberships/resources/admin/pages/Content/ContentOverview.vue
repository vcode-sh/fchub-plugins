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

    <!-- Protection Rules List -->
    <el-card shadow="never" class="list-card">
      <!-- Tabs -->
      <el-tabs v-model="activeTab" @tab-change="onTabChange">
        <el-tab-pane label="All" name="all" />
        <el-tab-pane
          v-for="group in groupTabs"
          :key="group.key"
          :label="group.label"
          :name="group.key"
        />
      </el-tabs>

      <!-- Search & Filters -->
      <div class="search-bar">
        <el-input
          v-model="filters.search"
          placeholder="Search protected content..."
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
            v-model="filters.resource_type"
            placeholder="All Types"
            clearable
            @change="resetAndFetch"
          >
            <el-option-group
              v-for="group in resourceTypeGroups"
              :key="group.label"
              :label="group.label"
            >
              <el-option
                v-for="opt in group.options"
                :key="opt.value"
                :label="opt.label"
                :value="opt.value"
              />
            </el-option-group>
          </el-select>
        </div>
      </div>

      <!-- Bulk actions bar -->
      <div class="bulk-bar" v-if="selectedRows.length > 0">
        <span class="bulk-count">{{ selectedRows.length }} selected</span>
        <el-popconfirm
          title="Remove protection from all selected items?"
          confirm-button-text="Unprotect"
          cancel-button-text="Cancel"
          confirm-button-type="danger"
          @confirm="handleBulkUnprotect"
        >
          <template #reference>
            <el-button size="small" type="danger" plain>
              <el-icon><Unlock /></el-icon>
              Bulk Unprotect
            </el-button>
          </template>
        </el-popconfirm>
      </div>

      <!-- Table -->
      <el-table
        ref="tableRef"
        v-loading="loading"
        :data="items"
        @selection-change="onSelectionChange"
        row-key="id"
      >
        <el-table-column type="selection" width="40" />

        <el-table-column label="Resource" min-width="240">
          <template #default="{ row }">
            <div class="resource-cell">
              <a v-if="row.edit_url" :href="row.edit_url" target="_blank" class="content-title-link">
                {{ row.resource_title }}
              </a>
              <span v-else class="content-title-text">{{ row.resource_title }}</span>
            </div>
          </template>
        </el-table-column>

        <el-table-column label="Type" width="140">
          <template #default="{ row }">
            <el-tag size="small" :type="typeTagColor(row.resource_type_group)">
              {{ row.resource_type_label || row.resource_type }}
            </el-tag>
          </template>
        </el-table-column>

        <el-table-column label="Plans" min-width="200">
          <template #default="{ row }">
            <div class="plans-cell" v-if="(row.plan_names || []).length > 0">
              <el-tag
                v-for="name in row.plan_names"
                :key="name"
                size="small"
                type="info"
                class="plan-tag"
              >
                {{ name }}
              </el-tag>
            </div>
            <span v-else class="text-muted">-</span>
          </template>
        </el-table-column>

        <el-table-column label="Teaser" width="90" align="center">
          <template #default="{ row }">
            <el-tag v-if="row.show_teaser === 'yes'" size="small" type="success">On</el-tag>
            <span v-else class="text-muted">Off</span>
          </template>
        </el-table-column>

        <el-table-column label="Protected Since" width="140">
          <template #default="{ row }">
            {{ formatDate(row.created_at) }}
          </template>
        </el-table-column>

        <el-table-column label="Actions" width="140" align="center" fixed="right">
          <template #default="{ row }">
            <el-button type="primary" text size="small" @click="openEditDrawer(row)">
              Edit
            </el-button>
            <el-popconfirm
              title="Remove content protection?"
              confirm-button-text="Unprotect"
              cancel-button-text="Cancel"
              confirm-button-type="danger"
              @confirm="handleUnprotect(row)"
            >
              <template #reference>
                <el-button type="danger" text size="small">
                  <el-icon><Unlock /></el-icon>
                </el-button>
              </template>
            </el-popconfirm>
            <a
              v-if="row.edit_url"
              :href="row.edit_url"
              target="_blank"
              class="view-link"
            >
              <el-button type="info" text size="small">
                <el-icon><View /></el-icon>
              </el-button>
            </a>
          </template>
        </el-table-column>
      </el-table>

      <!-- Empty State -->
      <div v-if="!loading && items.length === 0 && !hasActiveFilters" class="empty-state">
        <el-empty :image-size="80">
          <template #description>
            <h3 class="empty-title">No protected content yet</h3>
            <p class="empty-text">Start protecting your content to restrict access for members only.</p>
          </template>
          <el-button type="primary" @click="openProtectWizard()">
            <el-icon><Lock /></el-icon>
            Get Started
          </el-button>
        </el-empty>
      </div>
      <el-empty v-else-if="!loading && items.length === 0 && hasActiveFilters" description="No protected content matches your filters" />

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
          @current-change="fetchContent"
        />
      </div>
    </el-card>

    <!-- Protect Content Wizard Dialog -->
    <el-dialog
      v-model="wizardVisible"
      title="Protect Content"
      width="640px"
      :close-on-click-modal="false"
      @close="resetWizard"
      class="wizard-dialog"
    >
      <el-steps :active="wizardStep" align-center finish-status="success" class="wizard-steps">
        <el-step title="Category" />
        <el-step title="Resource" />
        <el-step title="Configure" />
        <el-step title="Review" />
      </el-steps>

      <div class="wizard-body">
        <!-- Step 1: Choose Category -->
        <div v-if="wizardStep === 0" class="wizard-step-content">
          <p class="wizard-instruction">What type of content do you want to protect?</p>
          <div class="wizard-category-grid">
            <div
              v-for="card in wizardCategoryCards"
              :key="card.key"
              class="wizard-category-card"
              :class="{ selected: wizardForm.categoryKey === card.key }"
              @click="selectWizardCategory(card)"
            >
              <el-icon :size="28"><component :is="card.icon" /></el-icon>
              <span class="wizard-category-label">{{ card.label }}</span>
            </div>
          </div>
        </div>

        <!-- Step 2: Select Resource -->
        <div v-if="wizardStep === 1" class="wizard-step-content">
          <p class="wizard-instruction">Select the {{ wizardForm.categoryLabel }} to protect</p>

          <!-- Resource type sub-select (if category has multiple types) -->
          <el-form-item
            v-if="wizardCategoryTypes.length > 1"
            label="Resource Type"
            class="wizard-form-item"
          >
            <el-select
              v-model="wizardForm.resource_type"
              placeholder="Select type"
              style="width: 100%"
              @change="onWizardTypeChange"
            >
              <el-option
                v-for="t in wizardCategoryTypes"
                :key="t.value"
                :label="t.label"
                :value="t.value"
              />
            </el-select>
          </el-form-item>

          <!-- URL Pattern input -->
          <el-form-item
            v-if="wizardForm.resource_type === 'url_pattern'"
            label="URL Pattern"
            class="wizard-form-item"
          >
            <el-input
              v-model="wizardForm.resource_id"
              placeholder="e.g. /members-only/* or /premium/*"
            />
            <div class="field-hint">Use * as wildcard. Example: /premium/* matches all URLs starting with /premium/</div>
          </el-form-item>

          <!-- Special page select -->
          <el-form-item
            v-else-if="wizardForm.resource_type === 'special_page'"
            label="Special Page"
            class="wizard-form-item"
          >
            <el-select
              v-model="wizardForm.resource_id"
              placeholder="Select a special page"
              style="width: 100%"
              :loading="resourceSearchLoading"
            >
              <el-option
                v-for="item in resourceOptions"
                :key="item.id"
                :label="item.label || item.title"
                :value="String(item.id)"
              />
            </el-select>
          </el-form-item>

          <!-- Comment type: wildcard or specific post -->
          <el-form-item
            v-else-if="wizardForm.resource_type === 'comment'"
            label="Comment Protection"
            class="wizard-form-item"
          >
            <el-radio-group v-model="wizardForm.commentMode" @change="onCommentModeChange">
              <el-radio value="all">All protected content comments</el-radio>
              <el-radio value="specific">Comments on a specific post</el-radio>
            </el-radio-group>
            <el-select
              v-if="wizardForm.commentMode === 'specific'"
              v-model="wizardForm.resource_id"
              filterable
              remote
              :remote-method="searchResources"
              :loading="resourceSearchLoading"
              placeholder="Search for a post..."
              style="width: 100%; margin-top: 8px"
            >
              <el-option
                v-for="item in resourceOptions"
                :key="item.id"
                :label="item.label || item.title"
                :value="String(item.id)"
              />
            </el-select>
          </el-form-item>

          <!-- Standard search select for searchable types -->
          <el-form-item
            v-else
            label="Resource"
            class="wizard-form-item"
          >
            <el-select
              v-model="wizardForm.resource_id"
              filterable
              remote
              :remote-method="searchResources"
              :loading="resourceSearchLoading"
              placeholder="Search for content..."
              style="width: 100%"
            >
              <el-option
                v-for="item in resourceOptions"
                :key="item.id"
                :label="item.label || item.title"
                :value="String(item.id)"
              />
            </el-select>
          </el-form-item>
        </div>

        <!-- Step 3: Configure Protection -->
        <div v-if="wizardStep === 2" class="wizard-step-content">
          <p class="wizard-instruction">Configure protection settings</p>

          <el-form label-position="top">
            <el-form-item label="Plans" required>
              <el-select
                v-model="wizardForm.plan_ids"
                multiple
                placeholder="Select plans that grant access"
                style="width: 100%"
                :loading="planOptionsLoading"
              >
                <el-option
                  v-for="plan in planOptions"
                  :key="plan.id"
                  :label="plan.title"
                  :value="plan.id"
                />
              </el-select>
            </el-form-item>

            <el-form-item label="Show Teaser">
              <el-select v-model="wizardForm.show_teaser" style="width: 200px">
                <el-option label="No" value="no" />
                <el-option label="Yes" value="yes" />
              </el-select>
              <div class="field-hint">Show a preview excerpt before the restriction message.</div>
            </el-form-item>

            <el-form-item label="Restriction Message">
              <el-input
                v-model="wizardForm.restriction_message"
                type="textarea"
                :rows="3"
                placeholder="Custom message shown to non-members (leave empty for default)"
              />
            </el-form-item>

            <el-form-item label="Redirect URL">
              <el-input
                v-model="wizardForm.redirect_url"
                placeholder="https://example.com/upgrade (optional)"
              />
              <div class="field-hint">Redirect non-members to this URL instead of showing the restriction message.</div>
            </el-form-item>
          </el-form>
        </div>

        <!-- Step 4: Review -->
        <div v-if="wizardStep === 3" class="wizard-step-content">
          <p class="wizard-instruction">Review and confirm</p>

          <div class="review-summary">
            <div class="review-row">
              <span class="review-label">Type</span>
              <span class="review-value">
                <el-tag size="small">{{ wizardForm.resource_type_label || wizardForm.resource_type }}</el-tag>
              </span>
            </div>
            <div class="review-row">
              <span class="review-label">Resource</span>
              <span class="review-value">{{ wizardResourceDisplayName }}</span>
            </div>
            <div class="review-row">
              <span class="review-label">Plans</span>
              <span class="review-value">
                <el-tag
                  v-for="id in wizardForm.plan_ids"
                  :key="id"
                  size="small"
                  type="info"
                  class="plan-tag"
                >
                  {{ planOptionsMap[id] || `Plan #${id}` }}
                </el-tag>
              </span>
            </div>
            <div class="review-row">
              <span class="review-label">Teaser</span>
              <span class="review-value">{{ wizardForm.show_teaser === 'yes' ? 'Enabled' : 'Disabled' }}</span>
            </div>
            <div class="review-row" v-if="wizardForm.restriction_message">
              <span class="review-label">Message</span>
              <span class="review-value review-message">{{ wizardForm.restriction_message }}</span>
            </div>
            <div class="review-row" v-if="wizardForm.redirect_url">
              <span class="review-label">Redirect</span>
              <span class="review-value">{{ wizardForm.redirect_url }}</span>
            </div>
          </div>
        </div>
      </div>

      <template #footer>
        <div class="wizard-footer">
          <el-button v-if="wizardStep > 0" @click="wizardStep--">Back</el-button>
          <div class="wizard-footer-right">
            <el-button @click="wizardVisible = false">Cancel</el-button>
            <el-button
              v-if="wizardStep < 3"
              type="primary"
              :disabled="!canAdvanceWizard"
              @click="wizardStep++"
            >
              Next
            </el-button>
            <el-button
              v-else
              type="primary"
              :loading="protectLoading"
              @click="submitProtect"
            >
              Protect
            </el-button>
          </div>
        </div>
      </template>
    </el-dialog>

    <!-- Edit Drawer -->
    <el-drawer
      v-model="editDrawerVisible"
      title="Edit Protection Rule"
      direction="rtl"
      size="420px"
      :close-on-click-modal="false"
    >
      <div v-if="editForm" class="edit-drawer-body">
        <div class="edit-resource-header">
          <el-tag size="small" :type="typeTagColor(editForm.resource_type_group)">
            {{ editForm.resource_type_label || editForm.resource_type }}
          </el-tag>
          <h4 class="edit-resource-title">{{ editForm.resource_title }}</h4>
        </div>

        <el-form label-position="top">
          <el-form-item label="Plans">
            <el-select
              v-model="editForm.plan_ids"
              multiple
              placeholder="Select plans"
              style="width: 100%"
            >
              <el-option
                v-for="plan in planOptions"
                :key="plan.id"
                :label="plan.title"
                :value="plan.id"
              />
            </el-select>
          </el-form-item>

          <el-form-item label="Show Teaser">
            <el-select v-model="editForm.show_teaser" style="width: 200px">
              <el-option label="No" value="no" />
              <el-option label="Yes" value="yes" />
            </el-select>
          </el-form-item>

          <el-form-item label="Restriction Message">
            <el-input
              v-model="editForm.restriction_message"
              type="textarea"
              :rows="3"
              placeholder="Custom message (leave empty for default)"
            />
          </el-form-item>

          <el-form-item label="Redirect URL">
            <el-input
              v-model="editForm.redirect_url"
              placeholder="https://example.com/upgrade"
            />
          </el-form-item>
        </el-form>
      </div>

      <template #footer>
        <el-button @click="editDrawerVisible = false">Cancel</el-button>
        <el-button type="primary" :loading="editSaving" @click="saveEdit">
          Save Changes
        </el-button>
      </template>
    </el-drawer>
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

// ─── Edit Drawer ───

const editDrawerVisible = ref(false)
const editSaving = ref(false)
const editForm = ref(null)

function openEditDrawer(row) {
  editForm.value = {
    id: row.id,
    resource_title: row.resource_title,
    resource_type: row.resource_type,
    resource_type_label: row.resource_type_label,
    resource_type_group: row.resource_type_group,
    plan_ids: [...(row.plan_ids || [])],
    show_teaser: row.show_teaser || 'no',
    restriction_message: row.restriction_message || '',
    redirect_url: row.redirect_url || '',
  }
  editDrawerVisible.value = true
}

async function saveEdit() {
  if (!editForm.value) return
  editSaving.value = true
  try {
    await content.update(editForm.value.id, {
      plan_ids: editForm.value.plan_ids,
      show_teaser: editForm.value.show_teaser,
      restriction_message: editForm.value.restriction_message,
      redirect_url: editForm.value.redirect_url,
    })
    ElMessage.success('Protection rule updated')
    editDrawerVisible.value = false
    await fetchContent()
  } catch (err) {
    ElMessage.error(err.message || 'Failed to update rule')
  } finally {
    editSaving.value = false
  }
}

// ─── Protect Wizard ───

const wizardVisible = ref(false)
const wizardStep = ref(0)
const protectLoading = ref(false)
const resourceSearchLoading = ref(false)
const resourceOptions = ref([])

const wizardForm = reactive({
  categoryKey: '',
  categoryLabel: '',
  resource_type: '',
  resource_type_label: '',
  resource_id: '',
  plan_ids: [],
  show_teaser: 'no',
  restriction_message: '',
  redirect_url: '',
  commentMode: 'all',
})

function openProtectWizard(categoryKey) {
  resetWizard()
  wizardVisible.value = true
  if (categoryKey) {
    selectWizardCategory(wizardCategoryCards.value.find(c => c.key === categoryKey))
  }
}

const wizardCategoryTypes = computed(() => {
  if (!wizardForm.categoryKey) return []

  const key = wizardForm.categoryKey
  const allTypes = resourceTypes.value

  switch (key) {
    case 'posts_pages':
      return allTypes.filter(t => ['post', 'page'].includes(t.key)).map(t => ({ value: t.key, label: t.label }))
    case 'taxonomies':
      return allTypes.filter(t => t.group === 'taxonomy').map(t => ({ value: t.key, label: t.label }))
    case 'cpt':
      return allTypes.filter(t => t.group === 'content' && !['post', 'page'].includes(t.key)).map(t => ({ value: t.key, label: t.label }))
    case 'menu':
      return allTypes.filter(t => t.key === 'menu_item').map(t => ({ value: t.key, label: t.label }))
    case 'url':
      return allTypes.filter(t => t.key === 'url_pattern').map(t => ({ value: t.key, label: t.label }))
    case 'special':
      return allTypes.filter(t => t.key === 'special_page').map(t => ({ value: t.key, label: t.label }))
    case 'comments':
      return allTypes.filter(t => t.key === 'comment').map(t => ({ value: t.key, label: t.label }))
    default:
      return []
  }
})

function selectWizardCategory(card) {
  if (!card) return
  wizardForm.categoryKey = card.key
  wizardForm.categoryLabel = card.label
  wizardForm.resource_type = ''
  wizardForm.resource_id = ''
  resourceOptions.value = []

  // Auto-select type if only one
  const types = wizardCategoryTypes.value
  if (types.length === 1) {
    wizardForm.resource_type = types[0].value
    wizardForm.resource_type_label = types[0].label
    loadInitialResources()
  }
}

function onWizardTypeChange() {
  wizardForm.resource_id = ''
  resourceOptions.value = []
  const typeObj = wizardCategoryTypes.value.find(t => t.value === wizardForm.resource_type)
  wizardForm.resource_type_label = typeObj ? typeObj.label : wizardForm.resource_type
  loadInitialResources()
}

function onCommentModeChange() {
  if (wizardForm.commentMode === 'all') {
    wizardForm.resource_id = '*'
  } else {
    wizardForm.resource_id = ''
  }
}

async function loadInitialResources() {
  const type = wizardForm.resource_type
  if (!type) return

  // For special types, load all options immediately
  if (type === 'special_page' || type === 'menu_item') {
    resourceSearchLoading.value = true
    try {
      const res = await content.searchResources({ type, query: '' })
      resourceOptions.value = res.data ?? res ?? []
    } catch {
      resourceOptions.value = []
    } finally {
      resourceSearchLoading.value = false
    }
  }

  // For comment type, set initial mode
  if (type === 'comment') {
    wizardForm.commentMode = 'all'
    wizardForm.resource_id = '*'
  }
}

async function searchResources(query) {
  const type = wizardForm.resource_type === 'comment' ? 'post' : wizardForm.resource_type
  if (!query || !type) return
  resourceSearchLoading.value = true
  try {
    const res = await content.searchResources({ type, query })
    resourceOptions.value = res.data ?? res ?? []
  } catch {
    resourceOptions.value = []
  } finally {
    resourceSearchLoading.value = false
  }
}

const wizardResourceDisplayName = computed(() => {
  if (wizardForm.resource_type === 'url_pattern') {
    return wizardForm.resource_id || '(not set)'
  }
  if (wizardForm.resource_type === 'comment' && wizardForm.resource_id === '*') {
    return 'All Protected Content Comments'
  }
  const opt = resourceOptions.value.find(o => String(o.id) === String(wizardForm.resource_id))
  return opt ? (opt.label || opt.title) : wizardForm.resource_id || '(not set)'
})

const canAdvanceWizard = computed(() => {
  switch (wizardStep.value) {
    case 0:
      return !!wizardForm.categoryKey
    case 1:
      return !!wizardForm.resource_type && !!wizardForm.resource_id
    case 2:
      return wizardForm.plan_ids.length > 0
    default:
      return true
  }
})

async function submitProtect() {
  protectLoading.value = true
  try {
    await content.protect({
      resource_type: wizardForm.resource_type,
      resource_id: wizardForm.resource_id,
      plan_ids: wizardForm.plan_ids,
      show_teaser: wizardForm.show_teaser,
      restriction_message: wizardForm.restriction_message,
      redirect_url: wizardForm.redirect_url,
    })
    ElMessage.success('Content protected successfully')
    wizardVisible.value = false
    resetWizard()
    await fetchContent()
  } catch (err) {
    ElMessage.error(err.message || 'Failed to protect content')
  } finally {
    protectLoading.value = false
  }
}

function resetWizard() {
  wizardStep.value = 0
  wizardForm.categoryKey = ''
  wizardForm.categoryLabel = ''
  wizardForm.resource_type = ''
  wizardForm.resource_type_label = ''
  wizardForm.resource_id = ''
  wizardForm.plan_ids = []
  wizardForm.show_teaser = 'no'
  wizardForm.restriction_message = ''
  wizardForm.redirect_url = ''
  wizardForm.commentMode = 'all'
  resourceOptions.value = []
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
