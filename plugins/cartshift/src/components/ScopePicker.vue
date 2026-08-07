<template>
  <div class="cartshift-scope-picker">
    <label :for="inputId" class="cartshift-scope-picker-label">
      {{ kind === 'product' ? 'Find products' : 'Find customers' }}
    </label>
    <input
      :id="inputId"
      type="search"
      class="cartshift-scope-picker-input"
      :placeholder="kind === 'product' ? 'Search by name or SKU…' : 'Search by name or email…'"
      v-model="query"
      autocomplete="off"
    />

    <div aria-live="polite">
      <p v-if="truncated" class="description cartshift-scope-picker-truncated">
        Showing the first {{ SEARCH_LIMIT }} matches — keep typing to narrow it down.
      </p>

      <ul v-if="results.length" class="cartshift-scope-picker-results">
        <li v-for="result in results" :key="result.kind + ':' + result.id">
          <button
            type="button"
            class="button cartshift-scope-result"
            @click="pick(result)"
          >
            <span class="cartshift-scope-result-label">{{ result.label }}</span>
            <span v-if="result.sublabel" class="cartshift-scope-result-sublabel">{{ result.sublabel }}</span>
          </button>
        </li>
      </ul>

      <p v-else-if="searched && !loading" class="description">No matches.</p>
    </div>

    <ul v-if="modelValue.length" class="cartshift-scope-picker-chips">
      <li v-for="item in modelValue" :key="(item.kind || kind) + ':' + item.id" class="cartshift-scope-chip">
        <span class="cartshift-scope-chip-label">{{ chipLabel(item) }}</span>
        <button
          type="button"
          class="button-link cartshift-scope-chip-remove"
          :aria-label="'Remove ' + chipLabel(item)"
          @click="remove(item)"
        >
          &times;
        </button>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { ref, watch, onBeforeUnmount } from 'vue';
import { useApi } from '@/composables/useApi.js';

const SEARCH_LIMIT = 20;
const DEBOUNCE_MS = 300;

const props = defineProps({
  modelValue: { type: Array, required: true },
  kind: { type: String, required: true, validator: (v) => v === 'product' || v === 'customer' },
});

const emit = defineEmits(['update:modelValue']);

const { api } = useApi();

const inputId = `cartshift-scope-picker-${props.kind}`;

const query = ref('');
const results = ref([]);
const truncated = ref(false);
const loading = ref(false);
const searched = ref(false);

let debounceTimer = null;

function search(term) {
  const trimmed = term.trim();

  if (trimmed === '') {
    results.value = [];
    truncated.value = false;
    searched.value = false;
    return;
  }

  loading.value = true;

  const params = new URLSearchParams({
    type: props.kind,
    q: trimmed,
    limit: String(SEARCH_LIMIT),
  });

  api('GET', `scope/search?${params.toString()}`)
    .then((data) => {
      results.value = (data && data.results) || [];
      truncated.value = !!(data && data.truncated);
    })
    .catch(() => {
      results.value = [];
      truncated.value = false;
    })
    .finally(() => {
      loading.value = false;
      searched.value = true;
    });
}

watch(query, (term) => {
  if (debounceTimer !== null) {
    clearTimeout(debounceTimer);
  }
  debounceTimer = setTimeout(() => search(term), DEBOUNCE_MS);
});

onBeforeUnmount(() => {
  if (debounceTimer !== null) {
    clearTimeout(debounceTimer);
    debounceTimer = null;
  }
});

// applyRemedy() (useMigration.js) pushes bare `{ id }` entries — no `kind`,
// no `label` — onto state.scope.products, ahead of the picker ever learning
// their name. itemKind() falls back to this picker's own `kind` prop so a
// remedy-added product still matches/removes correctly, and chipLabel()
// shows the id rather than rendering "undefined".
function itemKind(item) {
  return item.kind || props.kind;
}

function chipLabel(item) {
  return item.label || `#${item.id}`;
}

function pick(result) {
  const known = props.modelValue.some(
    (item) => itemKind(item) === itemKind(result) && String(item.id) === String(result.id)
  );
  if (known) return;

  emit('update:modelValue', [...props.modelValue, result]);
}

function remove(item) {
  emit(
    'update:modelValue',
    props.modelValue.filter(
      (existing) => !(itemKind(existing) === itemKind(item) && String(existing.id) === String(item.id))
    )
  );
}
</script>
