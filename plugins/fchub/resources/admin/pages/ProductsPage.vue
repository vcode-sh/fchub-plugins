<script setup>
import { computed, inject, nextTick, ref } from 'vue'
import ProductCard from '../components/ProductCard.vue'
import { useProductsStore } from '../stores/products.js'

const store = useProductsStore()

const filter = ref('all')

const FILTERS = [
  { id: 'all', label: 'All' },
  { id: 'installed', label: 'Installed' },
  { id: 'updates', label: 'Updates' },
]

const MATCHERS = {
  all: () => true,
  installed: (product) => product.lifecycle !== 'not_installed',
  updates: (product) => product.update === 'available',
}

function count(id) {
  return store.products.filter(MATCHERS[id]).length
}

const shown = computed(() => store.products.filter(MATCHERS[filter.value]))

/**
 * Pressing a filter flips `aria-pressed` and silently swaps the grid, so a
 * screen-reader user hears "Updates 1, pressed" and nothing at all about what
 * that did. This is the one place in the interface where a state change
 * produced no announcement; the count is the announcement.
 *
 * Not `role="status"` — the shell already owns the one polite region that
 * carries banners, and a second would make "the polite region" ambiguous.
 */
const announcement = computed(() => {
  const total = store.products.length
  const noun = total === 1 ? 'product' : 'products'

  if (!shown.value.length) {
    return 'No products match this filter.'
  }

  return shown.value.length === total
    ? `Showing all ${total} ${noun}.`
    : `Showing ${shown.value.length} of ${total} ${noun}.`
})

const emptyMessage = computed(() => {
  if (filter.value === 'updates') {
    return 'No updates waiting. Everything installed is on its latest release.'
  }

  if (filter.value === 'installed') {
    return 'Nothing from the suite is installed here yet.'
  }

  return 'The catalogue came back empty, which should not happen. The System page may have more to say.'
})

const focusMain = inject('fchub:focus-main', null)

/**
 * With the "Updates" filter on, a successful update removes the card from
 * `shown` entirely — so the button that was pressed is unmounted and the card's
 * own focus watcher never runs. Same safety net as the Overview, `hadFocus`
 * guard and all: focus is only ever restored to somebody who had it.
 */
async function run({ slug, action }) {
  const hadFocus = document.activeElement !== document.body

  await store.runAction(slug, action)
  await nextTick()

  if (hadFocus && document.activeElement === document.body) {
    focusMain?.()
  }
}
</script>

<template>
  <div class="fchub-products">
    <div class="fchub-products__head">
      <div>
        <h2 class="fchub-section-heading fchub-section-heading--flush">Products</h2>
        <p class="fchub-section-lead">
          Every stable product in the suite, and what each one is doing on this site.
        </p>
      </div>

      <div class="fchub-filters" role="group" aria-label="Filter products">
        <button
          v-for="option in FILTERS"
          :key="option.id"
          type="button"
          class="fchub-filter"
          :class="{ 'fchub-filter--on': filter === option.id }"
          :aria-pressed="filter === option.id"
          @click="filter = option.id"
        >
          {{ option.label }}
          <span class="fchub-filter__count">{{ count(option.id) }}</span>
        </button>
      </div>
    </div>

    <p class="fchub-sr-only" aria-live="polite" data-filter-result>{{ announcement }}</p>

    <p v-if="!shown.length" class="fchub-empty">{{ emptyMessage }}</p>

    <div v-else class="fchub-card-grid">
      <ProductCard
        v-for="product in shown"
        :key="product.slug"
        :product="product"
        :pending="store.actionPending[product.slug] || null"
        @action="run"
      />
    </div>
  </div>
</template>

<style scoped>
.fchub-products {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.fchub-products__head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  justify-content: space-between;
  gap: 16px;
}

.fchub-filters {
  display: inline-flex;
  gap: 4px;
  padding: 4px;
  border: 1px solid var(--fchub-border-color);
  border-radius: 999px;
  background: var(--fchub-card-bg);
}

.fchub-filter {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 14px;
  border: none;
  border-radius: 999px;
  background: transparent;
  font: inherit;
  font-size: 13px;
  font-weight: 500;
  color: var(--fchub-text-secondary);
  cursor: pointer;
}

.fchub-filter:hover {
  color: var(--fchub-text-primary);
  background: var(--fchub-neutral-bg);
}

.fchub-filter:focus-visible {
  outline: 2px solid var(--fchub-primary);
  outline-offset: 2px;
}

/* Tinted rather than filled, for the same contrast reason as the header nav. */
.fchub-filter--on,
.fchub-filter--on:hover {
  color: var(--fchub-stat-blue);
  background: var(--fchub-stat-blue-bg);
  font-weight: 600;
}

.fchub-filter__count {
  font-size: 11px;
  font-variant-numeric: tabular-nums;
  opacity: 0.75;
}
</style>
