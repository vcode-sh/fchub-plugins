<template>
  <section class="panel activity-panel" aria-labelledby="activity-heading">
    <div class="section-heading">
      <div>
        <p class="section-eyebrow">Audit trail</p>
        <h2 id="activity-heading">Recent activity</h2>
      </div>
    </div>
    <ol v-if="activity.length" class="activity-list">
      <li v-for="entry in activity" :key="entry.id" class="activity-item">
        <span class="activity-icon" aria-hidden="true"><el-icon><Tickets /></el-icon></span>
        <div class="activity-copy">
          <strong>{{ actionLabel(entry) }}</strong>
          <span>{{ entityLabel(entry) }} · {{ actorLabel(entry) }}</span>
        </div>
        <time :datetime="toIsoDateTime(entry.occurred_at) || undefined">
          {{ formatWpDateTime(entry.occurred_at, 'Date unavailable') }}
        </time>
      </li>
    </ol>
    <div v-else class="compact-empty">
      <el-icon aria-hidden="true"><Tickets /></el-icon>
      <div>
        <strong>No recorded activity yet</strong>
        <span>Plan, protection, and access changes will appear here.</span>
      </div>
    </div>
  </section>
</template>

<script setup>
import { Tickets } from '@element-plus/icons-vue'
import {
  actionLabel,
  actorLabel,
  entityLabel,
  toIsoDateTime,
} from '@/pages/dashboardUi.js'
import { formatWpDateTime } from '@/utils/wpDate.js'

defineProps({
  activity: {
    type: Array,
    required: true,
  },
})
</script>
