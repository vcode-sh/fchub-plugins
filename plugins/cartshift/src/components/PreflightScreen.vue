<template>
  <div>
    <PageHeader title="CartShift" />
    <p>Migrate your WooCommerce data to FluentCart.</p>

    <div v-if="state.resetMessage" class="notice notice-success inline">
      <p>{{ state.resetMessage }}</p>
    </div>

    <!-- A finished run the admin has not looked at yet. -->
    <div v-if="state.previousRun" class="notice notice-info inline">
      <p>
        A previous migration finished with status
        <strong>{{ capitalize(state.previousRun.status) }}</strong>
        <template v-if="state.previousRun.completed_at"> on {{ state.previousRun.completed_at }}</template>.
        Its results and log are still available.
      </p>
      <p>
        <button class="button button-primary" @click="actions.viewPreviousRun()">View Results</button>
        <button class="button" @click="actions.dismissPreviousRun()">Dismiss</button>
      </p>
    </div>

    <div v-if="state.loading">
      <p><span class="spinner is-active" style="float:none;margin:0 10px 0 0;"></span> Running preflight checks...</p>
    </div>

    <template v-else>
      <div v-if="state.error" class="notice notice-error">
        <p>{{ state.error }}</p>
      </div>

      <template v-if="!state.preflight">
        <button class="button button-primary" @click="actions.runPreflight()">Run Preflight Checks</button>
      </template>

      <template v-else>
        <h2>Preflight Checks</h2>

        <!-- Blocking failures. These get the whole message, not a table cell. -->
        <section v-if="blockers.length" class="cartshift-check-group cartshift-check-group-fail">
          <h3 class="cartshift-check-group-title">
            <span class="cartshift-check-glyph cartshift-fail" aria-hidden="true">&#10007;</span>
            {{ blockers.length === 1 ? '1 blocking problem' : blockers.length + ' blocking problems' }}
          </h3>
          <p class="cartshift-check-group-lede">
            Migrating in this state produces wrong data quietly, which is the worst kind.
            Fix these first.
          </p>

          <div v-for="check in blockers" :key="check.key" class="cartshift-check-card cartshift-check-card-fail">
            <h4 class="cartshift-check-card-title">
              <span class="cartshift-check-severity cartshift-check-severity-fail">Blocking</span>
              {{ check.label }}
            </h4>
            <p class="cartshift-check-card-message">{{ check.message }}</p>
          </div>
        </section>

        <!-- Advisories. Worth reading, not worth stopping for. -->
        <section v-if="advisories.length" class="cartshift-check-group cartshift-check-group-warn">
          <h3 class="cartshift-check-group-title">
            <span class="cartshift-check-glyph cartshift-warn" aria-hidden="true">&#9888;</span>
            {{ advisories.length === 1 ? '1 advisory' : advisories.length + ' advisories' }}
          </h3>
          <p class="cartshift-check-group-lede">
            None of these stop the migration. All of them are worth reading before you start it.
          </p>

          <div v-for="check in advisories" :key="check.key" class="cartshift-check-card cartshift-check-card-warn">
            <h4 class="cartshift-check-card-title">
              <span class="cartshift-check-severity cartshift-check-severity-warn">Warning</span>
              {{ check.label }}
            </h4>
            <p class="cartshift-check-card-message">{{ check.message }}</p>
          </div>
        </section>

        <!-- Everything that passed, folded away. -->
        <details v-if="passed.length" class="cartshift-check-passed">
          <summary>
            <span class="cartshift-check-glyph cartshift-pass" aria-hidden="true">&#10003;</span>
            {{ passed.length }} {{ passed.length === 1 ? 'check passed' : 'checks passed' }}
          </summary>
          <table class="widefat striped cartshift-checks">
            <thead>
              <tr><th>Check</th><th>Status</th><th>Details</th></tr>
            </thead>
            <tbody>
              <tr v-for="check in passed" :key="check.key">
                <td><strong>{{ check.label }}</strong></td>
                <td>
                  <span class="cartshift-check-severity cartshift-check-severity-pass">
                    <span aria-hidden="true">&#10003;</span> Passed
                  </span>
                </td>
                <td>{{ check.message }}</td>
              </tr>
            </tbody>
          </table>
        </details>

        <template v-if="state.counts">
          <h2>WooCommerce Data Counts</h2>
          <table class="widefat striped cartshift-table-counts">
            <thead>
              <tr><th>Entity</th><th>Count</th></tr>
            </thead>
            <tbody>
              <tr v-for="(count, entity) in state.counts" :key="entity">
                <td>{{ capitalize(entity) }}</td>
                <td>{{ formatNumber(count) }}</td>
              </tr>
            </tbody>
          </table>
        </template>

        <template v-if="fcDataCounts">
          <h2>Existing FluentCart Data</h2>
          <p class="description">
            FluentCart is not empty. Migrating on top of this will not wipe it, but it will
            add to it &mdash; check the numbers below are what you expect.
          </p>
          <table class="widefat striped cartshift-table-counts">
            <thead>
              <tr><th>Entity</th><th>Count</th></tr>
            </thead>
            <tbody>
              <tr v-for="(count, entity) in fcDataCounts" :key="entity">
                <td>{{ capitalize(entity) }}</td>
                <td>{{ formatNumber(count) }}</td>
              </tr>
            </tbody>
          </table>
        </template>

        <template v-if="state.preflight.ready">
          <p style="margin-top:20px;">
            <button class="button button-primary button-hero" @click="actions.goToScreen('select')">
              Proceed to Migration
            </button>
            <button class="button" @click="actions.runPreflight()">Re-run Checks</button>
          </p>
        </template>
        <template v-else>
          <div class="notice notice-error inline">
            <p>
              Migration is blocked until the {{ blockers.length === 1 ? 'problem' : 'problems' }}
              above {{ blockers.length === 1 ? 'is' : 'are' }} resolved. Fix, then re-run the checks.
            </p>
          </div>
          <p><button class="button button-primary" @click="actions.runPreflight()">Re-run Checks</button></p>
        </template>
      </template>
    </template>
  </div>
</template>

<script setup>
import { inject, computed } from 'vue';
import PageHeader from './PageHeader.vue';

const { state, actions } = inject('migration');

/**
 * Read the severity a check reports. Older payloads only carried the `pass` and
 * `warning` booleans, so fall back to those rather than mislabelling everything
 * as a pass.
 */
function severityOf(check) {
  const raw = typeof check?.severity === 'string' ? check.severity.toLowerCase() : '';

  if (raw === 'fail' || raw === 'warn' || raw === 'pass') {
    return raw;
  }

  if (check?.warning) return 'warn';
  if (check?.pass === false) return 'fail';

  return 'pass';
}

const allChecks = computed(() => {
  const checks = state.preflight?.checks;
  if (!checks || typeof checks !== 'object') return [];

  return Object.keys(checks).map((key) => {
    const check = checks[key] || {};

    return {
      key,
      label: check.label || capitalize(key),
      message: check.message || '',
      severity: severityOf(check),
    };
  });
});

const blockers = computed(() => allChecks.value.filter((c) => c.severity === 'fail'));
const advisories = computed(() => allChecks.value.filter((c) => c.severity === 'warn'));
const passed = computed(() => allChecks.value.filter((c) => c.severity === 'pass'));

const fcDataCounts = computed(() => {
  const fcData = state.preflight?.checks?.fc_data;
  if (!fcData) return null;
  if (severityOf(fcData) === 'pass') return null;

  const counts = fcData.counts;
  return counts && typeof counts === 'object' ? counts : null;
});

function capitalize(str) {
  if (!str) return '';
  return str.charAt(0).toUpperCase() + str.slice(1).replace(/_/g, ' ');
}

function formatNumber(value) {
  const n = Number(value);
  if (!Number.isFinite(n)) return '0';
  return n.toLocaleString();
}
</script>
