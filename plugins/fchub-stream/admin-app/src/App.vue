<template>
  <Layout
    :version="version"
    :page-title="pageTitle"
    :page-subtitle="pageSubtitle"
    :page-title-short="pageTitleShort"
  >
    <component :is="activeComponent" />
  </Layout>
</template>

<script setup>
import { computed } from 'vue'
import Layout from './components/Layout.vue'
import WelcomeDashboard from './components/WelcomeDashboard.vue'
import SettingsDashboard from './components/SettingsDashboard.vue'

const version = window.fchubStream?.version || '0.0.1'

// Get active component from WordPress
const componentId = window.fchubStream?.activeComponent || 'welcome'

// Dashboard components must be synchronous - WordPress routing requires immediate availability
const componentMap = {
  welcome: WelcomeDashboard,
  settings: SettingsDashboard,
}

// Map component IDs to page titles
const pageTitles = {
  welcome: {
    title: 'FCHub Stream',
    subtitle: 'Video streaming built out of media library trauma',
    short: 'FCHub Stream',
  },
  settings: {
    title: 'FCHub Stream Settings',
    subtitle: 'Provider credentials and upload limits',
    short: 'Settings',
  },
}

const activeComponent = computed(() => componentMap[componentId] || WelcomeDashboard)
const pageTitle = computed(() => pageTitles[componentId]?.title || 'FCHub Stream')
const pageSubtitle = computed(() => pageTitles[componentId]?.subtitle || 'Admin Dashboard')
const pageTitleShort = computed(() => pageTitles[componentId]?.short || 'FCHub Stream')
</script>
