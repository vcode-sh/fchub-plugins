<template>
  <el-tab-pane label="Renewals" name="renewals" :lazy="true">
    <div v-loading="loading">
      <div class="fchub-stat-grid fchub-stat-grid--3" style="margin-bottom: 20px">
        <div class="fchub-stat-widget">
          <div class="fchub-stat-icon blue"><el-icon :size="20"><TrendCharts /></el-icon></div>
          <div class="fchub-stat-title">Renewal Rate</div>
          <div class="fchub-stat-value">{{ data.overall_rate || 0 }}%</div>
        </div>
        <div class="fchub-stat-widget">
          <div class="fchub-stat-icon orange"><el-icon :size="20"><UserFilled /></el-icon></div>
          <div class="fchub-stat-title">Renewed Members</div>
          <div class="fchub-stat-value">{{ data.renewed_members || 0 }}</div>
        </div>
        <div class="fchub-stat-widget">
          <div class="fchub-stat-icon purple"><el-icon :size="20"><TrendCharts /></el-icon></div>
          <div class="fchub-stat-title">Avg Renewals Per Member</div>
          <div class="fchub-stat-value">{{ data.avg_renewals_per_member || 0 }}</div>
        </div>
      </div>

      <el-card shadow="never" class="chart-card" v-if="chartData">
        <template #header><span>Renewals Over Time</span></template>
        <div class="chart-container">
          <Line :data="chartData" :options="lineChartOptions" />
        </div>
      </el-card>

      <el-card shadow="never" style="margin-top: 20px">
        <template #header><span>Renewals by Plan</span></template>
        <el-table :data="data.by_plan || []">
          <el-table-column prop="plan_title" label="Plan" />
          <el-table-column prop="total_members" label="Total Members" width="140" align="center" />
          <el-table-column prop="renewed_members" label="Renewed" width="120" align="center" />
          <el-table-column label="Rate" width="100" align="center">
            <template #default="{ row }">{{ row.total_members > 0 ? ((row.renewed_members / row.total_members) * 100).toFixed(1) : 0 }}%</template>
          </el-table-column>
          <el-table-column label="Avg Renewals" width="130" align="center">
            <template #default="{ row }">{{ parseFloat(row.avg_renewals || 0).toFixed(1) }}</template>
          </el-table-column>
        </el-table>
      </el-card>
    </div>
  </el-tab-pane>
</template>

<script setup>
import { Line } from 'vue-chartjs'
import { TrendCharts, UserFilled } from '@element-plus/icons-vue'

defineProps({
  loading: Boolean,
  data: { type: Object, required: true },
  chartData: { type: Object, default: null },
  lineChartOptions: { type: Object, required: true },
})
</script>
