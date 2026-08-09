import { reactive, computed } from 'vue';
import { useApi } from '@/composables/useApi.js';

export const BANDS = ['strong', 'likely', 'weak', 'none'];

// MappingController::MAX_PER_PAGE. Asking for more is silently clamped there,
// so asking for exactly it is the fewest round trips the server will allow.
const PER_PAGE = 200;

// 20,000 products, at which point the screen has bigger problems than paging.
// A bound rather than a `while (true)`: a server that kept answering with rows
// would otherwise spin the browser for ever.
const MAX_PAGES = 100;

// The manual catalogue search is a page the owner reads, not a list the screen
// walks to completion, so it is a screenful rather than PER_PAGE's 200.
const CATALOGUE_PER_PAGE = 50;

/**
 * Mapping state for the map screen.
 *
 * A fresh reactive per call rather than a module singleton like useMigration:
 * the mapping screen is entered once per run and holds the decisions for one
 * visit, and a singleton would carry a previous run's decisions into the next
 * one's UI.
 */
export function useMapping() {
  const { api } = useApi();

  const state = reactive({
    rows: [],
    loading: false,
    error: null,
    total: 0,
    fcProductCount: 0,
    pagesLoaded: 0,
    perPage: PER_PAGE,
    // 'create-rest' migrates untouched products as usual; 'only-mapped' turns
    // the decided set into a whitelist via MigrationScope's explicit mode.
    runMode: 'create-rest',
    // The manual rescue for a row ProductMatcher scored `none` — which is both
    // Lapka subscription products, and therefore the whole migration.
    catalogue: { products: [], total: 0, loading: false, error: null, query: '' },
    // What the server hashed the decision set to on the last save. Tasks 10
    // and 11 persist this into stage and cutover receipts, so the mapping the
    // owner approved and the mapping the run executes can be compared rather
    // than assumed equal.
    mappingFingerprint: null,
  });

  function rowsUrl(page, scope) {
    const query = `page=${page}&per_page=${state.perPage}`;

    if (!scope) {
      return `mapping/rows?${query}`;
    }

    // A GET has no body, and the scope is nested; one encoded JSON blob is
    // unambiguous where `scope[product_ids][]=…` is a shape both sides would
    // have to agree on by hand. MappingController::scopeFrom() takes either.
    return `mapping/rows?${query}&scope=${encodeURIComponent(JSON.stringify(scope))}`;
  }

  /**
   * Load every in-scope product, not the first page of them.
   *
   * Paging exists because the matcher is O(page x catalogue) and one request
   * for a 2,000-product shop is a million comparisons. What it cannot be is
   * *visible* paging: the band headers count what is loaded, and a "Link all
   * 19 in this band" button that acts on nineteen of the forty-one rows in
   * that band is a button that lies. So the pages are walked here, and the
   * screen still sees one list.
   *
   * @param {Object|null} scope Wire-shaped MigrationScope, from serializeScope().
   */
  async function loadRows(scope = null) {
    state.loading = true;
    state.error = null;
    state.rows = [];
    state.pagesLoaded = 0;

    try {
      for (let page = 1; page <= MAX_PAGES; page += 1) {
        const data = await api('GET', rowsUrl(page, scope));
        const rows = data.rows || [];

        state.rows = state.rows.concat(rows);
        state.total = data.total || 0;
        state.fcProductCount = data.fc_product_count || 0;
        state.pagesLoaded = page;

        if (rows.length === 0 || state.rows.length >= state.total) {
          break;
        }
      }
    } catch (err) {
      state.error = err.message;
    } finally {
      state.loading = false;
    }
  }

  function bandRows(band) {
    return state.rows.filter((row) => row.band === band);
  }

  /**
   * Every FluentCart product, searchable.
   *
   * `band=none` means "nothing here looks like your product", not "your
   * product cannot be mapped". The row list drops `none` candidates on purpose
   * — eight implausible products under a "No candidate" heading is an
   * invitation to fuse a Gift Card with a T-shirt — so this is the other half
   * of that decision rather than a contradiction of it.
   *
   * @param {string} query Free text; empty means the first page of everything.
   * @param {number} page
   */
  async function searchCatalogue(query = '', page = 1) {
    state.catalogue.loading = true;
    state.catalogue.error = null;
    state.catalogue.query = query;

    const suffix = query ? `&q=${encodeURIComponent(query)}` : '';

    try {
      const data = await api('GET', `mapping/catalogue?page=${page}&per_page=${CATALOGUE_PER_PAGE}${suffix}`);

      state.catalogue.products = data.products || [];
      state.catalogue.total = data.total || 0;
    } catch (err) {
      state.catalogue.error = err.message;
    } finally {
      state.catalogue.loading = false;
    }
  }

  /**
   * Point a row at a product the matcher never offered.
   *
   * The variant block comes back from the server rather than being assembled
   * from the catalogue listing here. A subscription's variation is a billing
   * contract, and deciding it in the browser would be a second copy of the
   * cadence gate — one that would agree with the real one right up until
   * somebody edited a repeat interval.
   */
  async function chooseProduct(row, fcPostId) {
    try {
      const data = await api('GET', `mapping/variants?wc_id=${row.wc_id}&fc_post_id=${fcPostId}`);

      const candidate = {
        id: fcPostId,
        label: data.label,
        band: 'none',
        score: 0,
        variant: data.variant,
        downloads_lost: false,
      };

      row.candidates = [
        ...(row.candidates || []).filter((entry) => entry.id !== fcPostId),
        candidate,
      ];

      chooseCandidate(row, fcPostId);
    } catch (err) {
      state.error = err.message;
    }
  }

  /**
   * Put one source variation on one target variation, by hand.
   *
   * Refused rather than corrected when the contract does not fit: the server
   * would refuse it too, and a UI that silently moved the owner to a different
   * variation would be choosing between "billed monthly" and "billed yearly"
   * on their behalf without saying so.
   */
  function chooseVariation(row, sourceVariationId, targetVariationId) {
    const source = (row.variant?.sources || []).find((entry) => entry.id === sourceVariationId);

    if (!source) {
      return;
    }

    const option = (source.options || []).find((entry) => entry.id === targetVariationId);

    if (!option || !option.compatible) {
      return;
    }

    source.selected = targetVariationId;
    row.variant.map = { ...row.variant.map, [sourceVariationId]: targetVariationId };
  }

  /**
   * Point a row at a different FluentCart product.
   *
   * The variant block moves with it, and that is the whole reason this is a
   * function rather than an assignment in the template. FluentCart variation
   * IDs are global in fct_product_variations, so leaving the previous
   * candidate's map in place would save ENTITY_VARIATION rows aimed at
   * another product's variants — and the "4/4 variants matched" label would
   * keep quoting the old number while it happened.
   */
  function chooseCandidate(row, fcPostId) {
    const candidate = (row.candidates || []).find((entry) => entry.id === fcPostId);

    row.suggested = candidate ? candidate.id : null;
    row.variant = candidate ? candidate.variant || null : null;
    // Whether the Woo product's files survive the link depends entirely on
    // which FluentCart product is on the other end of it, so this moves with
    // the candidate for the same reason the variant block does.
    row.downloads_lost = candidate ? !!candidate.downloads_lost : false;
  }

  /**
   * The payload fragment a link needs. Null when the row has nothing to link
   * to, which is how bulk() drops candidate-less rows without special-casing.
   */
  function linkPayload(row) {
    if (!row.suggested) {
      return null;
    }

    return {
      fc_post_id: row.suggested,
      variant_map: row.variant ? row.variant.map : {},
      // Carried back so promotion can create the variants this link is missing.
      // Dropping them here is how "adds XL" becomes a line item pointing at
      // nothing three screens later.
      orphans: row.variant ? row.variant.orphans || [] : [],
      // The owner's explicit "yes, another product may already use this
      // variation". Always sent, never inferred: absent would be indistinguishable
      // from a client that predates the control.
      allow_shared_target: !!row.allow_shared_target,
    };
  }

  async function decide(row, decision) {
    const body = {
      wc_id: row.wc_id,
      wc_type: row.wc_type,
      decision,
      band: row.band,
    };

    if (decision === 'link') {
      const link = linkPayload(row);

      if (!link) {
        return;
      }

      Object.assign(body, link);
    }

    try {
      const data = await api('POST', 'mapping/decide', body);
      row.decision = data.decision;
      state.mappingFingerprint = data.mapping_fingerprint || null;
    } catch (err) {
      state.error = err.message;
    }
  }

  /**
   * Apply one decision to every undecided row in a band.
   *
   * Undecided only: a row the owner already ruled on individually is theirs,
   * and a later bulk press on its band must not overwrite it.
   */
  async function bulk(band, decision) {
    const targets = bandRows(band).filter((row) => !row.decision);

    const rows = [];

    targets.forEach((row) => {
      const payload = { wc_id: row.wc_id, wc_type: row.wc_type };

      if (decision === 'link') {
        const link = linkPayload(row);

        if (!link) {
          return;
        }

        Object.assign(payload, link);
      }

      rows.push(payload);
    });

    try {
      const data = await api('POST', 'mapping/bulk', { band, decision, rows });

      // The server's own decisions, exactly as decide() adopts them. This used
      // to synthesise `{decision, fc_post_id}` locally, so a row's `decision`
      // meant one thing after a bulk press and another after a per-row one —
      // and anything reading `decision.variant_map` saw undefined on half of them.
      const saved = new Map((data.decisions || []).map((entry) => [entry.wc_id, entry]));

      state.rows.forEach((row) => {
        if (saved.has(row.wc_id)) {
          row.decision = saved.get(row.wc_id);
        }
      });

      state.mappingFingerprint = data.mapping_fingerprint || null;
    } catch (err) {
      state.error = err.message;
    }
  }

  async function clearAll() {
    try {
      await api('POST', 'mapping/clear', {});
      state.rows.forEach((row) => {
        row.decision = null;
      });
    } catch (err) {
      state.error = err.message;
    }
  }

  /**
   * The products "Migrate only what I mapped" is a whitelist of.
   *
   * Every row the owner ruled on except the ones they ruled out. `create` is
   * in deliberately: the spec says a per-row decision always overrides the run
   * mode, and a product they pressed Create on being silently dropped is the
   * opposite of that. `skip` is out, and is also promoted into the migrator's
   * exclusion list, so it is refused twice over.
   */
  function whitelistedProductIds() {
    return state.rows
      .filter((row) => row.decision && row.decision.decision !== 'skip')
      .map((row) => row.wc_id);
  }

  /**
   * Whether "only what I mapped" can be offered for this scope at all.
   *
   * It cannot compose with "everything from a date". MigrationScope has three
   * mutually exclusive modes, and `fromArray()` nulls `since` outside `since`
   * mode and empties `product_ids` outside `explicit` mode — so whichever mode
   * wins, one of the two restrictions is discarded. Rewriting a `since` scope
   * to `explicit` discards the cutoff, which hands the owner the entire order
   * history for the products they mapped: the widening direction, and the
   * opposite of what they asked for.
   *
   * Refused rather than resolved. Resolving it means teaching MigrationScope
   * and every ScopeResolver predicate to carry a date *and* an id list, which
   * is a change to the scope model, not to this screen.
   *
   * @param {Object} scope The wizard's shared scope.
   */
  function runModeAvailable(scope) {
    return scope?.mode !== 'since';
  }

  /**
   * Hand the wizard's shared scope whatever the run mode implies.
   *
   * 'create-rest' is CartShift's existing behaviour and touches nothing.
   * 'only-mapped' is MigrationScope::MODE_EXPLICIT over the decided set —
   * existing machinery, no second filter mechanism, exactly as the design
   * spec asks.
   *
   * `includeOrdersForProducts` is not optional here. ScopeResolver builds an
   * explicit run's order set from picked customers *or* the orders containing
   * picked products, and with the second half switched off a product-only
   * scope selects no orders at all — so "migrate only what I mapped" would
   * migrate the products and leave eight years of history behind, which is
   * the one outcome this whole feature exists to prevent.
   *
   * A `since` scope is left exactly as found. The screen does not offer the
   * control in that mode, and this refuses it a second time rather than
   * trusting that — widening a run the owner deliberately narrowed to a date
   * is not a thing to leave resting on a `:disabled` binding.
   *
   * @param {Object} migrationState The wizard's shared state (App.vue's provide).
   */
  function applyRunMode(migrationState) {
    if (state.runMode !== 'only-mapped' || !runModeAvailable(migrationState.scope)) {
      return;
    }

    migrationState.scope.mode = 'explicit';
    migrationState.scope.products = whitelistedProductIds().map((id) => ({ id }));
    migrationState.scope.includeOrdersForProducts = true;
  }

  const summary = computed(() => ({
    decided: state.rows.filter((row) => row.decision).length,
    // What is on screen and actionable, kept apart from the catalogue total so
    // a load that stopped early reads as "247 of 300" rather than claiming the
    // owner has decided on rows they have never seen.
    loaded: state.rows.length,
    total: state.total,
    complete: state.rows.length >= state.total,
    fcProductCount: state.fcProductCount,
  }));

  return {
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
    whitelistedProductIds,
    bandRows,
    summary,
    BANDS,
  };
}
