<template>
  <el-table
    v-loading="loading"
    :data="plans"
    row-class-name="clickable-row"
    @row-click="emit('row-click', $event)"
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
        <el-tag :type="statusTagType(row.status)" size="small">
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
        <el-dropdown
          trigger="click"
          @command="emit('action', $event, row)"
          @click.stop
        >
          <el-button
            text
            size="small"
            :aria-label="`Plan actions for ${row.title}`"
            @click.stop
          >
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
              <el-dropdown-item
                v-if="Number(row.history_count || 0) === 0"
                command="delete"
                divided
              >
                <el-icon><Delete /></el-icon>
                <span style="color: var(--el-color-danger)">Delete</span>
              </el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </template>
    </el-table-column>
  </el-table>
</template>

<script setup>
import {
  ArrowDown,
  CircleCheck,
  CopyDocument,
  Delete,
  Download,
  Edit,
  FolderOpened,
} from '@element-plus/icons-vue'
import { formatWpDate } from '@/utils/wpDate.js'
import {
  durationLabel,
  readinessLabel,
  readinessState,
  statusTagType,
} from '@/pages/Plans/planListUi.js'

defineProps({
  loading: {
    type: Boolean,
    required: true,
  },
  plans: {
    type: Array,
    required: true,
  },
})

const emit = defineEmits({
  action: (command, plan) => typeof command === 'string' && Boolean(plan),
  'row-click': (plan) => Boolean(plan),
})

function formatDate(dateString) {
  return formatWpDate(dateString)
}
</script>

<style scoped>
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
</style>
