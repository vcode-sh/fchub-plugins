<template>
  <div v-loading="loading">
    <div class="tab-header-row">
      <p class="tab-description">FluentCart products and integration feeds linked to this plan.</p>
      <el-button size="small" type="primary" plain @click="$emit('link')">
        <el-icon><Plus /></el-icon>
        Link Product
      </el-button>
    </div>
    <el-table v-if="products.length > 0" :data="products" stripe>
      <el-table-column prop="product_title" label="Product" min-width="200" />
      <el-table-column prop="feed_title" label="Feed" min-width="160" />
      <el-table-column prop="price" label="Price" width="120">
        <template #default="{ row }">
          {{ row.price ? `$${(row.price / 100).toFixed(2)}` : '-' }}
        </template>
      </el-table-column>
      <el-table-column label="Billing" width="140">
        <template #default="{ row }">
          {{ row.billing_period ? `Every ${row.billing_interval || 1} ${row.billing_period}(s)` : 'One-time' }}
        </template>
      </el-table-column>
      <el-table-column prop="status" label="Status" width="100">
        <template #default="{ row }">
          <el-tag :type="row.status === 'active' ? 'success' : 'info'" size="small">
            {{ row.status }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column label="" width="80" align="center">
        <template #default="{ row }">
          <el-button type="danger" text size="small" @click="$emit('unlink', row)">
            <el-icon><Delete /></el-icon>
          </el-button>
        </template>
      </el-table-column>
    </el-table>
    <el-empty v-else description="No FluentCart products linked to this plan yet." :image-size="60">
      <template #description>
        <p>No FluentCart products linked to this plan yet.</p>
        <p style="font-size: 12px; color: #909399">Click "Link Product" to create an integration feed.</p>
      </template>
    </el-empty>
  </div>
</template>

<script setup>
import { Delete, Plus } from '@element-plus/icons-vue'

defineProps({
  loading: {
    type: Boolean,
    default: false,
  },
  products: {
    type: Array,
    default: () => [],
  },
})

defineEmits(['link', 'unlink'])
</script>

<style scoped>
.tab-description {
  font-size: 13px;
  color: var(--fchub-text-secondary);
  margin: 0 0 16px 0;
}

.tab-header-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
}

.tab-header-row .tab-description {
  margin-bottom: 0;
}
</style>
