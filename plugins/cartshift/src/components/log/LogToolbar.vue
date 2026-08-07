<template>
  <div class="cartshift-log-toolbar">
    <div class="cartshift-log-toolbar-left">
      <div class="cartshift-log-search-wrap">
        <span class="cartshift-log-search-icon" aria-hidden="true">&#128269;</span>
        <label for="cartshift-log-search" class="cartshift-sr-only">
          Search loaded log entries
        </label>
        <input
          id="cartshift-log-search"
          type="text"
          :value="searchQuery"
          placeholder="Search messages, WC IDs..."
          class="cartshift-log-search"
          @input="onSearch"
        />
      </div>

      <label for="cartshift-log-status" class="cartshift-sr-only">Filter by status</label>
      <select
        id="cartshift-log-status"
        :value="statusFilter"
        class="cartshift-log-filter-select"
        @change="$emit('filter', $event.target.value)"
      >
        <option value="">All statuses ({{ count('total') }})</option>
        <option value="success">Processed ({{ count('success') }})</option>
        <option value="error">Errors ({{ count('error') }})</option>
        <option value="warning">Warnings ({{ count('warning') }})</option>
        <option value="skipped">Skipped ({{ count('skipped') }})</option>
        <option v-if="count('dry-run') > 0" value="dry-run">Dry-run ({{ count('dry-run') }})</option>
      </select>

      <label for="cartshift-log-perpage" class="cartshift-sr-only">Entries per page</label>
      <select
        id="cartshift-log-perpage"
        :value="perPage"
        class="cartshift-log-perpage-select"
        @change="$emit('perpage', Number($event.target.value))"
      >
        <option :value="25">25 / page</option>
        <option :value="50">50 / page</option>
        <option :value="100">100 / page</option>
      </select>
    </div>

    <div class="cartshift-log-toolbar-right">
      <button type="button" class="button cartshift-log-export-btn" @click="$emit('export')">
        <span aria-hidden="true">&#8681;</span> Export CSV
      </button>
    </div>
  </div>
</template>

<script setup>
let debounceTimer = null;

const props = defineProps({
  searchQuery: {
    type: String,
    default: '',
  },
  statusFilter: {
    type: String,
    default: '',
  },
  perPage: {
    type: Number,
    default: 50,
  },
  stats: {
    type: Object,
    default: () => ({ success: 0, skipped: 0, warning: 0, error: 0, 'dry-run': 0, total: 0 }),
  },
});

const emit = defineEmits(['search', 'filter', 'export', 'perpage']);

function count(status) {
  const value = Number(props.stats?.[status]);
  return Number.isFinite(value) ? value : 0;
}

function onSearch(event) {
  const value = event.target.value;

  if (debounceTimer) {
    clearTimeout(debounceTimer);
  }

  debounceTimer = setTimeout(() => {
    emit('search', value);
  }, 300);
}
</script>
