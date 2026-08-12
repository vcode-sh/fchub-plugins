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

const state = reactive({
  loading: false,
  error: null,
  preflight: null,
  audit: null,
  copied: null,
});

const ready = computed(
  () => state.preflight?.ready === true && state.audit?.closure?.set_level !== true && state.audit?.target?.ready === true
);

const blockerCodes = computed(() => {
  const codes = [];
  const checks = state.preflight?.checks || {};
  for (const [key, check] of Object.entries(checks)) {
    const severity = String(check?.severity || '').toLowerCase();
    if (severity === 'fail' || check?.pass === false) codes.push(check?.code || key);
  }
  codes.push(...(state.audit?.closure?.set_level_codes || []));
  codes.push(...(state.audit?.closure?.reason_codes || []));
  for (const error of state.audit?.target?.errors || []) {
    codes.push(typeof error === 'string' ? error : error?.code);
  }
  if (sourceKey === 'local') codes.push('retired_local_source_namespace');

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
  {
    id: 'audit',
    value: `wp cartshift transfer audit --role=source --source-key=${sourceKey} --all-kinds --format=json`,
  },
  {
    id: 'export',
    value: `wp cartshift transfer export --role=source --source-key=${sourceKey} --all-kinds --decision-set=<absolute-path> --destination=<absolute-private-dir> --format=json`,
  },
  {
    id: 'validate-package',
    value: 'wp cartshift transfer validate-package --role=target --package=<absolute-path> --format=json',
  },
  {
    id: 'prepare',
    value: 'wp cartshift transfer prepare --role=target --package=<absolute-path> --decision-set=<absolute-path> --private-dir=<absolute-path> --execution-context=rehearsal --format=json',
  },
]);

async function refresh() {
  state.loading = true;
  state.error = null;
  try {
    const [preflight, audit] = await Promise.all([
      api('GET', 'preflight?operation=subscription'),
      api('GET', `subscriptions/audit?source=live&source_key=${encodeURIComponent(sourceKey)}`),
    ]);
    state.preflight = preflight;
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
