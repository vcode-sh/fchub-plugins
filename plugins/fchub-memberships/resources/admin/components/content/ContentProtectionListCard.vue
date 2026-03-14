<template>
  <el-card shadow="never" class="list-card">
    <el-tabs :model-value="activeTab" @update:model-value="$emit('update:activeTab', $event)" @tab-change="$emit('tab-change')">
      <el-tab-pane label="All" name="all" />
      <el-tab-pane v-for="group in groupTabs" :key="group.key" :label="group.label" :name="group.key" />
    </el-tabs>

    <div class="filter-row">
      <el-input
        :model-value="filters.search"
        placeholder="Search protected content..."
        clearable
        :prefix-icon="Search"
        class="filter-search"
        @update:model-value="$emit('update:search', $event)"
        @input="$emit('search-input')"
      />
      <el-select
        :model-value="filters.plan_id"
        placeholder="All Plans"
        clearable
        class="filter-select"
        @update:model-value="$emit('update:planId', $event)"
        @change="$emit('filter-change')"
      >
        <el-option v-for="plan in planOptions" :key="plan.id" :label="plan.title" :value="plan.id" />
      </el-select>
      <el-select
        :model-value="filters.resource_type"
        placeholder="All Types"
        clearable
        class="filter-select"
        @update:model-value="$emit('update:resourceType', $event)"
        @change="$emit('filter-change')"
      >
        <el-option-group v-for="group in resourceTypeGroups" :key="group.label" :label="group.label">
          <el-option v-for="opt in group.options" :key="opt.value" :label="opt.label" :value="opt.value" />
        </el-option-group>
      </el-select>
    </div>

    <div class="bulk-bar" v-if="selectedRows.length > 0">
      <span class="bulk-count">{{ selectedRows.length }} selected</span>
      <el-popconfirm
        title="Remove protection from all selected items?"
        confirm-button-text="Unprotect"
        cancel-button-text="Cancel"
        confirm-button-type="danger"
        @confirm="$emit('bulk-unprotect')"
      >
        <template #reference>
          <el-button size="small" type="danger" plain>
            <el-icon><Unlock /></el-icon>
            Bulk Unprotect
          </el-button>
        </template>
      </el-popconfirm>
    </div>

    <el-table
      ref="tableRef"
      v-loading="loading"
      :data="items"
      @selection-change="$emit('selection-change', $event)"
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
            <el-tag v-for="name in row.plan_names" :key="name" size="small" type="info" class="plan-tag">
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
        <template #default="{ row }">{{ formatDate(row.created_at) }}</template>
      </el-table-column>

      <el-table-column label="Actions" width="130" align="right" fixed="right">
        <template #default="{ row }">
          <div class="actions-cell">
            <el-button type="primary" text size="small" @click="$emit('edit', row)">Edit</el-button>
            <el-popconfirm
              title="Remove content protection?"
              confirm-button-text="Unprotect"
              cancel-button-text="Cancel"
              confirm-button-type="danger"
              @confirm="$emit('unprotect', row)"
            >
              <template #reference>
                <el-button type="danger" text size="small" :icon="Unlock" />
              </template>
            </el-popconfirm>
            <a v-if="row.edit_url" :href="row.edit_url" target="_blank" class="view-link">
              <el-button type="info" text size="small" :icon="View" />
            </a>
          </div>
        </template>
      </el-table-column>
    </el-table>

    <div v-if="!loading && items.length === 0 && !hasActiveFilters" class="empty-state">
      <el-empty :image-size="80">
        <template #description>
          <h3 class="empty-title">No protected content yet</h3>
          <p class="empty-text">Start protecting your content to restrict access for members only.</p>
        </template>
        <el-button type="primary" @click="$emit('protect')">
          <el-icon><Lock /></el-icon>
          Get Started
        </el-button>
      </el-empty>
    </div>
    <el-empty v-else-if="!loading && items.length === 0 && hasActiveFilters" description="No protected content matches your filters" />

    <div class="pagination-bar" v-if="total > 0">
      <div class="pagination-meta">
        <span class="pagination-total">{{ total }} {{ total === 1 ? 'rule' : 'rules' }}</span>
        <el-select
          :model-value="filters.per_page"
          size="small"
          class="per-page-select"
          @update:model-value="$emit('update:perPage', $event)"
          @change="$emit('filter-change')"
        >
          <el-option :value="10" label="10 per page" />
          <el-option :value="20" label="20 per page" />
          <el-option :value="50" label="50 per page" />
        </el-select>
      </div>
      <el-pagination
        :current-page="filters.page"
        :page-size="filters.per_page"
        :total="total"
        layout="prev, pager, next"
        background
        small
        @update:current-page="$emit('update:page', $event)"
        @current-change="$emit('page-change')"
      />
    </div>
  </el-card>
</template>

<script setup>
import { Lock, Search, Unlock, View } from '@element-plus/icons-vue'

defineProps({
  activeTab: { type: String, required: true },
  groupTabs: { type: Array, default: () => [] },
  filters: { type: Object, required: true },
  planOptions: { type: Array, default: () => [] },
  resourceTypeGroups: { type: Array, default: () => [] },
  selectedRows: { type: Array, default: () => [] },
  loading: Boolean,
  items: { type: Array, default: () => [] },
  hasActiveFilters: Boolean,
  total: Number,
  totalPages: Number,
  formatDate: { type: Function, required: true },
  typeTagColor: { type: Function, required: true },
})

defineEmits([
  'update:activeTab',
  'update:search',
  'update:planId',
  'update:resourceType',
  'update:perPage',
  'update:page',
  'tab-change',
  'search-input',
  'filter-change',
  'selection-change',
  'bulk-unprotect',
  'edit',
  'unprotect',
  'protect',
  'page-change',
])
</script>

<style scoped>
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

/* Actions Cell */
.actions-cell {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 2px;
}

.view-link {
  text-decoration: none;
  line-height: 1;
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

/* Table helpers */
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
</style>
