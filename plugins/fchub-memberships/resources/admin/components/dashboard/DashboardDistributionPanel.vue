<template>
  <section class="panel distribution-panel" aria-labelledby="distribution-heading">
    <div class="section-heading">
      <div>
        <p class="section-eyebrow">Membership mix</p>
        <h2 id="distribution-heading">Plan distribution</h2>
      </div>
    </div>
    <div v-if="plans.length" class="distribution-list">
      <div v-for="(plan, index) in plans" :key="plan.plan_id" class="distribution-row">
        <div class="distribution-meta">
          <span class="distribution-rank">{{ index + 1 }}</span>
          <strong>{{ plan.plan_title || 'Untitled plan' }}</strong>
          <span>{{ formatCount(plan.count) }}</span>
        </div>
        <div class="distribution-track" aria-hidden="true">
          <span :style="{ width: `${distributionWidth(plan.count)}%` }" />
        </div>
      </div>
    </div>
    <div v-else class="compact-empty">
      <el-icon aria-hidden="true"><Tickets /></el-icon>
      <div>
        <strong>No active members to compare yet</strong>
        <span>Plan distribution appears after access is granted.</span>
      </div>
      <router-link v-if="hasActivePlan" to="/members" class="text-action">
        Grant access
        <el-icon><ArrowRight /></el-icon>
      </router-link>
      <router-link v-else to="/plans/new" class="text-action">
        Create first plan
        <el-icon><ArrowRight /></el-icon>
      </router-link>
    </div>
  </section>
</template>

<script setup>
import { ArrowRight, Tickets } from '@element-plus/icons-vue'
import { formatCount } from '@/pages/dashboardUi.js'

defineProps({
  distributionWidth: {
    type: Function,
    required: true,
  },
  hasActivePlan: {
    type: Boolean,
    required: true,
  },
  plans: {
    type: Array,
    required: true,
  },
})
</script>
