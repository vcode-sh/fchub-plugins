<template>
  <el-tab-pane label="Content" name="content">
    <el-card shadow="never" v-loading="loading">
      <template #header><span>Content Popularity</span></template>
      <el-table :data="rows" style="width: 100%">
        <el-table-column prop="title" label="Content" min-width="240" />
        <el-table-column prop="resource_type" label="Type" width="120">
          <template #default="{ row }">
            <el-tag size="small">{{ row.resource_type }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="member_count" label="Members" width="120" align="center" sortable />
      </el-table>
    </el-card>
  </el-tab-pane>
</template>

<script setup>
defineProps({
  loading: Boolean,
  rows: { type: Array, default: () => [] },
})
</script>
