<template>
  <div class="cartshift-log-viewer">
    <LogSummary
      :stats="logState.stats"
      :active-filter="logState.statusFilter"
      @filter="onFilterFromSummary"
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

    <div v-if="logState.loading" class="cartshift-log-loading">
      <span class="spinner is-active" style="float:none;"></span>
      Loading log entries...
    </div>

    <template v-else-if="filteredEntries.length > 0">
      <table class="cartshift-log-table">
        <thead>
          <tr>
            <th class="cartshift-log-th-icon"></th>
            <th class="cartshift-log-th-time">Time</th>
            <th class="cartshift-log-th-entity">Entity</th>
            <th class="cartshift-log-th-message">Message</th>
            <th class="cartshift-log-th-expand"></th>
          </tr>
        </thead>
        <LogRow
          v-for="entry in filteredEntries"
          :key="entry.id"
          :entry="entry"
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
import { useLogViewer } from '@/composables/useLogViewer.js';
import LogSummary from './LogSummary.vue';
import LogToolbar from './LogToolbar.vue';
import LogRow from './LogRow.vue';

const props = defineProps({
  migrationId: {
    type: String,
    default: null,
  },
});

const {
  state: logState,
  filteredEntries,
  loadInitial,
  loadMore,
  setFilter,
  setSearch,
  setPerPage,
  exportCsv,
} = useLogViewer();

function onSearch(query) {
  setSearch(query);
}

function onFilter(status) {
  setFilter(status);
}

function onFilterFromSummary(status) {
  setFilter(status);
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
