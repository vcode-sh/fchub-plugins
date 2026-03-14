<template>
  <el-tab-pane label="Churn" name="churn">
    <el-card shadow="never" v-loading="loading" class="chart-card">
      <template #header><span>Churn Rate Over Time</span></template>
      <div class="chart-container">
        <Line v-if="chartData" :data="chartData" :options="chartOptions" />
        <el-empty v-else-if="!loading" description="No data available" />
      </div>
    </el-card>

    <el-card shadow="never" v-loading="loading" style="margin-top: 20px">
      <template #header><span>Churn Details</span></template>
      <el-table :data="details" style="width: 100%">
        <el-table-column prop="period" label="Period" min-width="140" />
        <el-table-column prop="churned" label="Churned" width="120" align="center" />
        <el-table-column prop="total" label="Total Members" width="140" align="center" />
        <el-table-column label="Churn Rate" width="130" align="center">
          <template #default="{ row }">{{ row.rate }}%</template>
        </el-table-column>
      </el-table>
    </el-card>
  </el-tab-pane>
</template>

<script setup>
import { Line } from 'vue-chartjs'

defineProps({
  loading: Boolean,
  chartData: { type: Object, default: null },
  details: { type: Array, default: () => [] },
  chartOptions: { type: Object, required: true },
})
</script>
