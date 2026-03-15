<template>
  <div>
    <PageHeader title="CartShift" />
    <p>Migrate your WooCommerce data to FluentCart.</p>

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
        <table class="widefat striped cartshift-checks">
          <thead>
            <tr><th>Check</th><th>Status</th><th>Details</th></tr>
          </thead>
          <tbody>
            <tr v-for="(check, key) in state.preflight.checks" :key="key">
              <td><strong>{{ check.label }}</strong></td>
              <td>
                <span v-if="check.warning" class="cartshift-warn">&#9888;</span>
                <span v-else-if="check.pass" class="cartshift-pass">&#10003;</span>
                <span v-else class="cartshift-fail">&#10007;</span>
              </td>
              <td>{{ check.message }}</td>
            </tr>
          </tbody>
        </table>

        <template v-if="state.counts">
          <h2>WooCommerce Data Counts</h2>
          <table class="widefat striped">
            <thead>
              <tr><th>Entity</th><th>Count</th></tr>
            </thead>
            <tbody>
              <tr v-for="(count, entity) in state.counts" :key="entity">
                <td>{{ capitalize(entity) }}</td>
                <td>{{ count }}</td>
              </tr>
            </tbody>
          </table>
        </template>

        <template v-if="fcDataWarning">
          <h2>Existing FluentCart Data</h2>
          <div class="notice notice-warning inline">
            <p>FluentCart already contains data. Review counts below before proceeding.</p>
          </div>
          <table class="widefat striped">
            <thead>
              <tr><th>Entity</th><th>Count</th></tr>
            </thead>
            <tbody>
              <tr v-for="(count, entity) in state.preflight.checks.fc_data.counts" :key="entity">
                <td>{{ capitalize(entity) }}</td>
                <td>{{ count }}</td>
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
            <p>Please resolve the failing checks above before migrating.</p>
          </div>
          <p><button class="button" @click="actions.runPreflight()">Re-run Checks</button></p>
        </template>
      </template>
    </template>
  </div>
</template>

<script setup>
import { inject, computed } from 'vue';
import PageHeader from './PageHeader.vue';

const { state, actions } = inject('migration');

const fcDataWarning = computed(() => {
  return state.preflight?.checks?.fc_data?.warning;
});

function capitalize(str) {
  if (!str) return '';
  return str.charAt(0).toUpperCase() + str.slice(1).replace(/_/g, ' ');
}
</script>
