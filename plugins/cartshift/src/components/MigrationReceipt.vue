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

      <!-- Consequences: only when there is a preview, and only the non-zero ones. -->
      <template v-if="preview">
        <h2>What will not</h2>

        <div v-if="!visibleConsequences.length" class="notice notice-success inline">
          <p><strong>Nothing left behind.</strong></p>
        </div>

        <ul v-else class="cartshift-receipt-list" aria-live="polite">
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

/**
 * `product_link_missing` is reused from PreflightCheck::countOrdersAffectedByTypes(),
 * which only looks at publish/draft/private orders — a matching order sitting in
 * the trash never gets counted. So the number the server sends is a floor, not
 * the true figure, and the receipt has to say so rather than presenting it as exact.
 */
const LOWER_BOUND_CODES = new Set(['product_link_missing']);

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
      const isMinimum = LOWER_BOUND_CODES.has(row.code);

      return {
        code: row.code,
        label: row.label || capitalize(row.code),
        hint: row.hint || '',
        severity: severityOf(row.severity),
        remedy: row.remedy || null,
        count,
        // "At least N", never a bare N — the count is a floor, not a fact,
        // for the one code that reuses a query with a narrower net than
        // the truth (see LOWER_BOUND_CODES above).
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
