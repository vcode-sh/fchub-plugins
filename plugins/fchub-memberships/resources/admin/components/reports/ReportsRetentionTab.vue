<template>
  <el-tab-pane label="Retention" name="retention">
    <el-card shadow="never" v-loading="loading">
      <template #header>
        <div class="retention-header">
          <span>Retention Cohorts</span>
          <el-select
            :model-value="months"
            @update:model-value="$emit('update:months', $event)"
            size="small"
            style="width: 140px"
          >
            <el-option :value="3" label="3 months" />
            <el-option :value="6" label="6 months" />
            <el-option :value="9" label="9 months" />
            <el-option :value="12" label="12 months" />
          </el-select>
        </div>
      </template>
      <div class="cohort-table-wrapper">
        <table v-if="cohorts.length > 0" class="cohort-table">
          <thead>
            <tr>
              <th class="cohort-label-col">Cohort</th>
              <th
                v-for="col in columnCount"
                :key="col - 1"
                class="cohort-data-col"
              >
                Month {{ col - 1 }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, idx) in cohorts" :key="idx">
              <td class="cohort-label-col">{{ formatCohortLabel(row.cohort) }}</td>
              <td
                v-for="col in columnCount"
                :key="col - 1"
                class="cohort-data-col"
                :style="cellStyle(row[col - 1])"
              >
                {{ row[col - 1] != null ? row[col - 1] + '%' : '—' }}
              </td>
            </tr>
          </tbody>
        </table>
        <el-empty v-else description="No cohort data available" :image-size="60" />
      </div>
    </el-card>
  </el-tab-pane>
</template>

<script setup>
import { computed } from 'vue'
import { formatReportPeriodLabel } from '@/utils/wpDate.js'

const props = defineProps({
  loading: Boolean,
  cohorts: { type: Array, default: () => [] },
  months: { type: Number, default: 6 },
})

defineEmits(['update:months'])

const columnCount = computed(() => {
  let max = 0
  for (const row of props.cohorts) {
    for (const key of Object.keys(row)) {
      const num = Number(key)
      if (!Number.isNaN(num) && num >= 0 && num > max) {
        max = num
      }
    }
  }
  return max + 1
})

function formatCohortLabel(value) {
  return formatReportPeriodLabel(value)
}

function cellStyle(value) {
  if (value == null) return {}
  const pct = Math.max(0, Math.min(100, value))
  const r = Math.round(245 - (245 - 103) * (pct / 100))
  const g = Math.round(108 + (194 - 108) * (pct / 100))
  const b = Math.round(108 - (108 - 58) * (pct / 100))
  const alpha = 0.15 + 0.25 * (pct / 100)
  return {
    backgroundColor: `rgba(${r}, ${g}, ${b}, ${alpha})`,
    color: 'var(--fchub-text-primary)',
    fontWeight: '500',
  }
}
</script>

<style scoped>
.retention-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.cohort-table-wrapper {
  overflow-x: auto;
}

.cohort-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}

.cohort-table th,
.cohort-table td {
  padding: 8px 12px;
  border: 1px solid var(--el-border-color-lighter, #ebeef5);
  text-align: center;
  white-space: nowrap;
}

.cohort-label-col {
  text-align: left;
  font-weight: 500;
  min-width: 120px;
}

.cohort-data-col {
  min-width: 80px;
}

.cohort-table thead th {
  background-color: var(--el-fill-color-light, #f5f7fa);
  color: var(--fchub-text-secondary, #606266);
  font-weight: 600;
}
</style>
