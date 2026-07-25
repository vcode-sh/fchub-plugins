<script setup>
import { computed } from 'vue'
import { compatibilitySentence } from '../stores/products.js'

/**
 * The one section on the Overview that is allowed to be a list of problems.
 * It says what each one is in a sentence and then gets out of the way — the
 * cards below already carry the buttons, and offering them twice would just
 * be two places to lose focus.
 */
const props = defineProps({
  products: { type: Array, default: () => [] },
})

const items = computed(() =>
  props.products.flatMap((product) => {
    const sentences = []

    if (product.update === 'available') {
      sentences.push({
        key: `${product.slug}-update`,
        text: product.installed_version
          ? `${product.name} has ${product.version} ready. This site is on ${product.installed_version}.`
          : `${product.name} has ${product.version} ready.`,
      })
    }

    const incompatible = compatibilitySentence(product)

    if (incompatible) {
      sentences.push({ key: `${product.slug}-compatibility`, text: incompatible })
    }

    // The product's own words about itself, which FCHub is in no position to
    // improve on. A descriptor can set the status without writing the sentence,
    // so there is a plain fallback rather than a bullet reading "null".
    if (product.health === 'attention') {
      const message =
        typeof product.health_message === 'string' && product.health_message !== ''
          ? product.health_message
          : `${product.name || 'A product'} is reporting a problem, without saying what.`

      sentences.push({ key: `${product.slug}-health`, text: message })
    }

    return sentences
  }),
)
</script>

<template>
  <section v-if="items.length" class="fchub-attention" aria-labelledby="fchub-attention-heading">
    <h2 id="fchub-attention-heading" class="fchub-attention__heading">Worth a look</h2>
    <ul class="fchub-attention__list">
      <li v-for="item in items" :key="item.key">{{ item.text }}</li>
    </ul>
  </section>
</template>

<style scoped>
.fchub-attention {
  padding: 18px 20px;
  border: 1px solid var(--fchub-border-color);
  border-left: 3px solid var(--fchub-stat-orange);
  border-radius: var(--fchub-radius-card);
  background: var(--fchub-card-bg);
}

.fchub-attention__heading {
  margin: 0 0 10px;
  padding: 0;
  font-size: 14px;
  font-weight: 650;
  color: var(--fchub-text-primary);
}

.fchub-attention__list {
  margin: 0;
  padding-left: 18px;
  list-style: disc;
}

.fchub-attention__list li {
  margin: 0 0 6px;
  font-size: 13px;
  line-height: 1.6;
  color: var(--fchub-text-secondary);
}

.fchub-attention__list li:last-child {
  margin-bottom: 0;
}
</style>
