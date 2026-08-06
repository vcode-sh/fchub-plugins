<template>
  <section class="summary-grid" aria-label="Membership summary">
    <router-link to="/members" class="summary-metric">
      <span class="summary-icon summary-icon--blue" aria-hidden="true"><el-icon><UserFilled /></el-icon></span>
      <span class="summary-label">Active members</span>
      <strong class="summary-value">{{ formatCount(summary.active_members) }}</strong>
      <span class="summary-support">Current access</span>
    </router-link>
    <router-link to="/members" class="summary-metric">
      <span class="summary-icon summary-icon--orange" aria-hidden="true"><el-icon><Plus /></el-icon></span>
      <span class="summary-label">New in 30 days</span>
      <strong class="summary-value">{{ formatCount(summary.new_members_30d) }}</strong>
      <span class="summary-support">{{ formatCount(summary.grants_30d) }} access grants issued</span>
    </router-link>
    <router-link to="/members" class="summary-metric">
      <span class="summary-icon summary-icon--purple" aria-hidden="true"><el-icon><Clock /></el-icon></span>
      <span class="summary-label">Expiring in 7 days</span>
      <strong class="summary-value">{{ formatCount(summary.expiring_7d) }}</strong>
      <span class="summary-support">Review upcoming expiry</span>
    </router-link>
    <router-link to="/drip" class="summary-metric">
      <span class="summary-icon summary-icon--pink" aria-hidden="true"><el-icon><Bell /></el-icon></span>
      <span class="summary-label">Failed notifications</span>
      <strong class="summary-value">{{ formatCount(summary.failed_notifications) }}</strong>
      <span class="summary-support">Check delivery queue</span>
    </router-link>
  </section>
</template>

<script setup>
import { Bell, Clock, Plus, UserFilled } from '@element-plus/icons-vue'
import { formatCount } from '@/pages/dashboardUi.js'

defineProps({
  summary: {
    type: Object,
    required: true,
  },
})
</script>
