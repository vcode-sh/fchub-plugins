<template>
  <el-dialog :model-value="visible" title="Link Product" width="520px" :close-on-click-modal="false" @close="handleClose">
    <!-- Step 1: Search & Select -->
    <template v-if="!confirming">
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
            <template v-if="product.variations && product.variations.length > 0">
              <span v-for="(v, i) in product.variations" :key="i" class="variation-tag">
                {{ v.title }}: {{ formatCurrency(v.price / 100) }}
                <span v-if="v.payment_type === 'subscription'" class="variation-type">recurring</span>
              </span>
            </template>
            <span v-else>{{ product.price ? formatCurrency(product.price / 100) : 'Free' }}</span>
          </div>
        </div>
        <div v-if="!loading && results.length === 0 && query" class="product-search-empty">
          No products found.
        </div>
      </div>
    </template>

    <!-- Step 2: Confirm -->
    <template v-else>
      <div class="confirm-summary">
        <p style="margin: 0 0 16px 0">Link this product to the current plan?</p>
        <div class="confirm-product">
          <div class="confirm-product-title">{{ selectedProduct.title }}</div>
          <div class="confirm-product-meta">
            <template v-if="selectedProduct.variations && selectedProduct.variations.length > 0">
              <span v-for="(v, i) in selectedProduct.variations" :key="i" class="variation-tag">
                {{ v.title }}: {{ formatCurrency(v.price / 100) }}
              </span>
            </template>
          </div>
        </div>
        <p style="font-size: 12px; color: var(--fchub-text-secondary); margin: 16px 0 0 0">
          This will create a FluentCart integration feed for this product.
        </p>
      </div>
    </template>

    <template #footer>
      <template v-if="!confirming">
        <el-button @click="handleClose">Cancel</el-button>
        <el-button type="primary" :disabled="!selectedProduct" @click="confirming = true">
          Next
        </el-button>
      </template>
      <template v-else>
        <el-button @click="confirming = false">Back</el-button>
        <el-button type="primary" :loading="linking" @click="$emit('confirm')">
          Link Product
        </el-button>
      </template>
    </template>
  </el-dialog>
</template>

<script setup>
import { ref } from 'vue'
import { formatCurrency } from '@/utils/currency.js'

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

const emit = defineEmits(['close', 'update:query', 'search', 'select', 'confirm'])

const confirming = ref(false)

function handleClose() {
  confirming.value = false
  emit('close')
}
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
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  font-size: 12px;
  color: var(--fchub-text-secondary);
  margin-top: 4px;
}

.variation-tag {
  background: var(--el-fill-color-light, #f5f7fa);
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 12px;
}

.variation-type {
  color: var(--el-color-primary);
  margin-left: 2px;
}

.product-search-empty {
  text-align: center;
  padding: 20px 0;
  color: var(--fchub-text-secondary);
  font-size: 13px;
}

.confirm-product {
  padding: 12px 16px;
  border: 1px solid var(--el-color-primary);
  border-radius: 6px;
  background: var(--el-color-primary-light-9);
}

.confirm-product-title {
  font-weight: 600;
  font-size: 15px;
  margin-bottom: 6px;
}

.confirm-product-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
</style>
