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
      <el-table-column label="Pricing" min-width="200">
        <template #default="{ row }">
          <template v-if="row.variations && row.variations.length > 0">
            <div v-for="(v, i) in row.variations" :key="i" class="variation-row">
              <span class="variation-name">{{ v.title }}</span>
              <span class="variation-price">{{ formatCurrency(v.price / 100) }}</span>
              <el-tag v-if="v.payment_type === 'subscription'" size="small" type="info">recurring</el-tag>
            </div>
          </template>
          <span v-else>{{ row.price ? formatCurrency(row.price / 100) : '-' }}</span>
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
          <el-popconfirm
            title="Unlink this product?"
            confirm-button-text="Unlink"
            confirm-button-type="danger"
            @confirm="$emit('unlink', row)"
          >
            <template #reference>
              <el-button type="danger" text size="small">
                <el-icon><Delete /></el-icon>
              </el-button>
            </template>
          </el-popconfirm>
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
import { formatCurrency } from '@/utils/currency.js'

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

.variation-row {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  line-height: 1.8;
}

.variation-name {
  color: var(--fchub-text-secondary);
}

.variation-price {
  font-weight: 500;
}
</style>
