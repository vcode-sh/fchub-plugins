<template>
  <el-card shadow="never" class="list-card">
    <el-tabs :model-value="activeTab" @update:model-value="$emit('update:activeTab', $event)" @tab-change="$emit('tab-change')">
      <el-tab-pane label="All" name="all" />
      <el-tab-pane v-for="group in groupTabs" :key="group.key" :label="group.label" :name="group.key" />
    </el-tabs>

    <div class="search-bar">
      <el-input
        :model-value="filters.search"
        placeholder="Search protected content..."
        clearable
        :prefix-icon="Search"
        class="search-input"
        @update:model-value="$emit('update:search', $event)"
        @input="$emit('search-input')"
      />
      <div class="filter-controls">
        <el-select
          :model-value="filters.plan_id"
          placeholder="All Plans"
          clearable
          @update:model-value="$emit('update:planId', $event)"
          @change="$emit('filter-change')"
        >
          <el-option v-for="plan in planOptions" :key="plan.id" :label="plan.title" :value="plan.id" />
        </el-select>
        <el-select
          :model-value="filters.resource_type"
          placeholder="All Types"
          clearable
          @update:model-value="$emit('update:resourceType', $event)"
          @change="$emit('filter-change')"
        >
          <el-option-group v-for="group in resourceTypeGroups" :key="group.label" :label="group.label">
            <el-option v-for="opt in group.options" :key="opt.value" :label="opt.label" :value="opt.value" />
          </el-option-group>
        </el-select>
      </div>
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

      <el-table-column label="Actions" width="140" align="center" fixed="right">
        <template #default="{ row }">
          <el-button type="primary" text size="small" @click="$emit('edit', row)">Edit</el-button>
          <el-popconfirm
            title="Remove content protection?"
            confirm-button-text="Unprotect"
            cancel-button-text="Cancel"
            confirm-button-type="danger"
            @confirm="$emit('unprotect', row)"
          >
            <template #reference>
              <el-button type="danger" text size="small">
                <el-icon><Unlock /></el-icon>
              </el-button>
            </template>
          </el-popconfirm>
          <a v-if="row.edit_url" :href="row.edit_url" target="_blank" class="view-link">
            <el-button type="info" text size="small">
              <el-icon><View /></el-icon>
            </el-button>
          </a>
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
      <div class="pagination-info">
        <span>Page {{ filters.page }} of {{ totalPages }}</span>
        <el-select
          :model-value="filters.per_page"
          size="small"
          class="per-page-select"
          @update:model-value="$emit('update:perPage', $event)"
          @change="$emit('filter-change')"
        >
          <el-option :value="10" label="10 / page" />
          <el-option :value="20" label="20 / page" />
          <el-option :value="50" label="50 / page" />
        </el-select>
        <span>Total {{ total }}</span>
      </div>
      <el-pagination
        :current-page="filters.page"
        :page-size="filters.per_page"
        :total="total"
        layout="prev, pager, next"
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
