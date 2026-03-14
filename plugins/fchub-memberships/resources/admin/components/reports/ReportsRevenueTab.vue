<template>
  <el-tab-pane label="Revenue" name="revenue">
    <div class="fchub-stat-grid fchub-stat-grid--3" v-loading="loading">
      <div class="fchub-stat-widget">
        <div class="fchub-stat-icon blue"><el-icon :size="20"><Money /></el-icon></div>
        <div class="fchub-stat-title">MRR</div>
        <div class="fchub-stat-value">${{ metrics.mrr.toFixed(2) }}</div>
      </div>
      <div class="fchub-stat-widget">
        <div class="fchub-stat-icon orange"><el-icon :size="20"><UserFilled /></el-icon></div>
        <div class="fchub-stat-title">Avg Revenue Per Member</div>
        <div class="fchub-stat-value">${{ metrics.arpm.toFixed(2) }}</div>
      </div>
      <div class="fchub-stat-widget">
        <div class="fchub-stat-icon pink"><el-icon :size="20"><TrendCharts /></el-icon></div>
        <div class="fchub-stat-title">Lifetime Value</div>
        <div class="fchub-stat-value">${{ metrics.ltv.toFixed(2) }}</div>
      </div>
    </div>

    <el-card shadow="never" v-loading="loading" class="chart-card">
      <template #header><span>Revenue Per Plan</span></template>
      <div class="chart-container">
        <Bar v-if="chartData" :data="chartData" :options="chartOptions" />
        <el-empty v-else-if="!loading" description="No data available" />
      </div>
    </el-card>
  </el-tab-pane>
</template>

<script setup>
import { Bar } from 'vue-chartjs'
import { Money, TrendCharts, UserFilled } from '@element-plus/icons-vue'

defineProps({
  loading: Boolean,
  chartData: { type: Object, default: null },
  metrics: { type: Object, required: true },
  chartOptions: { type: Object, required: true },
})
</script>
