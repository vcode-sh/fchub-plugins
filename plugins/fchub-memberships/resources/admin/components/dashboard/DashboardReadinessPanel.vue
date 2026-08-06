<template>
  <section class="panel readiness-panel" aria-labelledby="readiness-heading">
    <div class="section-heading">
      <div>
        <p class="section-eyebrow">Setup health</p>
        <h2 id="readiness-heading">Readiness</h2>
      </div>
      <span class="readiness-score">{{ completedSteps }}/3 ready</span>
    </div>

    <div class="readiness-list">
      <div v-for="step in steps" :key="step.key" class="readiness-step">
        <span class="readiness-icon" :class="{ 'is-complete': step.complete }" aria-hidden="true">
          <el-icon><Check v-if="step.complete" /><ArrowRight v-else /></el-icon>
        </span>
        <div class="readiness-copy">
          <strong>{{ step.title }}</strong>
          <span>{{ step.description }}</span>
        </div>
        <strong class="readiness-value">{{ formatCount(step.count) }}</strong>
        <router-link v-if="!step.complete" :to="step.destination" class="text-action">
          {{ step.action }}
          <el-icon aria-hidden="true"><ArrowRight /></el-icon>
        </router-link>
      </div>
    </div>
  </section>
</template>

<script setup>
import { ArrowRight, Check } from '@element-plus/icons-vue'
import { formatCount } from '@/pages/dashboardUi.js'

defineProps({
  completedSteps: {
    type: Number,
    required: true,
  },
  steps: {
    type: Array,
    required: true,
  },
})
</script>
