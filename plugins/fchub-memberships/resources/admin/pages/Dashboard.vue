<template>
  <div class="dashboard-page">
    <WorkspacePageHeader
      eyebrow="Membership workspace"
      title="Dashboard"
      description="See what needs action, check setup health, and keep member access moving."
    >
      <template v-if="!loading && !errorMessage" #actions>
        <router-link
          v-if="!hasActivePlan"
          to="/plans/new"
          class="dashboard-action dashboard-action--primary"
        >
          <el-icon><Plus /></el-icon>
          Create first plan
        </router-link>
        <template v-else>
          <router-link to="/members" class="dashboard-action dashboard-action--primary">
            <el-icon><UserFilled /></el-icon>
            Grant access
          </router-link>
          <router-link to="/content" class="dashboard-action dashboard-action--primary">
            <el-icon><Lock /></el-icon>
            Protect content
          </router-link>
        </template>
      </template>
    </WorkspacePageHeader>

    <div v-if="loading" class="dashboard-skeleton" aria-label="Loading dashboard">
      <div class="skeleton-line skeleton-line--short" />
      <div class="skeleton-grid">
        <div v-for="index in 4" :key="index" class="skeleton-card">
          <div class="skeleton-line" />
          <div class="skeleton-value" />
        </div>
      </div>
      <div class="skeleton-panels">
        <div class="skeleton-panel" />
        <div class="skeleton-panel" />
      </div>
    </div>

    <section v-else-if="errorMessage" class="dashboard-error" role="alert">
      <div class="error-icon" aria-hidden="true">
        <el-icon><WarningFilled /></el-icon>
      </div>
      <div class="error-copy">
        <h2>Dashboard unavailable</h2>
        <p>{{ errorMessage }}</p>
      </div>
      <button type="button" class="dashboard-action dashboard-action--primary" @click="loadDashboard">
        <el-icon><RefreshRight /></el-icon>
        Try again
      </button>
    </section>

    <main v-else-if="dashboardData" class="dashboard-content">
      <DashboardAttentionPanel :items="attentionItems" />

      <ProviderHealthCards compact />

      <DashboardSummaryPanel :summary="summary" />

      <div class="dashboard-row dashboard-row--readiness">
        <DashboardReadinessPanel
          :completed-steps="completedReadinessSteps"
          :steps="readinessSteps"
        />
        <DashboardTrendPanel
          :change-label="trendChangeLabel"
          :chart-colours="chartColours"
          :chart-data="membersChartData"
          :chart-options="lineChartOptions"
          :has-trend="hasTrend"
          :summary="trendSummary"
        />
      </div>

      <div class="dashboard-row dashboard-row--details">
        <DashboardDistributionPanel
          :distribution-width="distributionWidth"
          :has-active-plan="hasActivePlan"
          :plans="rankedPlans"
        />
        <DashboardActivityPanel :activity="recentActivity" />
      </div>
    </main>
  </div>
</template>

<script setup>
import {
  Lock,
  Plus,
  RefreshRight,
  UserFilled,
  WarningFilled,
} from '@element-plus/icons-vue'
import DashboardActivityPanel from '@/components/dashboard/DashboardActivityPanel.vue'
import DashboardAttentionPanel from '@/components/dashboard/DashboardAttentionPanel.vue'
import DashboardDistributionPanel from '@/components/dashboard/DashboardDistributionPanel.vue'
import ProviderHealthCards from '@/components/dashboard/ProviderHealthCards.vue'
import DashboardReadinessPanel from '@/components/dashboard/DashboardReadinessPanel.vue'
import DashboardSummaryPanel from '@/components/dashboard/DashboardSummaryPanel.vue'
import DashboardTrendPanel from '@/components/dashboard/DashboardTrendPanel.vue'
import WorkspacePageHeader from '@/components/workspace/WorkspacePageHeader.vue'
import { useDashboard } from '@/composables/dashboard/useDashboard.js'

const {
  attentionItems,
  chartColours,
  completedReadinessSteps,
  dashboardData,
  distributionWidth,
  errorMessage,
  hasActivePlan,
  hasTrend,
  lineChartOptions,
  loadDashboard,
  loading,
  membersChartData,
  rankedPlans,
  readinessSteps,
  recentActivity,
  summary,
  trendChangeLabel,
  trendSummary,
} = useDashboard()
</script>

<style src="./Dashboard.css"></style>
