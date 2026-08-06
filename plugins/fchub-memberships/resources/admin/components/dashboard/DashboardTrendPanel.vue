<template>
  <section
    class="panel trend-panel"
    aria-labelledby="trend-heading"
    :style="{ '--dashboard-chart-primary': chartColours.primary }"
  >
    <div class="section-heading">
      <div>
        <p class="section-eyebrow">Last 30 days</p>
        <h2 id="trend-heading">Member trend</h2>
      </div>
      <span v-if="hasTrend" class="trend-change">{{ changeLabel }}</span>
    </div>
    <div v-if="hasTrend" class="trend-chart">
      <div class="trend-plot">
        <Line
          :data="chartData"
          :options="chartOptions"
          role="img"
          aria-label="Member count over the last 30 days"
          aria-describedby="member-trend-summary"
        />
      </div>
      <p id="member-trend-summary" class="trend-summary">{{ summary }}</p>
    </div>
    <div v-else class="compact-empty">
      <el-icon aria-hidden="true"><DataLine /></el-icon>
      <div>
        <strong>Not enough history yet</strong>
        <span>Trend appears after at least two daily snapshots.</span>
      </div>
      <router-link to="/members" class="text-action">
        View members
        <el-icon><ArrowRight /></el-icon>
      </router-link>
    </div>
  </section>
</template>

<script setup>
import { ArrowRight, DataLine } from '@element-plus/icons-vue'
import { Line } from 'vue-chartjs'
import {
  CategoryScale,
  Chart as ChartJS,
  Filler,
  LinearScale,
  LineElement,
  PointElement,
  Tooltip,
} from 'chart.js'

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Tooltip, Filler)

defineProps({
  changeLabel: {
    type: String,
    required: true,
  },
  chartColours: {
    type: Object,
    required: true,
  },
  chartData: {
    type: Object,
    required: true,
  },
  chartOptions: {
    type: Object,
    required: true,
  },
  hasTrend: {
    type: Boolean,
    required: true,
  },
  summary: {
    type: String,
    required: true,
  },
})
</script>
