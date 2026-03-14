<template>
  <div>
    <div class="page-header">
      <h2 class="fchub-page-title">Members</h2>
      <div class="header-actions">
        <el-button @click="$emit('export')" :loading="exporting">
          <el-icon><Download /></el-icon>
          Export CSV
        </el-button>
        <el-button @click="$emit('import')">
          <el-icon><Upload /></el-icon>
          Import Members
        </el-button>
        <el-button type="primary" @click="$emit('grant')">
          <el-icon><Plus /></el-icon>
          Grant Access
        </el-button>
      </div>
    </div>

    <div class="search-bar">
      <el-input
        :model-value="filters.search"
        placeholder="Search"
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
          <el-option
            v-for="plan in planOptions"
            :key="plan.id"
            :label="plan.title"
            :value="plan.id"
          />
        </el-select>
        <el-select
          :model-value="filters.status"
          placeholder="All Statuses"
          clearable
          @update:model-value="$emit('update:status', $event)"
          @change="$emit('filter-change')"
        >
          <el-option label="Active" value="active" />
          <el-option label="Paused" value="paused" />
          <el-option label="Expired" value="expired" />
          <el-option label="Revoked" value="revoked" />
        </el-select>
      </div>
    </div>
    <div class="search-hint">Search by name, email or user ID</div>
  </div>
</template>

<script setup>
import { Download, Plus, Search, Upload } from '@element-plus/icons-vue'

defineProps({
  exporting: {
    type: Boolean,
    default: false,
  },
  filters: {
    type: Object,
    required: true,
  },
  planOptions: {
    type: Array,
    default: () => [],
  },
})

defineEmits(['export', 'import', 'grant', 'update:search', 'update:planId', 'update:status', 'search-input', 'filter-change'])
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
</style>
