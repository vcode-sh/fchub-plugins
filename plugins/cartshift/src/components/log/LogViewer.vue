<template>
  <div class="cartshift-log-viewer">
    <LogSummary
      :stats="logState.stats"
      :active-filter="logState.statusFilter"
      @filter="onFilter"
    />

    <LogBreakdown
      v-if="hasBreakdown"
      :rows="codeBreakdown.rows"
      :derived="codeBreakdown.derived"
      :covers="codeBreakdown.covers"
      :active-code="logState.codeFilter"
      :retry-enabled="retryEnabled"
      @select="onCodeFilter"
      @retry="$emit('retry', $event)"
    />

    <LogToolbar
      :search-query="logState.searchQuery"
      :status-filter="logState.statusFilter"
      :per-page="logState.perPage"
      :stats="logState.stats"
      @search="onSearch"
      @filter="onFilter"
      @export="onExport"
      @perpage="onPerPage"
    />

    <p v-if="logState.codeFilter" class="cartshift-log-active-filter">
      <span>
        Showing entries classified as <code>{{ logState.codeFilter }}</code>.
        <template v-if="!logState.codeFilterServerSide">
          Filtered from the {{ logState.entries.length }} entries loaded here &mdash; the server
          does not filter by reason, so load more to widen the net.
        </template>
      </span>
      <button type="button" class="button" @click="onCodeFilter('')">Clear</button>
    </p>

    <div
      class="cartshift-log-loading"
      role="status"
      aria-live="polite"
      v-if="logState.loading"
    >
      <span class="spinner is-active" style="float:none;"></span>
      Loading log entries...
    </div>

    <template v-else-if="filteredEntries.length > 0">
      <table class="cartshift-log-table">
        <caption class="cartshift-sr-only">
          Migration log entries. Each row expands for the full message and details.
        </caption>
        <thead>
          <tr>
            <th class="cartshift-log-th-icon" scope="col">
              <span class="cartshift-sr-only">Status</span>
            </th>
            <th class="cartshift-log-th-time" scope="col">Time</th>
            <th class="cartshift-log-th-entity" scope="col">Entity</th>
            <th class="cartshift-log-th-message" scope="col">Message</th>
            <th class="cartshift-log-th-expand" scope="col">
              <span class="cartshift-sr-only">Details</span>
            </th>
          </tr>
        </thead>
        <LogRow
          v-for="entry in filteredEntries"
          :key="entry.id"
          :entry="entry"
          :descriptor="descriptorFor(entry)"
        />
      </table>

      <div v-if="logState.hasMore && !logState.searchQuery" class="cartshift-log-load-more">
        <button
          class="button"
          :disabled="logState.loadingMore"
          @click="loadMore()"
        >
          <template v-if="logState.loadingMore">
            <span class="spinner is-active" style="float:none;margin:0 5px 0 0;"></span>
            Loading...
          </template>
          <template v-else>
            Load More ({{ logState.entries.length }} of {{ logState.total }})
          </template>
        </button>
      </div>
    </template>

    <div v-else class="cartshift-log-empty">
      <template v-if="logState.searchQuery">
        No entries matching "{{ logState.searchQuery }}".
      </template>
      <template v-else-if="logState.codeFilter">
        <template v-if="logState.codeFilterServerSide">
          Nothing in this run was classified as {{ logState.codeFilter }}.
        </template>
        <template v-else>
          None of the {{ logState.entries.length }} entries loaded here were classified as
          {{ logState.codeFilter }}. Load more to widen the net.
        </template>
      </template>
      <template v-else-if="logState.statusFilter">
        No {{ logState.statusFilter }} entries found.
      </template>
      <template v-else>
        No log entries found.
      </template>
    </div>
  </div>
</template>

<script setup>
import { onMounted, watch } from 'vue';
import { useLogViewer, extractCode } from '@/composables/useLogViewer.js';
import LogSummary from './LogSummary.vue';
import LogBreakdown from './LogBreakdown.vue';
import LogToolbar from './LogToolbar.vue';
import LogRow from './LogRow.vue';

const props = defineProps({
  migrationId: {
    type: String,
    default: null,
  },
  // Set by screens that can start a retry, so the grouped breakdown can offer
  // one per reason. Off everywhere else — a button that leads nowhere is worse
  // than no button.
  retryEnabled: {
    type: Boolean,
    default: false,
  },
});

defineEmits(['retry']);

const {
  state: logState,
  filteredEntries,
  codeBreakdown,
  codeDescriptors,
  hasBreakdown,
  loadInitial,
  loadMore,
  setFilter,
  setCodeFilter,
  setSearch,
  setPerPage,
  exportCsv,
} = useLogViewer();

/** The translated descriptor for a row's code, when stats knows about it. */
function descriptorFor(entry) {
  const code = extractCode(entry);
  return code ? codeDescriptors.value[code] || null : null;
}

function onSearch(query) {
  setSearch(query);
}

function onFilter(status) {
  setFilter(status);
}

function onCodeFilter(code) {
  setCodeFilter(code);
}

function onPerPage(value) {
  setPerPage(value);
}

function onExport() {
  exportCsv();
}

onMounted(() => {
  if (props.migrationId) {
    loadInitial(props.migrationId);
  }
});

watch(() => props.migrationId, (newId) => {
  if (newId) {
    loadInitial(newId);
  }
});
</script>
