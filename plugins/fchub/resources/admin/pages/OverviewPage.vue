<script setup>
import { computed, inject, nextTick } from 'vue'
import AttentionPanel from '../components/AttentionPanel.vue'
import ProductCard from '../components/ProductCard.vue'
import SummaryCard from '../components/SummaryCard.vue'
import { useProductsStore } from '../stores/products.js'

const store = useProductsStore()

const installed = computed(() => store.products.filter((p) => p.lifecycle !== 'not_installed'))
const available = computed(() => store.products.filter((p) => p.lifecycle === 'not_installed'))

const needsAttention = computed(() =>
  store.products.filter(
    (p) =>
      p.update === 'available' || p.compatibility !== 'compatible' || p.health === 'attention',
  ),
)

/**
 * Counted here rather than read off `summary`, because the envelope carries no
 * count for it. `health` comes from a running product's own descriptor and is
 * resolved independently of compatibility, so a site where everything is
 * current and compatible can still have a card wearing a "Needs attention"
 * badge. The hero promises to speak up when something changes; a hero that
 * cannot see this one would be promising something it does not do.
 */
const healthIssues = computed(
  () => store.products.filter((p) => p.health === 'attention').length,
)

const attentionCount = computed(
  () => store.summary.updates + store.summary.compatibility_issues + healthIssues.value,
)

const calm = computed(() => attentionCount.value === 0)

const heroTitle = computed(() => {
  if (calm.value) {
    return 'Everything is ticking along nicely.'
  }

  return attentionCount.value === 1
    ? 'One thing could use a look.'
    : 'A few things could use a look.'
})

/** "a", "a and b", "a, b and c" — no Oxford comma, this side of the Atlantic. */
function joined(parts) {
  return parts.length < 3
    ? parts.join(' and ')
    : `${parts.slice(0, -1).join(', ')} and ${parts[parts.length - 1]}`
}

const heroLead = computed(() => {
  if (calm.value) {
    return 'Nothing needs updating and nothing is being held back. FCHub will speak up when that changes.'
  }

  const parts = []

  if (store.summary.updates === 1) {
    parts.push('one update is waiting')
  } else if (store.summary.updates > 1) {
    parts.push(`${store.summary.updates} updates are waiting`)
  }

  // "cannot run here yet" would be a claim, and compatibility_issues counts
  // `unknown` alongside `blocked` — a requirement FCHub could not verify is
  // unconfirmed, not unmet. Saying a product cannot run here, three lines above
  // a card admitting FCHub has no idea, is the screen contradicting itself.
  if (store.summary.compatibility_issues === 1) {
    parts.push('one product is not ready to run here')
  } else if (store.summary.compatibility_issues > 1) {
    parts.push(`${store.summary.compatibility_issues} products are not ready to run here`)
  }

  if (healthIssues.value === 1) {
    parts.push('one product is reporting a problem')
  } else if (healthIssues.value > 1) {
    parts.push(`${healthIssues.value} products are reporting a problem`)
  }

  return `On this site, ${joined(parts)}.`
})

const updatesHint = computed(() =>
  store.summary.updates === 0
    ? 'Everything installed is on its latest release.'
    : 'Ready whenever you are.',
)

const issuesHint = computed(() =>
  store.summary.compatibility_issues === 0
    ? 'Nothing is being held back by this site.'
    : 'Requirements this site does not meet, or cannot confirm.',
)

/**
 * Whether the list below the empty state can actually be acted on. On a site
 * without FluentCart every product is blocked, every Install button is inert
 * and every card says so — at which point "a decent place to start" is pointing
 * cheerfully at a list nobody can start with.
 */
const installable = computed(() =>
  available.value.some((p) => Array.isArray(p.actions) && p.actions.length > 0),
)

const emptyLead = computed(() =>
  installable.value
    ? 'Nothing from the suite is installed yet. The list below is a decent place to start.'
    : 'Nothing from the suite is installed yet. The list below shows what exists, and what each one is waiting for.',
)

const focusMain = inject('fchub:focus-main', null)

/**
 * A successful install-and-activate moves the product out of "The rest of the
 * suite" and into "On this site" — a different v-for, in a different section —
 * so Vue unmounts the card that was pressed and its own focus watcher never
 * runs. The card handles the cases where it survives; this handles the rest.
 *
 * `hadFocus` is read *before* the await on purpose. Safari on macOS does not
 * focus a button on click, so activeElement is already <body> for a mouse user
 * — and moving focus into <main> for somebody who never had it would drag a
 * screen-reader cursor across the page unasked.
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
  <div class="fchub-overview">
    <section class="fchub-hero" :class="{ 'fchub-hero--calm': calm }">
      <h2 class="fchub-hero__title">{{ heroTitle }}</h2>
      <p class="fchub-hero__lead">{{ heroLead }}</p>
    </section>

    <div class="fchub-summary-grid">
      <SummaryCard
        tone="blue"
        label="Active products"
        :value="store.summary.active"
        :hint="`of ${store.products.length} in the suite`"
      />
      <SummaryCard
        tone="orange"
        label="Useful updates"
        :value="store.summary.updates"
        :hint="updatesHint"
      />
      <SummaryCard
        tone="pink"
        label="Compatibility issues"
        :value="store.summary.compatibility_issues"
        :hint="issuesHint"
      />
    </div>

    <AttentionPanel :products="needsAttention" />

    <section aria-labelledby="fchub-installed-heading">
      <h2 id="fchub-installed-heading" class="fchub-section-heading">On this site</h2>
      <p v-if="!installed.length" class="fchub-empty">{{ emptyLead }}</p>
      <div v-else class="fchub-card-grid">
        <ProductCard
          v-for="product in installed"
          :key="product.slug"
          :product="product"
          :pending="store.actionPending[product.slug] || null"
          @action="run"
        />
      </div>
    </section>

    <section v-if="available.length" aria-labelledby="fchub-available-heading">
      <h2 id="fchub-available-heading" class="fchub-section-heading">The rest of the suite</h2>
      <p class="fchub-section-lead">
        Not installed here. No pressure — they are listed so you know they exist.
      </p>
      <div class="fchub-card-grid">
        <ProductCard
          v-for="product in available"
          :key="product.slug"
          :product="product"
          :pending="store.actionPending[product.slug] || null"
          @action="run"
        />
      </div>
    </section>
  </div>
</template>

<style scoped>
.fchub-overview {
  display: flex;
  flex-direction: column;
  gap: 24px;
}

.fchub-hero {
  padding: 24px 26px;
  border: 1px solid var(--fchub-border-color);
  border-radius: var(--fchub-radius-stat);
  background: var(--fchub-card-bg);
}

.fchub-hero--calm {
  border-left: 3px solid var(--fchub-positive);
}

.fchub-hero__title {
  margin: 0;
  padding: 0;
  font-size: 22px;
  font-weight: 650;
  line-height: 1.3;
  color: var(--fchub-text-primary);
}

.fchub-hero__lead {
  margin: 8px 0 0;
  max-width: 62ch;
  font-size: 14px;
  line-height: 1.6;
  color: var(--fchub-text-secondary);
}

.fchub-summary-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 16px;
}

@media (max-width: 900px) {
  .fchub-summary-grid {
    grid-template-columns: repeat(1, minmax(0, 1fr));
  }
}
</style>
