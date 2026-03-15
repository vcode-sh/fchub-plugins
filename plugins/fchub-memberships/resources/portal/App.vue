<template>
  <div class="fchub-portal" :class="{ 'fchub-portal--embedded': isEmbedded }">
    <MembershipDashboard />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import MembershipDashboard from './components/MembershipDashboard.vue'

const isEmbedded = ref(false)

onMounted(() => {
  const el = document.getElementById('fchub-membership-portal')
  if (el?.closest('.fct-customer-dashboard-main-content')) {
    isEmbedded.value = true
  }
})
</script>

<style scoped>
.fchub-portal {
  max-width: 800px;
  margin: 0 auto;
  padding: 20px;
}

.fchub-portal--embedded {
  max-width: none;
  margin: 0;
  padding: 0;
}
</style>
