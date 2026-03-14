<template>
  <div v-if="selectedCount > 0" class="bulk-actions">
    <span class="bulk-count">{{ selectedCount }} selected</span>
    <el-dropdown @command="$emit('command', $event)">
      <el-button size="small">
        Bulk Actions <el-icon><ArrowDown /></el-icon>
      </el-button>
      <template #dropdown>
        <el-dropdown-menu>
          <el-dropdown-item command="grant">Grant Plan</el-dropdown-item>
          <el-dropdown-item command="revoke">Revoke Plan</el-dropdown-item>
          <el-dropdown-item command="extend">Extend Expiry</el-dropdown-item>
          <el-dropdown-item command="export" divided>Export Selected</el-dropdown-item>
        </el-dropdown-menu>
      </template>
    </el-dropdown>
    <el-button size="small" text @click="$emit('clear')">Clear Selection</el-button>
  </div>
</template>

<script setup>
import { ArrowDown } from '@element-plus/icons-vue'

defineProps({
  selectedCount: {
    type: Number,
    default: 0,
  },
})

defineEmits(['command', 'clear'])
</script>

<style scoped>
.bulk-actions {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 0;
  margin-bottom: 8px;
}

.bulk-count {
  font-size: 13px;
  color: var(--el-color-primary);
  font-weight: 500;
}
</style>
