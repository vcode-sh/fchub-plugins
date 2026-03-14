<template>
  <el-dialog :model-value="visible" title="Link Product" width="520px" :close-on-click-modal="false" @close="$emit('close')">
    <p style="font-size: 13px; color: var(--fchub-text-secondary); margin: 0 0 16px 0">
      Search for a FluentCart product and create a membership integration feed.
    </p>
    <el-input
      :model-value="query"
      placeholder="Search products..."
      clearable
      @update:model-value="$emit('update:query', $event)"
      @input="$emit('search')"
    />
    <div v-loading="loading" class="product-search-results">
      <div
        v-for="product in results"
        :key="product.id"
        class="product-search-item"
        :class="{ selected: selectedProduct?.id === product.id }"
        @click="$emit('select', product)"
      >
        <div class="product-search-item-title">{{ product.title }}</div>
        <div class="product-search-item-meta">
          <span>{{ product.price ? `$${(product.price / 100).toFixed(2)}` : 'Free' }}</span>
          <span v-if="product.billing_period"> / {{ product.billing_period }}</span>
          <el-tag v-if="product.status" size="small" :type="product.status === 'active' ? 'success' : 'info'" style="margin-left: 8px">
            {{ product.status }}
          </el-tag>
        </div>
      </div>
      <div v-if="!loading && results.length === 0 && query" class="product-search-empty">
        No products found.
      </div>
    </div>
    <template #footer>
      <el-button @click="$emit('close')">Cancel</el-button>
      <el-button type="primary" :disabled="!selectedProduct" :loading="linking" @click="$emit('confirm')">
        Link Product
      </el-button>
    </template>
  </el-dialog>
</template>

<script setup>
defineProps({
  visible: {
    type: Boolean,
    default: false,
  },
  query: {
    type: String,
    default: '',
  },
  results: {
    type: Array,
    default: () => [],
  },
  loading: {
    type: Boolean,
    default: false,
  },
  selectedProduct: {
    type: Object,
    default: null,
  },
  linking: {
    type: Boolean,
    default: false,
  },
})

defineEmits(['close', 'update:query', 'search', 'select', 'confirm'])
</script>

<style scoped>
.product-search-results {
  margin-top: 12px;
  max-height: 300px;
  overflow-y: auto;
  min-height: 60px;
}

.product-search-item {
  padding: 10px 12px;
  border: 1px solid var(--fchub-border-color);
  border-radius: 6px;
  margin-bottom: 8px;
  cursor: pointer;
  transition: border-color 0.15s, background-color 0.15s;
}

.product-search-item:hover {
  border-color: var(--el-color-primary-light-5);
  background: var(--el-fill-color-lighter, #fafafa);
}

.product-search-item.selected {
  border-color: var(--el-color-primary);
  background: var(--el-color-primary-light-9);
}

.product-search-item-title {
  font-weight: 500;
  font-size: 14px;
}

.product-search-item-meta {
  font-size: 12px;
  color: var(--fchub-text-secondary);
  margin-top: 4px;
}

.product-search-empty {
  text-align: center;
  padding: 20px 0;
  color: var(--fchub-text-secondary);
  font-size: 13px;
}
</style>
