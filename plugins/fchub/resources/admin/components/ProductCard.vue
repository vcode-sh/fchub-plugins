<script setup>
import { computed, nextTick, ref, useId, watch } from 'vue'
import StatusBadge from './StatusBadge.vue'
import { compatibilitySentence } from '../stores/products.js'

const props = defineProps({
  product: { type: Object, required: true },
  /** The action id currently in flight for this product, or null. */
  pending: { type: String, default: null },
})

const emit = defineEmits(['action'])

const root = ref(null)
const heading = ref(null)
const reasonId = useId()
const lastAction = ref(null)

const LABELS = {
  install: 'Install only',
  'install-and-activate': 'Install and activate',
  activate: 'Switch on',
  update: 'Update',
  deactivate: 'Switch off',
}

const RUNNING = {
  install: 'Installing…',
  'install-and-activate': 'Installing…',
  activate: 'Switching on…',
  update: 'Updating…',
  deactivate: 'Switching off…',
}

const busy = computed(() => Boolean(props.pending))

const permitted = computed(() => {
  const actions = props.product.actions

  return Array.isArray(actions) ? actions : []
})

/**
 * What this product's state is asking for, before anyone checks whether it is
 * allowed. Working it out separately from `actions` is the whole trick: a
 * blocked product still has an obvious next step, and saying "Install and
 * activate — but not until this site has PHP 8.3" is far more use than
 * quietly rendering no button and letting the customer wonder.
 */
const intended = computed(() => {
  const { lifecycle, update } = props.product

  if (lifecycle === 'not_installed') {
    return 'install-and-activate'
  }

  if (lifecycle === 'inactive') {
    return 'activate'
  }

  return update === 'available' ? 'update' : null
})

const primary = computed(() => {
  const action = intended.value

  if (action) {
    return {
      kind: 'action',
      action,
      label: action === 'update' ? `Update to ${props.product.version}` : LABELS[action],
      enabled: permitted.value.includes(action),
    }
  }

  // An active product that is already current has nothing to be done to it,
  // so the calm primary is to go and use it.
  return props.product.admin_url ? { kind: 'open', href: props.product.admin_url } : null
})

const reason = computed(() => {
  const sentence = compatibilitySentence(props.product)

  if (sentence) {
    return sentence
  }

  if (primary.value?.kind === 'action' && !primary.value.enabled) {
    return 'Your account cannot make that change on this site.'
  }

  return null
})

/**
 * One note, three quite different things to say, and pink for all three was a
 * card shouting at somebody whose only crime was not being an administrator.
 * The note now matches the badge above it: pink for a hard stop, amber for a
 * requirement FCHub could not check, and neutral for a permissions fact, which
 * is the site working exactly as configured rather than anything going wrong.
 */
const reasonClass = computed(() => {
  if (props.product.compatibility === 'blocked') {
    return 'fchub-card__note--blocked'
  }

  return props.product.compatibility === 'unknown' ? null : 'fchub-card__note--permission'
})

const versionLine = computed(() => {
  const { lifecycle, version, installed_version: installed, update } = props.product

  if (lifecycle === 'not_installed') {
    return `Latest release ${version}`
  }

  if (update === 'available' && installed) {
    return `${installed} installed · ${version} ready`
  }

  return installed ? `Version ${installed}` : 'Installed'
})

const badges = computed(() => {
  const { lifecycle, update, compatibility, health } = props.product
  const list = []

  if (lifecycle === 'active') {
    list.push({ tone: 'positive', label: 'Active' })
  } else if (lifecycle === 'inactive') {
    list.push({ tone: 'neutral', label: 'Switched off' })
  } else {
    list.push({ tone: 'neutral', label: 'Not installed' })
  }

  if (update === 'available') {
    list.push({ tone: 'info', label: 'Update ready' })
  } else if (lifecycle !== 'not_installed' && update === 'unknown') {
    list.push({ tone: 'warning', label: 'Version unclear' })
  }

  if (compatibility === 'blocked') {
    list.push({ tone: 'critical', label: 'Cannot run here' })
  } else if (compatibility === 'unknown') {
    list.push({ tone: 'warning', label: 'Cannot be checked' })
  }

  if (health === 'attention') {
    list.push({ tone: 'warning', label: 'Needs attention' })
  }

  return list
})

const healthMessage = computed(() =>
  props.product.health === 'attention' && typeof props.product.health_message === 'string'
    ? props.product.health_message
    : null,
)

function label(action) {
  return props.pending === action ? RUNNING[action] : LABELS[action]
}

function run(action, enabled) {
  if (!enabled || busy.value) {
    return
  }

  lastAction.value = action
  emit('action', { slug: props.product.slug, action })
}

/**
 * Where focus goes when an action finishes: the button that started it if it
 * survived — which is what happens when the action failed — and the card's own
 * heading when the action removed its own button.
 *
 * Deliberately not "the new primary action". After switching a product on, the
 * new primary is a link to its settings, and parking a keyboard user on a link
 * that navigates away from FCHub means the next Enter leaves the page. The
 * heading is inert, and announces which product just changed.
 */
function restoreFocus() {
  const wanted = lastAction.value

  lastAction.value = null

  const survivor = root.value?.querySelector(`[data-action="${wanted}"]`)
  const target = survivor || heading.value

  target?.focus()
}

watch(
  () => props.pending,
  (now, before) => {
    if (!before || now || !lastAction.value) {
      return
    }

    nextTick(restoreFocus)
  },
)
</script>

<template>
  <article ref="root" class="fchub-card">
    <div class="fchub-card__head">
      <div class="fchub-card__identity">
        <h3 ref="heading" class="fchub-card__title" data-card-heading tabindex="-1">
          {{ product.name }}
        </h3>
        <p class="fchub-card__version">{{ versionLine }}</p>
      </div>
      <div class="fchub-card__badges">
        <StatusBadge v-for="badge in badges" :key="badge.label" :tone="badge.tone" :label="badge.label" />
      </div>
    </div>

    <p class="fchub-card__description">{{ product.description }}</p>

    <p v-if="healthMessage" class="fchub-card__note" data-health>{{ healthMessage }}</p>

    <p v-if="reason" :id="reasonId" class="fchub-card__note" :class="reasonClass">
      {{ reason }}
    </p>

    <div class="fchub-card__actions">
      <template v-if="primary">
        <a
          v-if="primary.kind === 'open'"
          class="fchub-button fchub-button--primary"
          data-primary="true"
          data-link="settings"
          :href="primary.href"
        >
          Open settings
        </a>
        <button
          v-else
          type="button"
          class="fchub-button fchub-button--primary"
          data-primary="true"
          :data-action="primary.action"
          :aria-disabled="!primary.enabled || busy || null"
          :aria-describedby="!primary.enabled && reason ? reasonId : null"
          @click="run(primary.action, primary.enabled)"
        >
          {{ pending === primary.action ? RUNNING[primary.action] : primary.label }}
        </button>
      </template>

      <button
        v-if="permitted.includes('install')"
        type="button"
        class="fchub-button"
        data-action="install"
        :aria-disabled="busy || null"
        @click="run('install', true)"
      >
        {{ label('install') }}
      </button>

      <button
        v-if="permitted.includes('deactivate')"
        type="button"
        class="fchub-button"
        data-action="deactivate"
        :aria-disabled="busy || null"
        @click="run('deactivate', true)"
      >
        {{ label('deactivate') }}
      </button>

      <a
        v-if="product.admin_url && primary?.kind !== 'open'"
        class="fchub-link"
        data-link="settings"
        :href="product.admin_url"
      >
        Settings
      </a>

      <a
        v-if="product.docs_url"
        class="fchub-link"
        data-link="docs"
        :href="product.docs_url"
        target="_blank"
        rel="noopener noreferrer"
      >
        Documentation
      </a>

      <a
        v-if="product.release_url"
        class="fchub-link"
        data-link="release"
        :href="product.release_url"
        target="_blank"
        rel="noopener noreferrer"
      >
        Release notes
      </a>
    </div>
  </article>
</template>

<style scoped>
.fchub-card {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 20px;
  background: var(--fchub-card-bg);
  border: 1px solid var(--fchub-border-color);
  border-radius: var(--fchub-radius-card);
}

.fchub-card__head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.fchub-card__identity {
  min-width: 0;
}

.fchub-card__title {
  margin: 0;
  padding: 0;
  font-size: 15px;
  font-weight: 650;
  line-height: 1.4;
  color: var(--fchub-text-primary);
}

.fchub-card__title:focus-visible {
  outline: 2px solid var(--fchub-primary);
  outline-offset: 3px;
  border-radius: 4px;
}

.fchub-card__version {
  margin: 3px 0 0;
  font-size: 12px;
  color: var(--fchub-text-secondary);
}

.fchub-card__badges {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.fchub-card__description {
  margin: 0;
  font-size: 13px;
  line-height: 1.6;
  color: var(--fchub-text-secondary);
}

.fchub-card__note {
  margin: 0;
  padding: 10px 12px;
  border-radius: 8px;
  font-size: 13px;
  line-height: 1.5;
  color: var(--fchub-stat-orange);
  background: var(--fchub-stat-orange-bg);
}

/* The base note is amber, which covers a product's own health message and a
   requirement FCHub could not verify. These two are the exceptions. */
.fchub-card__note--blocked {
  color: var(--fchub-stat-pink);
  background: var(--fchub-stat-pink-bg);
}

.fchub-card__note--permission {
  color: var(--fchub-text-secondary);
  background: var(--fchub-neutral-bg);
}

.fchub-card__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  margin-top: 4px;
}

.fchub-link {
  margin-left: 4px;
  font-size: 13px;
  color: var(--fchub-text-secondary);
  text-decoration: none;
  border-bottom: 1px solid transparent;
}

/* Text at 13px. The resting colour is --fchub-text-secondary, which passes
   comfortably; these two states must not drop below it. A keyboard user
   reading a focused Documentation link is in the :focus-visible state by
   definition, so this is the state that matters most. */
.fchub-link:hover,
.fchub-link:focus-visible {
  color: var(--fchub-primary-strong);
  border-bottom-color: currentColor;
}

.fchub-link:focus-visible {
  outline: 2px solid var(--fchub-primary);
  outline-offset: 3px;
  border-radius: 3px;
}

@media (max-width: 600px) {
  .fchub-card__actions {
    align-items: stretch;
  }
}
</style>
