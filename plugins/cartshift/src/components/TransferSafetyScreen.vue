<template>
  <div class="cartshift-transfer-safety">
    <PageHeader title="CartShift transfer safety" />
    <p class="description">
      This screen reads evidence only. Transfers run through the v2 WP-CLI contract; the retired browser
      writers cannot stage, retry, reset, finalise, or roll anything back.
    </p>

    <p>
      <button class="button" :disabled="state.loading" @click="refresh">
        {{ state.loading ? 'Reading evidence…' : 'Refresh evidence' }}
      </button>
    </p>

    <div v-if="state.error" class="notice notice-error inline"><p>{{ state.error }}</p></div>

    <template v-if="state.preflight || state.audit">
      <section class="cartshift-safety-section">
        <h2>Compatibility and audit status</h2>
        <p>
          <strong>{{ ready ? 'Read-only checks are ready.' : 'Transfer is blocked.' }}</strong>
          A green screen is evidence for preparation, never permission to write.
        </p>
        <ul v-if="blockerCodes.length" class="cartshift-blocker-list" aria-label="Blocking reason codes">
          <li v-for="code in blockerCodes" :key="code"><code>{{ code }}</code></li>
        </ul>
        <p v-else><code>no_reported_blockers</code></p>
        <p v-if="!subscriptionsAvailable" class="description" data-test="wcs-unavailable">
          WooCommerce Subscriptions is not active. Subscription migration will be skipped. Products,
          variations, customers, orders and coupons are unaffected.
        </p>
      </section>

      <section class="cartshift-safety-section">
        <h2>Bound fingerprints</h2>
        <dl class="cartshift-fingerprints">
          <template v-for="item in fingerprints" :key="item.label">
            <dt>{{ item.label }}</dt>
            <dd><code>{{ item.value || 'not_reported' }}</code></dd>
          </template>
        </dl>
      </section>

      <section class="cartshift-safety-section">
        <h2>Next commands</h2>
        <p class="description">
          Copy these exact command shapes into a terminal. Replace absolute-path placeholders before running
          export or prepare; neither this screen nor a browser request performs them for you.
        </p>
        <div
          v-for="command in commands"
          :key="command.id"
          class="cartshift-command"
          :data-command="command.id"
        >
          <code>{{ command.value }}</code>
          <button class="button button-small" @click="copy(command)">
            {{ state.copied === command.id ? 'Copied' : 'Copy' }}
          </button>
        </div>
        <p v-if="!boundSourceKey" class="description" data-test="source-key-unset">
          <code>audit</code> and <code>export</code> are not listed: this site has never been given a
          transfer source key, and both refuse <code>--source-key=local</code>
          (<code>retired_local_source_namespace</code>). Name the site through the
          <code>cartshift/transfer/source_key</code> filter, then refresh. The target-role commands
          above need no source key and run as they are.
        </p>
      </section>
    </template>
  </div>
</template>

<script setup>
import { computed, inject, onMounted, reactive } from 'vue';
import { useApi } from '@/composables/useApi.js';
import PageHeader from './PageHeader.vue';

const { api } = useApi();
const config = inject('config', {});
const sourceKey = String(config.sourceKey || 'local');

/**
 * The name this site answers to in a transfer, or null when it has none.
 *
 * `local` is not a name, it is the absence of one, and
 * `SourceIdentity::assertValidSourceKey()` refuses it by that literal — so every
 * command carrying `--source-key=local` errors the instant it is pasted. The
 * screen used to print those commands beside a blocker code saying so, which is
 * an action offered and withdrawn in the same paragraph.
 */
const boundSourceKey = sourceKey === 'local' ? null : sourceKey;

const state = reactive({
  loading: false,
  error: null,
  preflight: null,
  subscriptionPreflight: null,
  audit: null,
  copied: null,
});

/**
 * WooCommerce Subscriptions is optional, and this is the only thing that says so.
 *
 * Everything subscription-shaped on this screen hangs off it: the second
 * preflight, the live audit, the readiness verdict and the status line. A shop
 * without WCS migrates products, variations, customers, orders and coupons
 * exactly as before — an absent add-on is a capability that is not here, never
 * a dataset that is empty and never a reason to call the transfer blocked.
 */
const subscriptionsAvailable = computed(
  () => state.preflight?.checks?.wc_subscriptions?.active === true
);

const ready = computed(() => {
  if (state.preflight?.ready !== true) return false;
  if (!subscriptionsAvailable.value) return true;

  return state.subscriptionPreflight?.ready === true
    && state.audit?.closure?.set_level !== true
    && state.audit?.target?.ready === true;
});

const blockerCodes = computed(() => {
  const codes = [];
  for (const report of [state.preflight, state.subscriptionPreflight]) {
    for (const [key, check] of Object.entries(report?.checks || {})) {
      const severity = String(check?.severity || '').toLowerCase();
      if (severity === 'fail' || check?.pass === false) codes.push(check?.code || key);
    }
  }
  codes.push(...(state.audit?.closure?.set_level_codes || []));
  codes.push(...(state.audit?.closure?.reason_codes || []));
  for (const error of state.audit?.target?.errors || []) {
    codes.push(typeof error === 'string' ? error : error?.code);
  }
  return [...new Set(codes.filter(Boolean).map(String))].sort();
});

const fingerprints = computed(() => [
  { label: 'Source evidence', value: state.audit?.source?.source_fingerprint },
  { label: 'Selection', value: state.audit?.source?.selection_fingerprint },
  { label: 'Target settings', value: state.audit?.target?.approval_fingerprint },
]);

const commands = computed(() => [
  {
    id: 'compatibility-source',
    value: 'wp cartshift transfer compatibility --role=source --format=json',
  },
  {
    id: 'compatibility-target',
    value: 'wp cartshift transfer compatibility --role=target --format=json',
  },
  // The two that carry a source key, and only where there is one to carry.
  // Nothing is lost by their absence: they return the moment the site is named,
  // and the note below says which filter names it.
  ...(boundSourceKey === null ? [] : [
    {
      id: 'audit',
      value: `wp cartshift transfer audit --role=source --source-key=${boundSourceKey} --all-kinds --format=json`,
    },
    {
      id: 'export',
      value: `wp cartshift transfer export --role=source --source-key=${boundSourceKey} --all-kinds --decision-set=<absolute-path> --destination=<absolute-private-dir> --format=json`,
    },
  ]),
  {
    id: 'validate-package',
    value: 'wp cartshift transfer validate-package --role=target --package=<absolute-path> --format=json',
  },
  {
    id: 'prepare',
    value: 'wp cartshift transfer prepare --role=target --package=<absolute-path> --decision-set=<absolute-path> --private-dir=<absolute-path> --execution-context=rehearsal --format=json',
  },
]);

/**
 * The general preflight for every shop, the subscription evidence only if there
 * is any.
 *
 * The operation names are spelled out rather than composed, because they are a
 * backend vocabulary — `PreflightCheck::OPERATIONS` — and the endpoint refuses
 * anything else with a 400 rather than quietly picking the permissive branch.
 * This screen used to ask for `subscription`, which is not one of them, so it
 * opened on that refusal and reported a perfectly healthy shop as broken.
 * `PreflightControllerTest` reads these two literals back out of the source and
 * puts them through the real controller, so an invented third cannot ship.
 *
 * The live audit is asked for second and only when WCS is present. Asking a
 * runtime without `wcs_get_subscriptions()` is answered with a 409 by design:
 * an empty subscription dataset and a runtime that cannot read one look
 * identical on screen and could not be more different.
 */
async function refresh() {
  state.loading = true;
  state.error = null;
  try {
    const preflight = await api('GET', 'preflight?operation=migration');

    state.preflight = preflight;
    state.subscriptionPreflight = null;
    state.audit = null;

    if (preflight?.checks?.wc_subscriptions?.active !== true) return;

    const [subscriptionPreflight, audit] = await Promise.all([
      api('GET', 'preflight?operation=subscription_dataset'),
      api('GET', `subscriptions/audit?source=live&source_key=${encodeURIComponent(sourceKey)}`),
    ]);
    state.subscriptionPreflight = subscriptionPreflight;
    state.audit = audit;
  } catch (error) {
    state.error = error?.message || 'Could not read transfer evidence.';
  } finally {
    state.loading = false;
  }
}

async function copy(command) {
  await navigator.clipboard.writeText(command.value);
  state.copied = command.id;
}

onMounted(refresh);
</script>

<style scoped>
.cartshift-transfer-safety { max-width: 1040px; }
.cartshift-safety-section { margin-top: 28px; }
.cartshift-blocker-list { display: flex; flex-wrap: wrap; gap: 8px; padding: 0; list-style: none; }
.cartshift-fingerprints { display: grid; grid-template-columns: minmax(140px, 190px) minmax(0, 1fr); gap: 8px 16px; }
.cartshift-fingerprints dt { font-weight: 600; }
.cartshift-fingerprints dd { margin: 0; overflow-wrap: anywhere; }
.cartshift-command { display: flex; align-items: flex-start; gap: 12px; margin: 10px 0; }
.cartshift-command code { flex: 1; padding: 8px 10px; background: #f0f0f1; overflow-wrap: anywhere; }
</style>
