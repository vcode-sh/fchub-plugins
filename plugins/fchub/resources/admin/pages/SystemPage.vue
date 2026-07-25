<script setup>
import { computed } from 'vue'
import { compatibilitySentence, useProductsStore } from '../stores/products.js'

const store = useProductsStore()

const config = window.fchubAdmin || {}

/**
 * Rows are keyed on the requirement a product is actually waiting for, not on
 * the *kind* of requirement.
 *
 * `compatibility_reason.requirement` is `dependency` for every platform, so a
 * row keyed on that would file "Portal Extender needs fluentcommunity" under a
 * heading reading FluentCart — and the "Other requirements" escape hatch below
 * could never fire for a dependency, because `dependency` would always be
 * known. Keying the platform rows on `{requirement}:{required}` keeps the
 * FluentCart row claiming only FluentCart, and lets anything else fall through
 * as intended.
 */
const REQUIREMENTS = [
  { key: 'wp', label: 'WordPress', clear: 'Nothing is waiting on a newer WordPress.' },
  { key: 'php', label: 'PHP', clear: 'Nothing is waiting on a newer PHP.' },
  {
    key: 'dependency:fluentcart',
    label: 'FluentCart',
    clear: 'Nothing is waiting on FluentCart.',
  },
]

const KNOWN = REQUIREMENTS.map((requirement) => requirement.key)

function troubled() {
  return store.products.filter((product) => product.compatibility !== 'compatible')
}

function requirementKey(product) {
  const reason = product.compatibility_reason

  if (!reason || typeof reason !== 'object' || typeof reason.requirement !== 'string') {
    return null
  }

  return reason.requirement === 'dependency'
    ? `dependency:${reason.required}`
    : reason.requirement
}

const rows = computed(() => {
  const list = REQUIREMENTS.map((requirement) => ({
    label: requirement.label,
    clear: requirement.clear,
    sentences: troubled()
      .filter((product) => requirementKey(product) === requirement.key)
      .map((product) => ({ key: product.slug, text: compatibilitySentence(product) })),
  }))

  // A product blocked by something FCHub has no row for would otherwise vanish
  // from this page entirely, which is a poor showing for the page whose whole
  // job is explaining where the buttons went.
  const others = troubled().filter((product) => !KNOWN.includes(requirementKey(product)))

  if (others.length) {
    list.push({
      label: 'Other requirements',
      clear: '',
      sentences: others.map((product) => ({
        key: product.slug,
        text: compatibilitySentence(product),
      })),
    })
  }

  return list
})

const SOURCES = {
  remote: {
    headline: 'Straight from fchub.co',
    hint: 'This is the current catalogue.',
  },
  last_good: {
    headline: 'Using the last saved catalogue',
    hint: 'fchub.co could not be reached, so FCHub kept the last copy it trusted.',
  },
  bundled: {
    headline: 'Using the catalogue included with FCHub',
    hint: 'FCHub has not managed to reach fchub.co, so this is the copy that shipped with it.',
  },
}

const source = computed(
  () => SOURCES[store.catalogue.source] || { headline: 'Not read yet', hint: '' },
)

/**
 * What this site runs, straight from the server — and the versions every
 * compatibility decision on this page was made against, rather than a second
 * opinion the browser worked out for itself.
 */
const siteRows = computed(() => [
  { label: 'WordPress', value: store.site.wp || 'FCHub could not read it' },
  { label: 'PHP', value: store.site.php || 'FCHub could not read it' },
  {
    label: 'FluentCart',
    value: store.site.fluentcart
      ? `Version ${store.site.fluentcart}`
      : 'Not running on this site',
  },
])

/**
 * Said out loud only when something is genuinely withheld. Listing everything
 * an administrator is allowed to do would be a permissions audit, not a calm
 * summary — but "why is there no Install button" deserves an answer.
 */
const withheld = computed(() => {
  const missing = []

  if (!store.capabilities.install) {
    missing.push('install products')
  }

  if (!store.capabilities.update) {
    missing.push('update them')
  }

  if (!store.capabilities.activate) {
    missing.push('switch them on or off')
  }

  if (!missing.length) {
    return null
  }

  const list =
    missing.length === 1
      ? missing[0]
      : `${missing.slice(0, -1).join(', ')} or ${missing[missing.length - 1]}`

  return `Your account cannot ${list} on this site.`
})

const lastChecked = computed(() => {
  const raw = store.catalogue.last_refresh

  if (!raw) {
    return null
  }

  const when = new Date(raw)

  if (Number.isNaN(when.getTime())) {
    return raw
  }

  try {
    return new Intl.DateTimeFormat(String(config.locale || 'en_US').replace(/_/g, '-'), {
      dateStyle: 'medium',
      timeStyle: 'short',
    }).format(when)
  } catch {
    return when.toISOString()
  }
})

const updatesUrl = computed(() => `${String(config.admin_url || '/wp-admin/')}update-core.php`)

function refresh() {
  if (store.refreshing) {
    return
  }

  store.refreshCatalogue()
}
</script>

<template>
  <div class="fchub-system">
    <div>
      <h2 class="fchub-section-heading fchub-section-heading--flush">System</h2>
      <p class="fchub-section-lead">
        What this site can run, where the catalogue came from, and which FCHub is doing the asking.
      </p>
    </div>

    <section class="fchub-panel" aria-labelledby="fchub-site-heading">
      <h3 id="fchub-site-heading" class="fchub-panel__heading">This site</h3>
      <dl class="fchub-facts">
        <div v-for="row in siteRows" :key="row.label" class="fchub-facts__row">
          <dt>{{ row.label }}</dt>
          <dd>
            <p class="fchub-facts__line">{{ row.value }}</p>
          </dd>
        </div>
        <div v-if="withheld" class="fchub-facts__row">
          <dt>Your account</dt>
          <dd>
            <p class="fchub-facts__line">{{ withheld }}</p>
          </dd>
        </div>
      </dl>
    </section>

    <section class="fchub-panel" aria-labelledby="fchub-compatibility-heading">
      <h3 id="fchub-compatibility-heading" class="fchub-panel__heading">Compatibility</h3>
      <dl class="fchub-facts">
        <div v-for="row in rows" :key="row.label" class="fchub-facts__row">
          <dt>{{ row.label }}</dt>
          <dd>
            <template v-if="row.sentences.length">
              <p v-for="sentence in row.sentences" :key="sentence.key" class="fchub-facts__line">
                {{ sentence.text }}
              </p>
            </template>
            <p v-else class="fchub-facts__line fchub-facts__line--calm">{{ row.clear }}</p>
          </dd>
        </div>
      </dl>
    </section>

    <section class="fchub-panel" aria-labelledby="fchub-catalogue-heading">
      <h3 id="fchub-catalogue-heading" class="fchub-panel__heading">Catalogue</h3>
      <dl class="fchub-facts">
        <div class="fchub-facts__row">
          <dt>Source</dt>
          <dd>
            <p class="fchub-facts__line">{{ source.headline }}</p>
            <p v-if="source.hint" class="fchub-facts__line fchub-facts__line--quiet">
              {{ source.hint }}
            </p>
          </dd>
        </div>
        <div class="fchub-facts__row">
          <dt>Last checked</dt>
          <dd>
            <p class="fchub-facts__line">
              {{ lastChecked || 'FCHub has not managed a successful check yet.' }}
            </p>
          </dd>
        </div>
      </dl>
      <div class="fchub-panel__actions">
        <button
          type="button"
          class="fchub-button fchub-button--primary"
          :aria-disabled="store.refreshing || null"
          @click="refresh"
        >
          {{ store.refreshing ? 'Checking…' : 'Check for updates' }}
        </button>
      </div>
    </section>

    <section class="fchub-panel" aria-labelledby="fchub-hub-heading">
      <h3 id="fchub-hub-heading" class="fchub-panel__heading">FCHub</h3>
      <dl class="fchub-facts">
        <div class="fchub-facts__row">
          <dt>Version</dt>
          <dd>
            <p class="fchub-facts__line">{{ config.version || 'Unknown' }}</p>
          </dd>
        </div>
        <div class="fchub-facts__row">
          <dt>Updates</dt>
          <dd>
            <p class="fchub-facts__line">
              FCHub updates arrive through WordPress like any other plugin.
            </p>
            <p class="fchub-facts__line fchub-facts__line--quiet">
              <a class="fchub-link" :href="updatesUrl">Open the WordPress Updates screen</a>
            </p>
          </dd>
        </div>
      </dl>
    </section>
  </div>
</template>

<style scoped>
.fchub-system {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.fchub-panel {
  padding: 20px 22px;
  border: 1px solid var(--fchub-border-color);
  border-radius: var(--fchub-radius-card);
  background: var(--fchub-card-bg);
}

.fchub-panel__heading {
  margin: 0 0 12px;
  padding: 0;
  font-size: 14px;
  font-weight: 650;
  color: var(--fchub-text-primary);
}

.fchub-panel__actions {
  margin-top: 16px;
}

.fchub-facts {
  margin: 0;
}

.fchub-facts__row {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 24px;
  padding: 12px 0;
  border-top: 1px solid var(--fchub-border-color);
}

.fchub-facts__row:first-child {
  padding-top: 0;
  border-top: none;
}

.fchub-facts__row:last-child {
  padding-bottom: 0;
}

.fchub-facts dt {
  flex: 0 0 160px;
  font-size: 13px;
  font-weight: 500;
  color: var(--fchub-text-primary);
}

.fchub-facts dd {
  flex: 1;
  min-width: 220px;
  margin: 0;
}

.fchub-facts__line {
  margin: 0 0 4px;
  font-size: 13px;
  line-height: 1.6;
  color: var(--fchub-text-primary);
}

.fchub-facts__line:last-child {
  margin-bottom: 0;
}

.fchub-facts__line--calm,
.fchub-facts__line--quiet {
  color: var(--fchub-text-secondary);
}

/* Text, so --fchub-primary-strong: this renders at 13px inside
   .fchub-facts__line, where 4.5:1 is the bar and #4D6EF5 gives 4.32:1. */
.fchub-link {
  color: var(--fchub-primary-strong);
  text-decoration: none;
  border-bottom: 1px solid transparent;
}

.fchub-link:hover,
.fchub-link:focus-visible {
  border-bottom-color: currentColor;
}

.fchub-link:focus-visible {
  outline: 2px solid var(--fchub-primary);
  outline-offset: 3px;
  border-radius: 3px;
}

@media (max-width: 700px) {
  .fchub-facts dt {
    flex: 0 0 100%;
  }
}
</style>
