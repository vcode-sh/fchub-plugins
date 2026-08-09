<template>
  <div>
    <PageHeader title="Subscription Audit" />

    <!-- Before anything else, and never conditional on the result. The whole
         mode is defined by what it does not do, and an operator arriving from
         a screen whose dry run DOES write simulation rows needs the difference
         stated rather than implied. -->
    <div class="cartshift-audit-zero-write" data-zero-write>
      <strong>Read only.</strong>
      <span>{{ zeroWriteStatement }}</span>
      <p class="description">{{ dryRunNote }}</p>
    </div>

    <!-- The reference deployment is two WordPress installations sharing one
         MariaDB, so the package route is not an advanced option — it is the
         only route the target can take. A screen that could only audit `live`
         could not audit the migration this plugin was written for. -->
    <div class="cartshift-audit-panel" data-source-picker>
      <h2>What should be audited?</h2>
      <label class="cartshift-audit-source-option">
        <input
          type="radio"
          name="cartshift-audit-source"
          value="live"
          data-source-mode="live"
          :checked="state.source === 'live'"
          @change="chooseSource('live')"
        />
        <span>
          <strong>This runtime&rsquo;s WooCommerce</strong>
          <span class="description">Same site and prefix as FluentCart.</span>
        </span>
      </label>
      <label class="cartshift-audit-source-option">
        <input
          type="radio"
          name="cartshift-audit-source"
          value="package"
          data-source-mode="package"
          :checked="state.source === 'package'"
          @change="chooseSource('package')"
        />
        <span>
          <strong>A private package exported from the source site</strong>
          <span class="description">
            Two WordPress installations, or two prefixes. The file never leaves the server.
          </span>
        </span>
      </label>

      <div v-if="state.source === 'package'" class="cartshift-audit-package">
        <label>
          Absolute path, outside the web root
          <input
            type="text"
            class="regular-text"
            data-package-path
            :value="state.file"
            placeholder="/srv/private/source.ndjson"
            @input="state.file = $event.target.value"
          />
        </label>
        <button type="button" class="button" data-action="audit-package" @click="load()">
          Audit this file
        </button>
        <!-- Prepare is the write, and it is labelled as one on the button
             itself rather than only in the panel below. -->
        <button
          type="button"
          class="button"
          data-action="prepare-package"
          :disabled="!state.file"
          @click="preparePackage(state.file)"
        >
          Remember it (writes four strings)
        </button>
        <p v-if="state.configurationWrite" class="description" data-configuration-write>
          {{ state.configurationWrite }}
        </p>
        <p v-if="sourceMismatch" class="cartshift-audit-warn" data-source-mismatch>
          Everything below is still the last reading of the
          {{ doc.source.mode === 'package' ? 'package' : 'live WooCommerce runtime' }}. Press
          &ldquo;Audit this file&rdquo; to replace it.
        </p>
      </div>
    </div>

    <div v-if="state.error" class="notice notice-error inline" role="alert">
      <p>{{ state.error }}</p>
    </div>

    <p v-if="state.loading" class="description">Reading the source&hellip;</p>

    <template v-if="doc">
      <!-- ── Where this reading came from ─────────────────────────────── -->
      <div class="cartshift-audit-summary" data-source>
        <span><strong>Source</strong> {{ sourceLabel }}</span>
        <span><strong>Source key</strong> <code>{{ doc.source.source_key }}</code></span>
        <span v-if="doc.source.file"><strong>File</strong> <code>{{ doc.source.file }}</code></span>
        <span><strong>Storage authority</strong> {{ doc.source.storage_authority || 'unknown' }}</span>
        <span><strong>Selection</strong> <code>{{ short(doc.source.selection_fingerprint) }}</code></span>
      </div>

      <!-- ── Readiness ─────────────────────────────────────────────────── -->
      <div class="cartshift-audit-totals" data-totals>
        <!-- Each count filters the record list below it. The endpoint already
             takes `outcome`; without this the numbers were a dead end. -->
        <button
          v-for="slot in outcomeSlots"
          :key="slot.outcome"
          type="button"
          class="cartshift-audit-count"
          :class="{ 'cartshift-audit-count-active': state.filters.outcome === slot.outcome }"
          :data-filter-outcome="slot.outcome"
          :aria-pressed="state.filters.outcome === slot.outcome"
          @click="toggleOutcome(slot.outcome)"
        >
          <span class="cartshift-audit-count-value">{{ n(slot.count) }}</span>
          <span class="cartshift-audit-count-label">{{ slot.label }}</span>
        </button>
        <div class="cartshift-audit-count">
          <span class="cartshift-audit-count-value">{{ n(doc.totals.invalid) }}</span>
          <span class="cartshift-audit-count-label">Unreadable at source</span>
        </div>
        <p class="cartshift-audit-reconcile" :data-reconciles="String(doc.totals.reconciles)">
          <template v-if="doc.totals.reconciles">
            Adds up to all {{ n(doc.totals.selected) }} selected subscriptions.
          </template>
          <template v-else>
            These do <strong>not</strong> add up to the {{ n(doc.totals.selected) }} selected
            subscriptions. Do not stage this cohort — something is being counted twice or not at all.
          </template>
        </p>
      </div>

      <!-- ── The expected first-run state ──────────────────────────────── -->
      <div
        v-if="doc.confirmation.awaiting > 0 && !doc.confirmation.manual_fallback_confirmed"
        class="cartshift-audit-panel cartshift-audit-panel-attention"
        data-confirmation
      >
        <h2>{{ n(doc.confirmation.awaiting) }} subscriptions are waiting on a decision</h2>
        <p>{{ doc.confirmation.remedy }}</p>
      </div>

      <!-- ── What writes, and what does not ────────────────────────────── -->
      <div class="cartshift-audit-panel" data-configuration-writes>
        <h2>What would write, if you asked it to</h2>
        <p class="description">
          None of these is part of the audit. They are CartShift configuration writes, listed here so
          the difference is visible from the screen that makes none of them.
        </p>
        <ul class="cartshift-audit-writes">
          <li v-for="write in doc.writes.configuration_writes" :key="write.action">
            <strong>{{ write.label }}</strong>
            <span class="description">{{ write.writes }}</span>
          </li>
        </ul>
      </div>

      <div class="cartshift-audit-grid">
        <!-- ── Breakdowns ─────────────────────────────────────────────── -->
        <div class="cartshift-audit-panel" data-breakdown>
          <h2>The cohort</h2>
          <TallyList label="Status" :tally="doc.breakdown.by_status" />
          <TallyList label="Cadence" :tally="doc.breakdown.by_cadence" />
          <TallyList label="Payment strategy" :tally="doc.breakdown.by_strategy" />
          <TallyList label="Collection method" :tally="doc.breakdown.by_collection_method" />
        </div>

        <!-- ── Customers ──────────────────────────────────────────────── -->
        <div class="cartshift-audit-panel" data-customers>
          <h2>Customers</h2>
          <ul class="cartshift-audit-facts">
            <li>
              <strong>{{ n(doc.customers.unique_identities) }}</strong> distinct email identities
              across {{ n(doc.customers.assessed) }} subscriptions
            </li>
            <li><strong>{{ n(doc.customers.guests_at_source) }}</strong> guests at source</li>
            <li><strong>{{ n(doc.customers.registered_at_source) }}</strong> registered at source</li>
            <li v-if="doc.customers.blank_email">
              <strong>{{ n(doc.customers.blank_email) }}</strong> with no email at all
            </li>
          </ul>

          <h3>What resolution would do</h3>
          <ul class="cartshift-audit-facts">
            <li>
              <strong>{{ n(doc.customers.resolution.matched_target_user) }}</strong>
              match a WordPress user on this site
            </li>
            <li>
              <strong>{{ n(doc.customers.resolution.reused_customer) }}</strong>
              reuse an existing FluentCart customer
            </li>
            <li>
              <strong>{{ n(doc.customers.resolution.adopted_target_user) }}</strong>
              adopt the FluentCart customer that user already has
            </li>
            <li>
              <strong>{{ n(doc.customers.resolution.reused_guest) }}</strong>
              reuse a guest this migration already recorded
            </li>
            <!-- Split, because the sum is the one shape that tells an operator
                 nothing: "43 attach to a WordPress user this site already has"
                 and "172 arrive as brand-new guests" are different amounts of
                 work and different amounts of risk. -->
            <li>
              <strong>{{ n(doc.customers.resolution.attached_target_user) }}</strong>
              would get a new FluentCart customer attached to an existing WordPress user
            </li>
            <li>
              <strong>{{ n(doc.customers.resolution.would_create_guest) }}</strong>
              would arrive as new guest customers
            </li>
            <li>
              <strong>{{ n(doc.customers.resolution.would_create) }}</strong>
              would be created in total &mdash; nothing was
            </li>
            <li v-if="doc.customers.resolution.blocked">
              <strong>{{ n(doc.customers.resolution.blocked) }}</strong> blocked:
              <code
                v-for="(count, code) in doc.customers.resolution.blocked_reason_codes"
                :key="code"
              >{{ code }} &times;{{ count }}</code>
            </li>
          </ul>

          <h3>Already recorded by this migration</h3>
          <ul class="cartshift-audit-facts">
            <li><strong>{{ n(doc.customers.resolved_in_id_map) }}</strong> resolve through the ID map</li>
            <li><strong>{{ n(doc.customers.unresolved_in_id_map) }}</strong> do not yet</li>
          </ul>
          <p class="description">{{ doc.customers.note }}</p>
        </div>

        <!-- ── Stripe ─────────────────────────────────────────────────── -->
        <div class="cartshift-audit-panel" data-stripe-split>
          <h2>Stripe &middot; {{ n(doc.stripe.total) }}</h2>
          <ul class="cartshift-audit-facts">
            <li><strong>{{ n(doc.stripe.modern) }}</strong> modern payment method (<code>pm_</code>)</li>
            <li><strong>{{ n(doc.stripe.legacy) }}</strong> legacy source (<code>src_</code>/<code>card_</code>)</li>
            <li><strong>{{ n(doc.stripe.missing) }}</strong> no usable token</li>
            <li v-if="doc.stripe.unrecognised">
              <strong>{{ n(doc.stripe.unrecognised) }}</strong> token nobody recognises
            </li>
            <li><strong>{{ n(doc.stripe.remote_schedule) }}</strong> with a remote Stripe schedule</li>
          </ul>
          <p class="description">
            A legacy source is not a modern payment method with an older prefix. The expected fix is a
            customer payment-method update, not a hopeful copy into FluentCart.
          </p>
        </div>

        <!-- ── PayPal ─────────────────────────────────────────────────── -->
        <div class="cartshift-audit-panel" data-paypal-split>
          <h2>PayPal &middot; {{ n(doc.paypal.total) }}</h2>
          <ul class="cartshift-audit-facts">
            <li><strong>{{ n(doc.paypal.system) }}</strong> verified target system charge</li>
            <li><strong>{{ n(doc.paypal.automatic) }}</strong> verified remote (automatic) schedule</li>
            <li><strong>{{ n(doc.paypal.manual_confirmation) }}</strong> manual, awaiting confirmation</li>
            <li><strong>{{ n(doc.paypal.manual_accepted) }}</strong> manual, accepted</li>
            <li><strong>{{ n(doc.paypal.blocked) }}</strong> blocked</li>
          </ul>
        </div>

        <!-- ── Schedule ───────────────────────────────────────────────── -->
        <div class="cartshift-audit-panel" data-schedule>
          <h2>Schedule</h2>
          <ul class="cartshift-audit-facts">
            <li><strong>{{ n(doc.schedule.next_payment_missing) }}</strong> with no next payment date</li>
            <li><strong>{{ n(doc.schedule.next_payment_past) }}</strong> with a past next payment date</li>
            <li><strong>{{ n(doc.schedule.next_payment_future) }}</strong> with a future next payment date</li>
          </ul>
          <p v-if="doc.schedule.active_next_date_past" class="cartshift-audit-warn">
            {{ n(doc.schedule.active_next_date_past) }} active with a date already passed:
            <code v-for="ref in doc.schedule.active_next_date_past_refs" :key="ref">{{ ref }}</code>
          </p>
          <p v-if="doc.schedule.active_next_date_missing" class="cartshift-audit-warn">
            {{ n(doc.schedule.active_next_date_missing) }} active with no date at all:
            <code v-for="ref in doc.schedule.active_next_date_missing_refs" :key="ref">{{ ref }}</code>
          </p>
        </div>

        <!-- ── History ────────────────────────────────────────────────── -->
        <div class="cartshift-audit-panel" data-history>
          <h2>Renewal history</h2>
          <p v-if="!doc.history.mismatches">
            Every subscription's payment count agrees with the paid orders included.
          </p>
          <template v-else>
            <p class="cartshift-audit-warn">
              {{ n(doc.history.mismatches) }} disagree with the history included.
            </p>
            <table class="widefat striped cartshift-table-audit-history">
              <thead>
                <tr><th>Subscription</th><th>Source says</th><th>Included</th></tr>
              </thead>
              <tbody>
                <tr v-for="row in doc.history.records" :key="row.source_ref">
                  <td><code>{{ row.source_ref }}</code></td>
                  <td>{{ n(row.source_payment_count) }}</td>
                  <td>{{ n(row.included_paid_orders) }}</td>
                </tr>
              </tbody>
            </table>
          </template>
          <p class="description">{{ doc.history.note }}</p>
        </div>
      </div>

      <!-- ── Target ─────────────────────────────────────────────────────── -->
      <div class="cartshift-audit-panel" data-target>
        <h2>This FluentCart install</h2>
        <ul class="cartshift-audit-facts">
          <li v-for="(value, key) in doc.target.subscription_settings" :key="key">
            <strong>{{ key }}</strong> <code>{{ display(value) }}</code>
          </li>
        </ul>

        <h3>Existing subscription census</h3>
        <ul class="cartshift-audit-facts">
          <li v-for="(value, key) in flatCensus" :key="key">
            <strong>{{ key }}</strong> <code>{{ display(value) }}</code>
          </li>
        </ul>

        <h3>Gateway capability</h3>
        <ul class="cartshift-audit-facts">
          <li v-for="(capability, gateway) in doc.target.capabilities" :key="gateway">
            <strong>{{ gateway }}</strong>
            registered: <code>{{ display(capability.registered) }}</code>,
            collection method: <code>{{ capability.collection_method }}</code>
            <span v-if="capability.reason_codes && capability.reason_codes.length">
              &mdash; <code v-for="code in capability.reason_codes" :key="code">{{ code }}</code>
            </span>
          </li>
        </ul>

        <h3>Approval fingerprint</h3>
        <p><code class="cartshift-audit-fingerprint">{{ doc.target.approval_fingerprint }}</code></p>
        <p class="description">{{ doc.target.note }}</p>
      </div>

      <!-- ── Mapping ────────────────────────────────────────────────────── -->
      <div class="cartshift-audit-panel" data-mapping>
        <h2>Products and variations</h2>
        <p>
          <button type="button" class="button" data-goto-map @click="openMapping(null)">
            Open the mapping screen
          </button>
          <span class="description">
            {{ n(doc.mapping.mapped) }} of {{ n(doc.mapping.source_products.length) }} source products
            resolve to a FluentCart variation.
          </span>
        </p>
        <table class="widefat striped cartshift-table-audit-mapping">
          <thead>
            <tr>
              <th>Source product</th><th>Subscriptions</th><th>Cadence</th>
              <th>FluentCart product</th><th>FluentCart variation</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="product in doc.mapping.source_products" :key="product.source_product_id">
              <td>
                <strong>{{ product.name || '(unnamed)' }}</strong>
                <span class="description">#{{ product.source_product_id }}</span>
              </td>
              <td>{{ n(product.subscriptions) }}</td>
              <td>{{ product.cadences.join(', ') || '&mdash;' }}</td>
              <td>{{ product.target_product_id ?? 'not mapped' }}</td>
              <td>
                {{ product.target_variation_id ?? 'not mapped' }}
                <button
                  v-if="!product.mapped"
                  type="button"
                  class="button-link"
                  data-goto-map
                  @click="openMapping(product.source_product_id, product.name)"
                >
                  Map it
                </button>
              </td>
            </tr>
          </tbody>
        </table>
        <!-- Named, not counted. "1 variation is claimed by more than one
             source" cannot tell the benign opted-in case from the
             monthly/yearly case §7.3 says must resolve to distinct
             variations — and the claimants were already in the payload. -->
        <template v-if="doc.mapping.shared_target_variations.length">
          <p class="cartshift-audit-warn">
            {{ n(doc.mapping.shared_target_variations.length) }} FluentCart variation(s) are claimed by
            more than one source product.
          </p>
          <ul class="cartshift-audit-facts" data-shared-targets>
            <li
              v-for="shared in doc.mapping.shared_target_variations"
              :key="shared.target_variation_id"
              :data-shared-target="shared.target_variation_id"
            >
              <strong>FluentCart variation {{ shared.target_variation_id }}</strong>
              claimed by
              <span
                v-for="claimant in shared.claimants"
                :key="`${claimant.wc_id}-${claimant.source_variation_id}`"
              >
                <code>product {{ claimant.wc_id }} / variation {{ claimant.source_variation_id }}</code>
                <span :data-opted-in="String(claimant.allow_shared_target)">
                  {{ claimant.allow_shared_target ? '(sharing allowed)' : '(sharing NOT allowed)' }}
                </span>
              </span>
              <button
                type="button"
                class="button-link"
                data-goto-map
                @click="openMapping(shared.claimants[0].wc_id)"
              >
                Re-decide on the mapping screen
              </button>
            </li>
          </ul>
          <p class="description">
            Sharing one variation is allowed only when the billing contracts match and every decision
            opts in. This screen reports the claim; the mapping screen has the source products and is
            what judges it.
          </p>
        </template>
        <p class="description">{{ doc.mapping.note }}</p>
      </div>

      <!-- ── Reason codes ───────────────────────────────────────────────── -->
      <div
        v-for="group in reasonGroups"
        :key="group.severity"
        class="cartshift-audit-panel"
        :data-reasons="group.severity"
      >
        <h2>{{ group.title }}</h2>
        <p v-if="!group.reasons.length" class="description">None.</p>
        <table v-else class="widefat striped cartshift-table-audit-reasons">
          <thead>
            <tr><th>Code</th><th>Records</th><th>Affected</th></tr>
          </thead>
          <tbody>
            <tr
              v-for="reason in group.reasons"
              :key="reason.code"
              :data-reason="reason.code"
              :data-expected="String(reason.expected)"
            >
              <td>
                <code>{{ reason.code }}</code>
                <span v-if="reason.nested_in" class="description">
                  reported inside <code>{{ reason.nested_in }}</code>
                </span>
                <span v-if="reason.expected" class="description">
                  Expected: every record takes the product's subscription length because none records
                  its own. Not a problem to fix.
                </span>
                <span v-else-if="reason.known" class="description">{{ reason.hint }}</span>
              </td>
              <td>
                <button
                  type="button"
                  class="button-link"
                  :data-filter-code="reason.code"
                  @click="filterByCode(reason.code)"
                >
                  {{ n(reason.count) }}
                </button>
              </td>
              <td>
                <code v-for="ref in reason.source_refs" :key="ref">{{ ref }}</code>
                <span v-if="reason.truncated" class="description">
                  and {{ n(reason.source_ref_total - reason.source_refs.length) }} more &mdash; use the
                  count to list them all
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- ── Records ────────────────────────────────────────────────────── -->
      <div class="cartshift-audit-panel" data-records>
        <h2>Subscriptions</h2>
        <p>
          <span class="description">
            Showing {{ n(state.records.records.length) }} of {{ n(state.records.filtered) }}
            <template v-if="state.records.filtered !== state.records.total">
              (filtered from {{ n(state.records.total) }})
            </template>
          </span>
          <button
            v-if="state.filters.code || state.filters.outcome"
            type="button"
            class="button"
            data-clear-filters
            @click="clearFilters()"
          >
            Clear filters
          </button>
        </p>

        <table class="widefat striped cartshift-table-audit-records">
          <thead>
            <tr>
              <th>Subscription</th><th>Status</th><th>Cadence</th><th>Strategy</th>
              <th>Outcome</th><th>Reasons</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="record in state.records.records" :key="record.source_ref">
              <td><code>{{ record.source_ref }}</code></td>
              <td>{{ record.status || '&mdash;' }}</td>
              <td>{{ record.cadence || '&mdash;' }}</td>
              <td>{{ record.strategy || '&mdash;' }}</td>
              <td>{{ record.outcome }}</td>
              <td>
                <code v-for="code in record.reason_codes" :key="code">{{ code }}</code>
                <button
                  v-if="record.mapping.needs_mapping && record.mapping.source_product_id"
                  type="button"
                  class="button-link"
                  data-goto-map
                  @click="openMapping(record.mapping.source_product_id)"
                >
                  Map product #{{ record.mapping.source_product_id }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>

        <p v-if="pageCount > 1">
          <button type="button" class="button" :disabled="state.records.page <= 1" @click="prev">
            Previous
          </button>
          <span class="description">Page {{ state.records.page }} of {{ pageCount }}</span>
          <button
            type="button"
            class="button"
            :disabled="state.records.page >= pageCount"
            @click="next"
          >
            Next
          </button>
        </p>
      </div>
    </template>

    <p class="cartshift-audit-actions">
      <button type="button" class="button" data-action="re-audit" @click="load()">
        Run the audit again
      </button>
      <button type="button" class="button" @click="actions.goToScreen('select')">Back</button>
    </p>
  </div>
</template>

<script setup>
import { computed, h, inject, onMounted } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import { useSubscriptionAudit } from '@/composables/useSubscriptionAudit.js';

const { actions } = inject('migration');

const {
  state,
  load,
  goToPage,
  filterByCode,
  filterByOutcome,
  clearFilters,
  preparePackage,
} = useSubscriptionAudit();

const doc = computed(() => state.document);

/**
 * The statement is the server's, not the screen's.
 *
 * A second copy written here would be one more place for the promise and the
 * behaviour to drift apart, and this particular promise is the whole mode.
 */
const zeroWriteStatement = computed(
  () =>
    doc.value?.writes?.statement ||
    'This mode writes nothing: no FluentCart row, no CartShift ID-map row, no option, no transient.'
);

const dryRunNote = computed(() => doc.value?.writes?.dry_run_note || '');

const sourceLabel = computed(() =>
  doc.value?.source?.mode === 'package' ? 'package (live source not read)' : 'live WooCommerce runtime'
);

/**
 * Three groups, in the order somebody acts on them.
 *
 * Notes are last and separate for a reason that is specific rather than
 * tidy-minded: every record in the reference dataset carries the §9.2
 * product-fallback warning, so filing them with the blockers would report 564
 * problems where there are none.
 */
const GROUPS = [
  { severity: 'blocking', title: 'Blocking — nothing migrates until these are fixed' },
  { severity: 'confirmation', title: 'Awaiting a decision' },
  { severity: 'warning', title: 'Notes' },
];

const reasonGroups = computed(() =>
  GROUPS.map((group) => ({
    ...group,
    reasons: (doc.value?.reasons || []).filter((reason) => reason.severity === group.severity),
  }))
);

/**
 * The census is nested one level (totals plus per-status counts), and the
 * screen shows it rather than summarising it: the operator is comparing it
 * against what they expect the store to hold before approving a store-wide
 * policy fingerprint, and a summary is exactly the wrong shape for that.
 */
const flatCensus = computed(() => {
  const census = doc.value?.target?.subscription_census || {};
  const flat = {};

  for (const [key, value] of Object.entries(census)) {
    if (value && typeof value === 'object') {
      for (const [inner, count] of Object.entries(value)) {
        flat[`${key}.${inner}`] = count;
      }
    } else {
      flat[key] = value;
    }
  }

  return flat;
});

/**
 * The three readiness counts, in the order somebody reads them, each one a
 * filter over the record list below.
 */
const outcomeSlots = computed(() => [
  { outcome: 'ready', label: 'Ready', count: doc.value?.totals?.ready ?? 0 },
  {
    outcome: 'confirmation_required',
    label: 'Awaiting confirmation',
    count: doc.value?.totals?.confirmation_required ?? 0,
  },
  { outcome: 'blocked', label: 'Blocked', count: doc.value?.totals?.blocked ?? 0 },
]);

/** Pressing the active filter again clears it, which is what a toggle means. */
function toggleOutcome(outcome) {
  filterByOutcome(state.filters.outcome === outcome ? '' : outcome);
}

/**
 * Switch which source the NEXT audit reads. The current reading stays put.
 *
 * `live` re-audits immediately because it needs nothing else. `package` cannot:
 * there is no path yet, and auditing an empty one would only produce a refusal
 * — so the live reading remains on screen until the operator supplies a file
 * and presses Audit. That is deliberate (losing a good reading because somebody
 * clicked a radio would be worse), and it is also why `sourceMismatch` below
 * exists: the figures underneath belong to the mode they were read in, not to
 * the mode the picker is showing.
 */
function chooseSource(mode) {
  state.source = mode;

  if (mode === 'live') {
    load();
  }
}

/**
 * True while the picker and the rendered document disagree.
 *
 * `[data-source]` already reports the displayed document's real mode, so
 * nothing on screen is false — but an operator who has just switched the picker
 * is looking for the change and will read the totals as the answer.
 */
const sourceMismatch = computed(() => !!doc.value && doc.value.source.mode !== state.source);

/**
 * Send the operator to the mapping screen with the row they came for.
 *
 * `goToScreen('map')` alone dropped them on a catalogue with the product ID
 * left behind — the same complaint the stale-variation refusal message had,
 * and the payload has carried `wc_id` since.
 */
function openMapping(wcId, name = '') {
  if (wcId && actions.goToMapping) {
    actions.goToMapping({ wc_id: wcId, name });
    return;
  }

  actions.goToScreen('map');
}

const pageCount = computed(() =>
  Math.max(1, Math.ceil((state.records.filtered || 0) / (state.records.per_page || 25)))
);

function prev() {
  goToPage(state.records.page - 1);
}

function next() {
  goToPage(state.records.page + 1);
}

function n(value) {
  const number = Number(value);

  return Number.isFinite(number) ? number.toLocaleString() : '0';
}

function short(fingerprint) {
  return typeof fingerprint === 'string' && fingerprint.length > 16
    ? `${fingerprint.slice(0, 16)}…`
    : fingerprint || '';
}

function display(value) {
  if (value === null || value === undefined) return 'not set';
  if (typeof value === 'boolean') return value ? 'yes' : 'no';
  if (typeof value === 'object') return JSON.stringify(value);

  return String(value);
}

/**
 * A small presentational list, defined here because it exists only for this
 * screen and a separate file for four lines of render function is filing, not
 * design.
 */
const TallyList = (props) =>
  h('div', { class: 'cartshift-audit-tally' }, [
    h('h3', props.label),
    h(
      'ul',
      { class: 'cartshift-audit-facts' },
      Object.entries(props.tally || {}).map(([key, count]) =>
        h('li', { key }, [h('strong', n(count)), ' ', key])
      )
    ),
  ]);

TallyList.props = ['label', 'tally'];

onMounted(() => load());
</script>
