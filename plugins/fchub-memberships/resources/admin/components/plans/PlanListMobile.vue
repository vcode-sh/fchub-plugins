<template>
  <div v-loading="loading" class="mobile-plan-list" aria-label="Plans">
    <article v-for="plan in plans" :key="plan.id" class="mobile-record-card">
      <div class="mobile-record-card__topline">
        <div>
          <router-link
            :to="`/plans/${plan.id}/edit`"
            class="mobile-record-card__title"
          >
            {{ plan.title }}
          </router-link>
          <p class="mobile-record-card__subtitle">/{{ plan.slug }}</p>
        </div>
        <el-tag :type="statusTagType(plan.status)" size="small">{{ plan.status }}</el-tag>
      </div>
      <dl class="mobile-record-card__facts">
        <div><dt>Members</dt><dd>{{ plan.members_count ?? 0 }}</dd></div>
        <div><dt>Rules</dt><dd>{{ plan.rules_count ?? 0 }}</dd></div>
        <div><dt>Duration</dt><dd>{{ durationLabel(plan) }}</dd></div>
        <div><dt>Readiness</dt><dd>{{ readinessLabel(plan) }}</dd></div>
      </dl>
      <div class="mobile-record-card__footer">
        <router-link :to="`/plans/${plan.id}/edit`">Edit plan</router-link>
        <el-dropdown trigger="click" @command="emit('action', $event, plan)">
          <el-button text :aria-label="`Plan actions for ${plan.title}`">
            Manage
            <el-icon><ArrowDown /></el-icon>
          </el-button>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item command="duplicate">Duplicate</el-dropdown-item>
              <el-dropdown-item command="export">Export</el-dropdown-item>
              <el-dropdown-item :command="plan.status === 'archived' ? 'activate' : 'archive'">
                {{ plan.status === 'archived' ? 'Activate' : 'Archive' }}
              </el-dropdown-item>
              <el-dropdown-item
                v-if="Number(plan.history_count || 0) === 0"
                command="delete"
                divided
              >
                Delete
              </el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </div>
    </article>
  </div>
</template>

<script setup>
import { ArrowDown } from '@element-plus/icons-vue'
import {
  durationLabel,
  readinessLabel,
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
})
</script>

<style scoped>
.mobile-plan-list {
  display: none;
}

@media (max-width: 782px) {
  .mobile-plan-list {
    display: grid;
    gap: 12px;
  }
}
</style>
