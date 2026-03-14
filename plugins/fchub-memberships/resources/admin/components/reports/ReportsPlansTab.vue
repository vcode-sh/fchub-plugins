<template>
  <el-tab-pane label="Plans" name="plans">
    <el-row :gutter="20" v-loading="loading">
      <el-col :span="10">
        <el-card shadow="never">
          <template #header><span>Plan Distribution</span></template>
          <div class="chart-container">
            <Doughnut v-if="chartData" :data="chartData" :options="doughnutChartOptions" />
            <el-empty v-else-if="!loading" description="No data available" />
          </div>
        </el-card>
      </el-col>
      <el-col :span="14">
        <el-card shadow="never">
          <template #header><span>Plans by Member Count</span></template>
          <el-table :data="rows" style="width: 100%">
            <el-table-column prop="title" label="Plan" min-width="180" />
            <el-table-column prop="members_count" label="Members" width="120" align="center" />
            <el-table-column label="Share" width="120" align="center">
              <template #default="{ row }">{{ row.percentage }}%</template>
            </el-table-column>
            <el-table-column label="Status" width="100">
              <template #default="{ row }">
                <el-tag :type="row.status === 'active' ? 'success' : 'info'" size="small">{{ row.status }}</el-tag>
              </template>
            </el-table-column>
          </el-table>
        </el-card>
      </el-col>
    </el-row>
  </el-tab-pane>
</template>

<script setup>
import { Doughnut } from 'vue-chartjs'

defineProps({
  loading: Boolean,
  chartData: { type: Object, default: null },
  rows: { type: Array, default: () => [] },
  doughnutChartOptions: { type: Object, required: true },
})
</script>
