<template>
  <section class="attention-section panel" aria-labelledby="attention-heading">
    <div class="section-heading">
      <div>
        <p class="section-eyebrow">Operations</p>
        <h2 id="attention-heading">Needs attention</h2>
      </div>
      <span v-if="items.length" class="section-count">
        {{ items.length }} {{ items.length === 1 ? 'item' : 'items' }}
      </span>
    </div>

    <div v-if="items.length" class="attention-list">
      <router-link
        v-for="item in items"
        :key="item.key"
        :to="safeDestination(item.destination)"
        class="attention-item"
        :class="`attention-item--${safeSeverity(item.severity)}`"
      >
        <span class="severity-label">{{ severityLabel(item.severity) }}</span>
        <span class="attention-copy">
          <strong>{{ item.title }}</strong>
          <span>{{ item.description }}</span>
        </span>
        <span v-if="Number(item.count) > 0" class="attention-count">{{ item.count }}</span>
        <el-icon class="attention-arrow" aria-hidden="true"><ArrowRight /></el-icon>
      </router-link>
    </div>

    <div v-else class="healthy-state">
      <el-icon aria-hidden="true"><CircleCheck /></el-icon>
      <div>
        <strong>Nothing urgent</strong>
        <span>No membership issues need attention right now.</span>
      </div>
    </div>
  </section>
</template>

<script setup>
import { ArrowRight, CircleCheck } from '@element-plus/icons-vue'
import { safeDestination, safeSeverity, severityLabel } from '@/pages/dashboardUi.js'

defineProps({
  items: {
    type: Array,
    required: true,
  },
})
</script>
