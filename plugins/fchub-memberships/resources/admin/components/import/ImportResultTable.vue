<template>
  <el-table :data="rows" size="small" stripe>
    <el-table-column prop="email" label="Email" min-width="200" />
    <el-table-column prop="plan" label="Plan" min-width="140" />
    <el-table-column prop="message" :label="detailLabel" min-width="160" />
  </el-table>
</template>

<script setup>
defineProps({
  rows: { type: Array, required: true },
  detailLabel: { type: String, required: true },
})
</script>
