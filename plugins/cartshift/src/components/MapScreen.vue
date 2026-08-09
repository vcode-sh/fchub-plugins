<template>
  <div>
    <PageHeader title="Map Products to FluentCart" />
    <p>
      Link each WooCommerce product to the FluentCart product you already built, or let CartShift
      create it. Nothing is written until you continue.
    </p>

    <div v-if="state.error" class="notice notice-error inline" role="alert">
      <p>{{ state.error }}</p>
    </div>

    <!-- Arrived from the subscription audit with a specific row to re-decide.
         Saying which one is the whole point of the link: a catalogue of two
         thousand rows and a remembered product ID is not navigation. -->
    <div
      v-if="migrationState.mapFocus"
      class="notice notice-info inline"
      data-map-focus
      role="status"
    >
      <p>
        The subscription audit sent you here to map
        <strong>{{ migrationState.mapFocus.name || 'a product' }}</strong>
        (WooCommerce product #{{ migrationState.mapFocus.wc_id }}).
        <button type="button" class="button-link" data-action="clear-focus" @click="clearFocus()">
          Dismiss
        </button>
      </p>
    </div>

    <div class="cartshift-map-summary">
      <span><strong>{{ summary.total }}</strong> Woo products</span>
      <span><strong>{{ summary.decided }}</strong> of {{ summary.loaded }} decided</span>
      <span><strong>{{ summary.fcProductCount }}</strong> in FluentCart</span>
    </div>

    <!-- Every in-scope product is loaded before the bands are drawn, so a band
         count is the whole band. If a load stopped short, the bulk buttons are
         acting on less than they say and the owner needs to know that. -->
    <div
      v-if="!state.loading && !summary.complete"
      class="notice notice-warning inline"
      data-partial-load
      role="alert"
    >
      <p>
        Only {{ summary.loaded }} of {{ summary.total }} products loaded. Bulk actions apply to
        what is on screen. Reload to try again.
      </p>
    </div>

    <fieldset class="cartshift-map-mode">
      <legend>What happens to products you do not touch?</legend>
      <label>
        <input type="radio" value="create-rest" v-model="state.runMode" />
        Create them in FluentCart, as usual
      </label>
      <label>
        <input
          type="radio"
          value="only-mapped"
          v-model="state.runMode"
          :disabled="!runModeOffered"
        />
        Migrate only what I mapped
      </label>
      <!-- A scope is one of three exclusive modes, so a date cutoff and a
           product whitelist cannot both survive the round trip. Saying so beats
           quietly dropping the cutoff and migrating every order these products
           ever had. -->
      <span v-if="!runModeOffered" class="description" data-run-mode-blocked>
        Not available with a date-limited run: you chose to migrate everything since a date, and
        narrowing to mapped products would drop that cutoff. Go Back and choose a full run to use
        this.
      </span>
    </fieldset>

    <div v-for="band in BANDS" :key="band">
      <template v-if="grouped[band].length">
        <div class="cartshift-map-band" :data-band="band">
          <strong>{{ bandLabel(band) }} &middot; {{ grouped[band].length }}</strong>
          <span class="description">{{ bandHint(band) }}</span>

          <button
            v-if="band !== 'none'"
            type="button"
            class="button"
            data-bulk="link"
            @click="bulk(band, 'link')"
          >
            Link all {{ grouped[band].length }}
          </button>
          <button type="button" class="button" data-bulk="create" @click="bulk(band, 'create')">
            Create all
          </button>
          <button type="button" class="button" data-bulk="skip" @click="bulk(band, 'skip')">
            Skip all
          </button>
        </div>

        <table class="widefat striped cartshift-table-map">
          <thead>
            <tr><th>WooCommerce Product</th><th>FluentCart Product</th><th>Actions</th></tr>
          </thead>
          <tbody>
            <MapRow
              v-for="row in grouped[band]"
              :key="row.wc_id"
              :row="row"
              @decide="(decision) => decide(row, decision)"
              @suggest="(id) => chooseCandidate(row, id)"
              @search="openSearch(row)"
              @choose-variation="(sourceId, targetId) => chooseVariation(row, sourceId, targetId)"
              @share-target="(allowed) => (row.allow_shared_target = allowed)"
            />
          </tbody>
        </table>
      </template>
    </div>

    <p class="cartshift-map-continue">
      <!-- Disabled while loading as well as while starting: "only what I
           mapped" builds its whitelist from the rows on screen, and half a
           catalogue makes a whitelist that quietly drops the other half. -->
      <button
        class="button button-primary button-hero"
        :disabled="migrationState.migrating || state.loading"
        @click="continueToMigration"
      >
        Continue
      </button>
      <button class="button" @click="actions.goToScreen('select')">Back</button>
      <!-- The audit sends blocked subscription rows here to be re-decided, and
           a one-way trip would leave the operator to find their way back by
           memory. Saving a decision is a CartShift configuration write, so the
           audit's numbers move when they return — which is the point. -->
      <button
        class="button"
        data-action="subscription-audit"
        @click="actions.goToScreen('subscription-audit')"
      >
        Back to the subscription audit
      </button>
      <!-- reset() deliberately spares the staging table, so this is the only
           way a stale decision set ever stops governing later runs. -->
      <button class="button button-link-delete" data-action="clear" @click="confirmingClear = true">
        Clear all mappings
      </button>
    </p>

    <p v-if="state.loading" class="description">Loading products&hellip;</p>

    <!-- The manual rescue. A row the matcher scored `none` has no dropdown, and
         on Lapka that is both subscription products — so the whole target
         catalogue is here, searchable, one click from the row. -->
    <div v-if="searchingRow" class="cartshift-catalogue" data-catalogue-search>
      <p>
        <strong>{{ searchingRow.name }}</strong>
        <span class="description">Pick the FluentCart product this becomes.</span>
      </p>

      <p>
        <input
          type="search"
          class="regular-text"
          placeholder="Search FluentCart products"
          :value="state.catalogue.query"
          @input="searchCatalogue($event.target.value)"
        />
        <button type="button" class="button" data-action="close-search" @click="searchingRow = null">
          Close
        </button>
      </p>

      <p v-if="state.catalogue.error" class="cartshift-map-refused">{{ state.catalogue.error }}</p>
      <p v-else-if="state.catalogue.loading" class="description">Searching&hellip;</p>
      <p v-else-if="!state.catalogue.products.length" class="description">
        Nothing in FluentCart matches that.
      </p>

      <ul class="cartshift-catalogue-list">
        <li v-for="product in state.catalogue.products" :key="product.id">
          <button
            type="button"
            class="button-link"
            :data-catalogue-product="product.id"
            @click="pickProduct(product.id)"
          >
            {{ product.name }}
          </button>
          <span class="description">{{ product.variation_count }} variation(s)</span>
        </li>
      </ul>
    </div>

    <ConfirmDialog
      :open="confirmingClear"
      title="Clear every mapping?"
      confirm-label="Clear mappings"
      tone="danger"
      @confirm="doClear"
      @cancel="confirmingClear = false"
    >
      <p>
        Every link, create and skip you have decided is forgotten, for all
        {{ summary.total }} products. Nothing that has already been migrated is touched — this
        only clears the decisions, and you will be starting the mapping from scratch.
      </p>
    </ConfirmDialog>
  </div>
</template>

<script setup>
import { computed, inject, onMounted, ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import MapRow from '@/components/MapRow.vue';
import { useMapping } from '@/composables/useMapping.js';
import { serializeScope } from '@/composables/useMigration.js';

// The wizard's shared state comes from App.vue's provide, never from calling
// useMigration() again — that would hand this screen a private copy. Aliased
// to migrationState because useMapping() below also returns a `state`, and
// the two are not interchangeable: this one is shared across the whole
// wizard, useMapping()'s belongs only to this visit.
const { state: migrationState, actions } = inject('migration');

// useMapping, by contrast, IS per-screen: its rows belong to this visit.
const {
  state,
  loadRows,
  decide,
  bulk,
  clearAll,
  chooseCandidate,
  searchCatalogue,
  chooseProduct,
  chooseVariation,
  applyRunMode,
  runModeAvailable,
  bandRows,
  summary,
  BANDS,
} = useMapping();

const confirmingClear = ref(false);

// Which row the catalogue search is for. One at a time, because the answer is
// a link for that product and nothing else.
const searchingRow = ref(null);

async function openSearch(row) {
  searchingRow.value = row;
  await searchCatalogue('');
}

async function pickProduct(fcPostId) {
  const row = searchingRow.value;

  searchingRow.value = null;

  await chooseProduct(row, fcPostId);
}

// "Only what I mapped" and "everything since a date" are different modes of the
// same scope, so one always discards the other. See runModeAvailable().
const runModeOffered = computed(() => runModeAvailable(migrationState.scope));

// bandRows() re-filters state.rows on every call, and the template reads it
// up to four times per band (the v-if guard, the header count, the bulk-link
// label, and the row source) — group once per render instead of four times.
const grouped = computed(() => {
  const map = {};

  for (const band of BANDS) {
    map[band] = bandRows(band);
  }

  return map;
});

const LABELS = {
  strong: 'Strong',
  likely: 'Likely',
  weak: 'Weak',
  none: 'No candidate',
};

const HINTS = {
  strong: 'same SKU, or same name and price',
  likely: 'similar name only',
  weak: 'loose match — check these',
  none: 'nothing in FluentCart looks like this',
};

function bandLabel(band) {
  return LABELS[band];
}

function bandHint(band) {
  return HINTS[band];
}

/**
 * The run mode is applied on the way out, not while the owner is toggling it.
 * It rewrites the wizard's shared scope, and doing that on every click would
 * leave a half-decided whitelist behind if they went Back instead.
 */
function continueToMigration() {
  applyRunMode(migrationState);
  actions.startMigration();
}

async function doClear() {
  confirmingClear.value = false;
  await clearAll();
}

/**
 * Dismiss the audit's "re-decide this row" pointer.
 *
 * Guarded on the action existing so a build whose wizard state predates the
 * pointer degrades to hiding nothing rather than throwing on click.
 */
function clearFocus() {
  if (actions.clearMapFocus) {
    actions.clearMapFocus();
    return;
  }

  migrationState.mapFocus = null;
}

// The same scope the run will use, so the screen presents the products that
// run will actually migrate rather than the whole catalogue.
onMounted(() => loadRows(serializeScope(migrationState.scope)));
</script>
