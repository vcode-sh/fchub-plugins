<template>
  <el-tab-pane label="Trials" name="trials" :lazy="true">
    <div v-loading="loading">
      <div class="fchub-stat-grid" style="margin-bottom: 20px">
        <div class="fchub-stat-widget">
          <div class="fchub-stat-icon blue"><el-icon :size="20"><TrendCharts /></el-icon></div>
          <div class="fchub-stat-title">Conversion Rate</div>
          <div class="fchub-stat-value">{{ data.overall_rate || 0 }}%</div>
        </div>
        <div class="fchub-stat-widget">
          <div class="fchub-stat-icon orange"><el-icon :size="20"><UserFilled /></el-icon></div>
          <div class="fchub-stat-title">Total Trials</div>
          <div class="fchub-stat-value">{{ data.total_trials || 0 }}</div>
        </div>
        <div class="fchub-stat-widget">
          <div class="fchub-stat-icon purple"><el-icon :size="20"><Plus /></el-icon></div>
          <div class="fchub-stat-title">Converted</div>
          <div class="fchub-stat-value">{{ data.total_converted || 0 }}</div>
        </div>
        <div class="fchub-stat-widget">
          <div class="fchub-stat-icon pink"><el-icon :size="20"><Remove /></el-icon></div>
          <div class="fchub-stat-title">Expired</div>
          <div class="fchub-stat-value">{{ data.total_dropped || 0 }}</div>
        </div>
      </div>
      <el-card shadow="never">
        <template #header><span>Trial Conversion by Plan</span></template>
        <el-table :data="data.by_plan || []">
          <el-table-column prop="plan_title" label="Plan" />
          <el-table-column prop="total_trials" label="Trials" width="100" align="center" />
          <el-table-column prop="converted" label="Converted" width="120" align="center" />
          <el-table-column prop="dropped" label="Expired" width="100" align="center" />
          <el-table-column label="Rate" width="100" align="center">
            <template #default="{ row }">{{ row.total_trials > 0 ? ((row.converted / row.total_trials) * 100).toFixed(1) : 0 }}%</template>
          </el-table-column>
        </el-table>
      </el-card>
    </div>
  </el-tab-pane>
</template>

<script setup>
import { Plus, Remove, TrendCharts, UserFilled } from '@element-plus/icons-vue'

defineProps({
  loading: Boolean,
  data: { type: Object, required: true },
})
</script>
