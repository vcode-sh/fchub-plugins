<template>
  <el-tab-pane label="Overview" name="overview">
    <div class="fchub-stat-grid" v-loading="overviewLoading">
      <div class="fchub-stat-widget">
        <div class="fchub-stat-icon blue"><el-icon :size="20"><UserFilled /></el-icon></div>
        <div class="fchub-stat-title">Active Members</div>
        <div class="fchub-stat-value">{{ overview.active_members }}</div>
      </div>
      <div class="fchub-stat-widget">
        <div class="fchub-stat-icon orange"><el-icon :size="20"><Plus /></el-icon></div>
        <div class="fchub-stat-title">New This Month</div>
        <div class="fchub-stat-value">{{ overview.new_this_month }}</div>
      </div>
      <div class="fchub-stat-widget">
        <div class="fchub-stat-icon pink"><el-icon :size="20"><Remove /></el-icon></div>
        <div class="fchub-stat-title">Churned</div>
        <div class="fchub-stat-value">{{ overview.churned }}</div>
      </div>
      <div class="fchub-stat-widget">
        <div class="fchub-stat-icon purple"><el-icon :size="20"><TrendCharts /></el-icon></div>
        <div class="fchub-stat-title">Retention Rate</div>
        <div class="fchub-stat-value">{{ overview.retention_rate }}%</div>
      </div>
    </div>

    <el-card shadow="never" v-loading="chartLoading">
      <template #header><span>Members Over Time</span></template>
      <div class="chart-container">
        <Line v-if="chartData" :data="chartData" :options="lineChartOptions" />
        <el-empty v-else-if="!chartLoading" description="No data available" />
      </div>
    </el-card>
  </el-tab-pane>
</template>

<script setup>
import { Line } from 'vue-chartjs'
import { Plus, Remove, TrendCharts, UserFilled } from '@element-plus/icons-vue'

defineProps({
  overviewLoading: Boolean,
  chartLoading: Boolean,
  overview: { type: Object, required: true },
  chartData: { type: Object, default: null },
  lineChartOptions: { type: Object, required: true },
})
</script>
