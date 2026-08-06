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

      <PlanListTable
        v-else
        :loading="loading"
        :plans="plans_data"
        @row-click="handleRowClick"
        @action="handleAction"
      />

      <PlanListMobile
        v-if="!errorMessage"
        :loading="loading"
        :plans="plans_data"
        @action="handleAction"
      />

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

    <PlanImportDialog
      v-model="importDialogVisible"
      v-model:json="importJson"
      v-model:mode="importMode"
      :file-name="importFileName"
      :importing="importing"
      @file-selected="onFileSelected"
      @import="handleImport"
    />
  </div>
</template>

<script setup>
import {
  ArrowDown,
  Download,
  Plus,
  Search,
  Upload,
} from '@element-plus/icons-vue'
import WorkspacePageHeader from '@/components/workspace/WorkspacePageHeader.vue'
import OperationsSummary from '@/components/workspace/OperationsSummary.vue'
import ListStatePanel from '@/components/workspace/ListStatePanel.vue'
import PlanImportDialog from '@/components/plans/PlanImportDialog.vue'
import PlanListMobile from '@/components/plans/PlanListMobile.vue'
import PlanListTable from '@/components/plans/PlanListTable.vue'
import { usePlanList } from '@/composables/plans/usePlanList.js'

const {
  loading,
  plans_data,
  total,
  errorMessage,
  summary,
  filters,
  totalPages,
  hasActiveFilters,
  summaryItems,
  deleteDialogVisible,
  deleteLoading,
  planToDelete,
  importDialogVisible,
  importJson,
  importing,
  importMode,
  importFileName,
  bulkExporting,
  debouncedFetch,
  resetAndFetch,
  clearFilters,
  handleUtility,
  fetchPlans,
  handleRowClick,
  handleAction,
  confirmDelete,
  onFileSelected,
  handleImport,
} = usePlanList()
</script>

<style scoped src="./PlanList.css"></style>
