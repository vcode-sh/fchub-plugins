<script setup>
import { nextTick, onMounted, provide, ref } from 'vue'
import { RouterLink, RouterView } from 'vue-router'
import { useProductsStore } from './stores/products.js'

const store = useProductsStore()
const main = ref(null)

/**
 * The shell's safety net, handed to the pages.
 *
 * A ProductCard restores focus to its own button, but only if that card is
 * still mounted. A successful install-and-activate moves the product from "The
 * rest of the suite" to "On this site" — two separate v-for blocks — so Vue
 * unmounts the card, its watcher's effect scope stops, and the focused button
 * vanishes with it. The page calls this after every action; if focus has ended
 * up on <body>, <main> catches it rather than the top of wp-admin.
 */
function focusMain() {
  main.value?.focus()
}

provide('fchub:focus-main', focusMain)

const NAV = [
  { to: '/', label: 'Overview' },
  { to: '/products', label: 'Products' },
  { to: '/system', label: 'System' },
]

onMounted(() => {
  store.load()
})

/**
 * Dismissing a banner removes the button that was just pressed, so focus would
 * otherwise fall back to <body> and a keyboard user would restart from the top
 * of wp-admin. <main> takes it instead. Same for a retry that works: the
 * button it was pressed on is gone by the time it finishes.
 */
function dismiss(what) {
  if (what === 'error') {
    store.dismissError()
  } else {
    store.dismissNotice()
  }

  nextTick(focusMain)
}

async function retry() {
  if (store.loading) {
    return
  }

  await store.load()
  await nextTick()

  if (store.ready) {
    focusMain()
  }
}
</script>

<template>
  <div class="fchub-shell">
    <header class="fchub-header">
      <div class="fchub-header__mark">
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">
          <circle cx="12" cy="12" r="3.4" />
          <circle cx="12" cy="3.6" r="2" />
          <circle cx="12" cy="20.4" r="2" />
          <circle cx="3.6" cy="12" r="2" />
          <circle cx="20.4" cy="12" r="2" />
          <path
            d="M12 7v3.4M12 13.6V17M7 12h3.4M13.6 12H17"
            stroke="currentColor"
            stroke-width="1.6"
            stroke-linecap="round"
          />
        </svg>
        <!-- WordPress normally provides the page h1 inside .wrap. FCHub does
             not render a .wrap, so it owns its own, and the heading order runs
             h1 -> section h2 -> product h3 all the way down. -->
        <h1 class="fchub-header__name">FCHub</h1>
      </div>

      <nav class="fchub-nav" aria-label="FCHub sections">
        <RouterLink
          v-for="item in NAV"
          :key="item.to"
          class="fchub-nav__pill"
          exact-active-class="fchub-nav__pill--on"
          :to="item.to"
        >
          {{ item.label }}
        </RouterLink>
      </nav>
    </header>

    <!-- Both regions stay in the document so a screen reader is watching them
         before there is anything to watch. Rendering the region and its
         contents together is the classic way to announce nothing at all. -->
    <div role="alert" aria-live="assertive">
      <div v-if="store.error" class="fchub-banner fchub-banner--error">
        <p class="fchub-banner__text">{{ store.error.message }}</p>
        <button type="button" class="fchub-banner__dismiss" @click="dismiss('error')">
          Dismiss
        </button>
      </div>
    </div>

    <div role="status" aria-live="polite">
      <div
        v-if="store.notice"
        class="fchub-banner"
        :class="`fchub-banner--${store.notice.tone}`"
      >
        <p class="fchub-banner__text">{{ store.notice.message }}</p>
        <button type="button" class="fchub-banner__dismiss" @click="dismiss('notice')">
          Dismiss
        </button>
      </div>
    </div>

    <main ref="main" class="fchub-main" tabindex="-1">
      <p v-if="store.loading && !store.ready" class="fchub-empty">Reading the catalogue…</p>

      <div v-else-if="!store.ready" class="fchub-empty">
        <p class="fchub-empty__text">
          FCHub could not read the catalogue, so there is nothing to show yet.
        </p>
        <button
          type="button"
          class="fchub-button fchub-button--primary"
          :aria-disabled="store.loading || null"
          @click="retry()"
        >
          {{ store.loading ? 'Trying…' : 'Try again' }}
        </button>
      </div>

      <RouterView v-else />
    </main>
  </div>
</template>

<style scoped>
/* Deliberately not a gapped flex column. The two live regions must stay in the
   document whether or not they have anything to say, and a flex gap would
   reserve 40px of nothing above the page for the privilege. */
.fchub-shell {
  max-width: 1160px;
  margin: 0 auto;
}

.fchub-header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 20px;
}

.fchub-header__mark {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  color: var(--fchub-primary);
}

.fchub-header__mark svg {
  width: 26px;
  height: 26px;
}

.fchub-header__name {
  margin: 0;
  padding: 0;
  font-size: 18px;
  font-weight: 700;
  letter-spacing: -0.01em;
  color: var(--fchub-text-primary);
}

.fchub-nav {
  display: inline-flex;
  gap: 4px;
  padding: 4px;
  border: 1px solid var(--fchub-border-color);
  border-radius: 999px;
  background: var(--fchub-card-bg);
}

.fchub-nav__pill {
  padding: 6px 16px;
  border-radius: 999px;
  font-size: 13px;
  font-weight: 500;
  color: var(--fchub-text-secondary);
  text-decoration: none;
}

.fchub-nav__pill:hover {
  color: var(--fchub-text-primary);
  background: var(--fchub-neutral-bg);
}

.fchub-nav__pill:focus-visible {
  outline: 2px solid var(--fchub-primary);
  outline-offset: 2px;
}

/* Tinted rather than filled: white on #4D6EF5 measures 4.32:1, which is under
   AA for text this size. The approved stat-blue pair is 12.7:1 and needs no
   new colours. */
.fchub-nav__pill--on,
.fchub-nav__pill--on:hover {
  color: var(--fchub-stat-blue);
  background: var(--fchub-stat-blue-bg);
  font-weight: 600;
}

.fchub-banner {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 16px;
  padding: 14px 16px;
  border-radius: var(--fchub-radius-card);
  background: var(--fchub-positive-bg);
  color: var(--fchub-positive);
}

.fchub-banner--warning {
  background: var(--fchub-stat-orange-bg);
  color: var(--fchub-stat-orange);
}

.fchub-banner--error {
  background: var(--fchub-stat-pink-bg);
  color: var(--fchub-stat-pink);
}

.fchub-banner__text {
  margin: 0;
  font-size: 13px;
  line-height: 1.6;
}

.fchub-banner__dismiss {
  flex-shrink: 0;
  padding: 0;
  border: none;
  background: none;
  font: inherit;
  font-size: 13px;
  font-weight: 500;
  color: inherit;
  text-decoration: underline;
  cursor: pointer;
}

.fchub-banner__dismiss:focus-visible {
  outline: 2px solid currentColor;
  outline-offset: 3px;
  border-radius: 3px;
}

.fchub-main {
  min-height: 200px;
}

/* Only ever focused programmatically, after a banner is dismissed. */
.fchub-main:focus {
  outline: none;
}
</style>
