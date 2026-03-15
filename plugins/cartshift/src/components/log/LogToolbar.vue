<template>
  <div class="cartshift-log-toolbar">
    <div class="cartshift-log-toolbar-left">
      <div class="cartshift-log-search-wrap">
        <span class="cartshift-log-search-icon">&#128269;</span>
        <input
          type="text"
          :value="searchQuery"
          placeholder="Search messages, WC IDs..."
          class="cartshift-log-search"
          @input="onSearch"
        />
      </div>

      <select
        :value="statusFilter"
        class="cartshift-log-filter-select"
        @change="$emit('filter', $event.target.value)"
      >
        <option value="">All statuses ({{ stats.total }})</option>
        <option value="success">Processed ({{ stats.success }})</option>
        <option value="error">Errors ({{ stats.error }})</option>
        <option value="skipped">Skipped ({{ stats.skipped }})</option>
        <option v-if="stats['dry-run'] > 0" value="dry-run">Dry-run ({{ stats['dry-run'] }})</option>
      </select>

      <select
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
      <button class="button cartshift-log-export-btn" @click="$emit('export')">
        &#8681; Export CSV
      </button>
    </div>
  </div>
</template>

<script setup>
let debounceTimer = null;

defineProps({
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
    default: () => ({ success: 0, skipped: 0, error: 0, 'dry-run': 0, total: 0 }),
  },
});

const emit = defineEmits(['search', 'filter', 'export', 'perpage']);

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
