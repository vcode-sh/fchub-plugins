import { computed, ref } from 'vue'
import { ElMessage } from 'element-plus'

function resolveValue(value) {
  return typeof value === 'function' ? value() : value
}

export function usePlanProducts({
  plansApi,
  planId,
  isNew,
  messageApi = ElMessage,
  setTimer = setTimeout,
  clearTimer = clearTimeout,
  searchDelay = 300,
}) {
  const linkedProducts = ref([])
  const productsLoading = ref(false)
  const productsLoaded = ref(false)
  const linkedProductsError = ref('')
  const linkProductVisible = ref(false)
  const productSearchQuery = ref('')
  const productSearchResults = ref([])
  const productSearchLoading = ref(false)
  const selectedProduct = ref(null)
  const linkingProduct = ref(false)
  let searchTimer = null

  const linkedProductIds = computed(() => new Set(
    linkedProducts.value.map((product) => Number(product.product_id)),
  ))

  async function loadLinkedProducts() {
    if (productsLoaded.value || resolveValue(isNew)) return

    productsLoading.value = true
    linkedProductsError.value = ''
    try {
      const response = await plansApi.linkedProducts(resolveValue(planId))
      linkedProducts.value = response.data ?? response
      productsLoaded.value = true
    } catch (error) {
      linkedProductsError.value = error.message || 'Failed to load linked products.'
    } finally {
      productsLoading.value = false
    }
  }

  async function searchProducts() {
    productSearchLoading.value = true
    try {
      const response = await plansApi.searchProducts({ search: productSearchQuery.value })
      const data = response.data ?? response
      productSearchResults.value = (Array.isArray(data) ? data : []).map((product) => ({
        ...product,
        already_linked: linkedProductIds.value.has(Number(product.id)),
      }))
    } catch {
      productSearchResults.value = []
    } finally {
      productSearchLoading.value = false
    }
  }

  function showLinkProductDialog() {
    productSearchQuery.value = ''
    productSearchResults.value = []
    selectedProduct.value = null
    linkProductVisible.value = true
    void searchProducts()
  }

  function debouncedSearchProducts() {
    if (searchTimer !== null) clearTimer(searchTimer)
    searchTimer = setTimer(() => {
      searchTimer = null
      return searchProducts()
    }, searchDelay)
  }

  async function refreshLinkedProducts() {
    productsLoaded.value = false
    await loadLinkedProducts()
  }

  async function confirmLinkProduct() {
    if (!selectedProduct.value) return

    linkingProduct.value = true
    try {
      await plansApi.linkProduct(resolveValue(planId), { product_id: selectedProduct.value.id })
      messageApi.success('Product linked successfully')
      linkProductVisible.value = false
      await refreshLinkedProducts()
    } catch (error) {
      messageApi.error(error.message || 'Failed to link product')
    } finally {
      linkingProduct.value = false
    }
  }

  async function confirmUnlinkProduct(row) {
    productsLoading.value = true
    try {
      await plansApi.unlinkProduct(resolveValue(planId), row.feed_id)
      messageApi.success('Product unlinked successfully')
      await refreshLinkedProducts()
    } catch (error) {
      messageApi.error(error.message || 'Failed to unlink product')
      productsLoading.value = false
    }
  }

  return {
    linkedProducts,
    productsLoading,
    productsLoaded,
    linkedProductsError,
    linkProductVisible,
    productSearchQuery,
    productSearchResults,
    productSearchLoading,
    selectedProduct,
    linkingProduct,
    loadLinkedProducts,
    showLinkProductDialog,
    debouncedSearchProducts,
    searchProducts,
    confirmLinkProduct,
    confirmUnlinkProduct,
  }
}
