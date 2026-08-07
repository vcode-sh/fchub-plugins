<template>
  <div class="cartshift-receipt">
    <!-- The selection is too big to run. Plain words, no jokes, nothing else on the panel. -->
    <div v-if="preview && preview.too_large" class="notice notice-error inline" role="alert">
      <p>
        <strong>This selection is too large to migrate in one run.</strong> Nothing has been
        migrated. Narrow what you have picked, or migrate everything from a date instead.
      </p>
    </div>

    <template v-else>
      <h2>What will come across</h2>

      <!-- The selection changes on a debounce as the owner edits it, so the counts
           and consequences below are announced politely, not interrupted mid-typing. -->
      <div aria-live="polite">
        <table class="widefat striped">
          <thead>
            <tr><th>Entity</th><th>Count</th></tr>
          </thead>
          <tbody>
            <tr v-for="row in countRows" :key="row.key">
              <td><strong>{{ row.label }}</strong></td>
              <td>{{ row.value.toLocaleString() }}</td>
            </tr>
          </tbody>
        </table>

        <p v-if="closureNote" class="cartshift-receipt-closure">{{ closureNote }}</p>
      </div>

      <!-- Consequences: only when there is a preview, and only the non-zero ones. -->
      <template v-if="preview">
        <h2>What will not</h2>

        <div aria-live="polite">
          <div v-if="!visibleConsequences.length" class="notice notice-success inline">
            <p><strong>Nothing left behind.</strong></p>
          </div>

          <ul v-else class="cartshift-receipt-list">
            <li v-for="row in visibleConsequences" :key="row.code" class="cartshift-receipt-item">
              <div :class="['cartshift-log-breakdown-row', 'cartshift-receipt-row', severityClass(row.severity)]">
                <span class="cartshift-log-breakdown-count">{{ row.countLabel }}</span>
                <div class="cartshift-log-breakdown-body">
                  <span class="cartshift-log-breakdown-label">
                    {{ row.label }}
                    <span :class="['cartshift-log-severity', 'cartshift-log-severity-' + row.severity]">
                      {{ severityText(row.severity) }}
                    </span>
                  </span>
                  <span v-if="row.hint" class="cartshift-log-breakdown-hint">{{ row.hint }}</span>
                </div>
              </div>
              <button
                v-if="row.remedy"
                type="button"
                class="button cartshift-remedy"
                @click="$emit('apply-remedy', row.remedy)"
              >
                {{ row.remedy.label || 'Apply' }}
              </button>
            </li>
          </ul>
        </div>
      </template>

      <!-- No preview available: this build predates the endpoint, so fall back to the
           old whole-shop counts and say nothing about consequences we cannot see. -->
      <p v-else class="description">
        {{ loading ? 'Working out what this selection includes…' : '' }}
      </p>
    </template>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  preview: { type: Object, default: null },
  counts: { type: Object, default: null },
  loading: { type: Boolean, default: false },
});

defineEmits(['apply-remedy']);

function capitalize(str) {
  if (!str) return '';
  return str.charAt(0).toUpperCase() + str.slice(1).replace(/_/g, ' ');
}

/** The counts table: the preview's counts when we have one, the old whole-shop counts otherwise. */
const countRows = computed(() => {
  const source = props.preview ? props.preview.counts : props.counts;
  if (!source || typeof source !== 'object') return [];

  return Object.keys(source).map((key) => ({
    key,
    label: capitalize(key),
    value: Number(source[key]) || 0,
  }));
});

/**
 * The extra products/customers a narrower pick pulls in that the owner did not
 * choose themselves — stated in counts, never as "closure" or any other
 * mechanism word.
 */
const closureNote = computed(() => {
  const closure = props.preview && !props.preview.too_large ? props.preview.closure : null;
  if (!closure || typeof closure !== 'object') return '';

  const parts = [];
  const products = Number(closure.products) || 0;
  const customers = Number(closure.customers) || 0;

  if (products > 0) {
    parts.push(`${products.toLocaleString()} more ${products === 1 ? 'product' : 'products'}`);
  }
  if (customers > 0) {
    parts.push(`${customers.toLocaleString()} more ${customers === 1 ? 'customer' : 'customers'}`);
  }

  if (!parts.length) return '';

  return `This selection also brings in ${parts.join(' and ')}, so orders and subscriptions stay linked.`;
});

/**
 * Consequences with something to report. A code the front end has never seen
 * is rendered with whatever the server sent — the vocabulary belongs to the
 * server, not to this bundle.
 */
const visibleConsequences = computed(() => {
  const list = props.preview && Array.isArray(props.preview.consequences)
    ? props.preview.consequences
    : [];

  return list
    .filter((row) => row && Number(row.count) > 0)
    .map((row) => {
      const count = Number(row.count) || 0;
      // The server says whether its own count is a floor — via `is_minimum`
      // on the descriptor (ScopeConsequences::describe()) — never inferred
      // here from `code`. A future consequence can be flagged the same way
      // without a front-end release, which is the whole point.
      const isMinimum = row.is_minimum === true;

      return {
        code: row.code,
        label: row.label || capitalize(row.code),
        hint: row.hint || '',
        severity: severityOf(row.severity),
        remedy: row.remedy || null,
        count,
        // "At least N", never a bare N, whenever the server says its count
        // is a floor rather than a fact.
        countLabel: isMinimum ? `At least ${count.toLocaleString()}` : count.toLocaleString(),
      };
    });
});

function severityOf(raw) {
  const value = typeof raw === 'string' ? raw.toLowerCase() : '';
  return value === 'error' || value === 'warning' || value === 'info' ? value : 'info';
}

function severityClass(severity) {
  return 'cartshift-log-breakdown-row-' + severity;
}

function severityText(severity) {
  if (severity === 'error') return 'Error';
  if (severity === 'warning') return 'Warning';
  return 'Info';
}
</script>
