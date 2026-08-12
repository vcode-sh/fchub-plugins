import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { ref } from 'vue';

const { apiMock } = vi.hoisted(() => ({ apiMock: vi.fn() }));

vi.mock('@/composables/useApi.js', () => ({ useApi: () => ({ api: apiMock }) }));

const { default: GuidedMigrationScreen } = await import('@/components/GuidedMigrationScreen.vue');

function mountScreen(options = {}) {
  return mount(GuidedMigrationScreen, {
    ...options,
    global: { provide: { config: {}, theme: { themeMode: ref('light'), changeTheme: vi.fn() } } },
  });
}

/**
 * The shape `GuidedMigrationController::status()` really returns.
 *
 * `wc_subscriptions` is severity `pass` with `active: false` when the add-on is
 * absent — the product rule in its actual serialised form. A fixture that made
 * it `fail` would let the screen pass a test it should not.
 */
function status(overrides = {}) {
  return {
    guided_available: true,
    initialised: true,
    preflight: {
      ready: true,
      checks: {
        woocommerce: { label: 'WooCommerce', severity: 'pass', message: 'WooCommerce 11.0.1 is active.' },
        order_storage: { label: 'Order Storage (HPOS)', severity: 'pass', message: 'HPOS is enabled.' },
        wc_subscriptions: {
          label: 'WooCommerce Subscriptions',
          severity: 'pass',
          message: 'WC Subscriptions not detected. Subscription migration will be skipped.',
        },
      },
    },
    subscriptions: { available: false },
    setup: {
      complete: true,
      missing: [],
      cutover: {
        available: false,
        message: 'Cutover remains unavailable until CartShift can roll back a completed rehearsal.',
      },
    },
    plan: [
      { label: 'Check source compatibility', completed: false },
      { label: 'Prepare target records', completed: false },
    ],
    plan_blocked: null,
    plan_message: null,
    run: null,
    ...overrides,
  };
}

function serve(payload) {
  let startIndex = 0;
  apiMock.mockImplementation(async (method, endpoint) => {
    if (method === 'GET' && endpoint.startsWith('migration/status')) {
      return payload;
    }
    if (method === 'POST' && endpoint === 'migration/initialise') {
      if (payload.initialiseError) throw new Error('setup failed');
      return { initialised: true };
    }
    if (method === 'POST' && endpoint === 'migration/decisions') {
      return payload.acceptResult || {
        accepted: 27,
        run: { phase: 'completed', completed_steps: 12, total_steps: 12, last_step: 'Finishing the rehearsal' },
      };
    }
    if (method === 'POST' && endpoint === 'migration/start') {
      if (payload.startResults) return payload.startResults[startIndex++];
      return (
        payload.startResult || {
          phase: 'awaiting_decisions',
          completed_steps: 3,
          total_steps: 12,
          last_step: 'Review migration decisions',
          review: { blockers: [], items: [], proposal_counts: {} },
        }
      );
    }
    if (method === 'POST' && endpoint === 'migration/cancel') {
      return { phase: 'cancelled', completed_steps: 3, total_steps: 12, last_step: 'propose-decisions' };
    }
    if (method === 'POST' && endpoint === 'migration/rollback') {
      return { phase: 'rolled_back', completed_steps: 7, total_steps: 12, last_step: 'rollback' };
    }
    throw new Error(`Unexpected request: ${method} ${endpoint}`);
  });
}

// Block body, not an arrow returning the mock. `beforeEach(() => x.mockReset())`
// hands Vitest the mock as a return value and the implementation installed by
// `serve()` afterwards never took effect — every test failed with a request
// carrying no arguments at all.
beforeEach(() => {
  apiMock.mockReset();
});

describe('GuidedMigrationScreen', () => {
  it('keeps each journey label together so the connector begins after its copy', async () => {
    serve(status());
    const wrapper = mountScreen();
    await flushPromises();

    const steps = wrapper.findAll('.cartshift-journey li');

    expect(steps).toHaveLength(3);
    expect(steps[0].find('.cartshift-step-copy').text()).toBe('CheckStore readiness');
    expect(steps[1].find('.cartshift-step-copy').text()).toBe('ReviewYour choices');
    expect(steps[2].find('.cartshift-step-copy').text()).toBe('MoveSafe migration');
  });

  it('asks for the shop once on mount, without the expensive audit', async () => {
    serve(status());
    mountScreen();
    await flushPromises();

    expect(apiMock.mock.calls).toEqual([['GET', 'migration/status']]);
  });

  /**
   * The rule the whole workstream exists for. A shop without the optional add-on
   * is a shop that migrates everything else.
   */
  it('treats a missing WooCommerce Subscriptions as skipped, never as blocked', async () => {
    serve(status());
    const wrapper = mountScreen();
    await flushPromises();

    expect(wrapper.find('[data-test="wcs-skipped"]').exists()).toBe(true);
    expect(wrapper.find('[data-test="preflight-ready"]').exists()).toBe(true);
    expect(wrapper.find('[data-test="preflight-blocked"]').exists()).toBe(false);
    expect(wrapper.text()).not.toContain('cannot migrate');
  });

  it('still reports a genuine blocker', async () => {
    serve(
      status({
        preflight: {
          ready: false,
          checks: {
            order_storage: { label: 'Order Storage (HPOS)', severity: 'fail', message: 'HPOS is off.' },
            wc_subscriptions: { label: 'WooCommerce Subscriptions', severity: 'pass', active: false, message: 'Skipped.' },
          },
        },
      })
    );
    const wrapper = mountScreen();
    await flushPromises();

    expect(wrapper.find('[data-test="preflight-blocked"]').exists()).toBe(true);
    expect(wrapper.text()).toContain('HPOS is off.');
  });

  it('keeps non-blocking loss warnings visible without adding another primary action', async () => {
    const payload = status();
    payload.preflight.checks.product_types = {
      label: 'Unsupported product types',
      severity: 'warn',
      message: '41 orders contain product types that need review.',
    };
    serve(payload);
    const wrapper = mountScreen();
    await flushPromises();

    expect(wrapper.find('[data-test="preflight-warnings"]').text()).toContain('41 orders');
    expect(wrapper.find('[data-test="preflight-warnings"]').attributes('open')).toBeUndefined();
    expect(wrapper.find('[data-test="preflight-ready"]').text()).toContain('No blocking');
    expect(wrapper.findAll('.button-primary')).toHaveLength(1);
  });

  it('finishes private setup automatically without exposing server instructions', async () => {
    serve(
      status({
        setup: {
          complete: false,
          cutover: { available: false, message: 'Cutover is not ready yet.' },
        },
      })
    );
    const wrapper = mountScreen();
    await flushPromises();

    expect(wrapper.text()).not.toContain('wp-config.php');
    expect(wrapper.text()).not.toContain('CARTSHIFT_TRANSFER_');
    expect(wrapper.find('[data-test="setup-copy"]').exists()).toBe(false);
    expect(wrapper.findAll('.button-primary')).toHaveLength(1);
    expect(wrapper.find('[data-test="check-store"]').text()).toContain('Check my store');

    await wrapper.find('[data-test="check-store"]').trigger('click');
    await flushPromises();

    expect(apiMock.mock.calls).toContainEqual(['POST', 'migration/initialise']);
  });

  it('introduces the migration in plain language and prepares the site only when asked', async () => {
    serve(status({ initialised: false, message: 'This site has not been named for transfer yet.' }));
    const wrapper = mountScreen();
    await flushPromises();

    expect(apiMock.mock.calls.every(([method]) => method === 'GET')).toBe(true);
    expect(wrapper.find('[data-test="readiness-hero"]').text()).toContain('Ready to check your store?');
    expect(wrapper.text()).not.toContain('named for transfer');
    expect(wrapper.text()).not.toContain('transfer identity');

    await wrapper.find('[data-test="check-store"]').trigger('click');
    await flushPromises();

    expect(apiMock.mock.calls).toContainEqual(['POST', 'migration/initialise']);
  });

  it('keeps the clear setup action visible when private preparation fails', async () => {
    const payload = status({ initialised: false, message: 'CartShift is ready to check this store.' });
    payload.initialiseError = true;
    serve(payload);
    const wrapper = mountScreen();
    await flushPromises();

    await wrapper.find('[data-test="check-store"]').trigger('click');
    await flushPromises();

    expect(wrapper.find('[role="alert"]').text()).toContain('private migration workspace');
    expect(wrapper.find('[data-test="check-store"]').exists()).toBe(true);
  });

  it('offers one obvious primary action for the readiness flow', async () => {
    serve(status());
    const wrapper = mountScreen();
    await flushPromises();

    expect(wrapper.findAll('.button-primary')).toHaveLength(1);
    expect(wrapper.find('[data-test="start"]').text()).toContain('Review my store');
    expect(wrapper.find('[data-test="readiness-hero"]').text()).toContain('Ready for review');
    expect(wrapper.text()).not.toContain('Inspect the source first');
  });

  it('turns blockers into one clear next step and keeps warnings secondary', async () => {
    const payload = status({
      preflight: {
        ready: false,
        checks: {
          fc_data: {
            label: 'Existing FluentCart records',
            severity: 'fail',
            message: 'Remove test records in FluentCart, then check again.',
          },
          product_types: {
            label: 'Product types',
            severity: 'warn',
            message: 'Two products will need manual review after migration.',
          },
        },
      },
    });
    serve(payload);
    const wrapper = mountScreen();
    await flushPromises();

    expect(wrapper.find('[data-test="readiness-hero"]').text()).toContain('Your store needs attention');
    expect(wrapper.find('[data-test="blocking-checks"]').text()).toContain('Remove test records');
    expect(wrapper.find('[data-test="preflight-warnings"]').text()).toContain('Worth knowing');
    expect(wrapper.findAll('.button-primary')).toHaveLength(1);
    expect(wrapper.find('[data-test="check-again"]').text()).toContain('Check again');
  });

  it('shows known blockers before automatic setup instead of claiming readiness', async () => {
    serve(
      status({
        setup: { complete: false, cutover: { available: false, message: 'Cutover is not ready yet.' } },
        plan_blocked: true,
        plan_message: 'Active subscriptions need a supported migration route.',
        subscriptions: { available: true },
      })
    );
    const wrapper = mountScreen();
    await flushPromises();

    expect(wrapper.find('[data-test="readiness-hero"]').text()).toContain('Your store needs attention');
    expect(wrapper.text()).toContain('Subscriptions need a supported migration route');
    expect(wrapper.find('[data-test="check-store"]').exists()).toBe(false);
    expect(wrapper.find('[data-test="check-again"]').exists()).toBe(true);
  });

  it('starts the durable rehearsal and shows persisted progress', async () => {
    const payload = status();
    payload.startResult = {
      phase: 'failed',
      completed_steps: 7,
      total_steps: 12,
      last_step: 'Preparing target records',
      failure: {
        message: 'Target verification stopped after records were prepared.',
        can_restart: false,
      },
      review: null,
      rollback: {
        safe: true,
        deletion_count: 1,
        review_id: 'rollback-0123456789ab',
      },
    };
    serve(payload);
    const wrapper = mountScreen();
    await flushPromises();

    await wrapper.find('[data-test="start"]').trigger('click');
    await flushPromises();

    expect(apiMock.mock.calls).toContainEqual(['POST', 'migration/start']);
    expect(wrapper.find('[data-test="run-progress"]').text()).toContain('7 of 12');
    expect(wrapper.find('[data-test="run-failure"]').text()).toContain('Target verification stopped');
    expect(wrapper.text()).not.toContain('site-x');

    await wrapper.find('[data-test="rollback"]').trigger('click');
    await flushPromises();

    expect(apiMock.mock.calls).toContainEqual([
      'POST',
      'migration/rollback',
      { review_id: 'rollback-0123456789ab' },
    ]);
    expect(wrapper.find('[data-test="run-progress"]').text()).toContain('rollback');
  });

  it('shows every friendly review item and sends only opaque approvals', async () => {
    const item = {
      review_id: 'customer-0123456789ab',
      kind: 'customer_ownership',
      title: 'Ada Lovelace',
      summary: 'Attach this customer to the exact same WordPress account on this site.',
    };
    serve(
      status({
        run: {
          phase: 'awaiting_decisions',
          completed_steps: 3,
          total_steps: 12,
          last_step: 'Review migration decisions',
          review: { blockers: [], items: [item], proposal_counts: {} },
        },
      })
    );
    const wrapper = mountScreen();
    await flushPromises();

    expect(wrapper.find('[data-test="review-decision"]').text()).toContain('Ada Lovelace');
    expect(wrapper.find('[data-test="review-decision"]').text()).toContain('same WordPress account');
    expect(wrapper.find('[data-test="accept-run-decisions"]').attributes('disabled')).toBeDefined();
    await wrapper.find('[data-test="review-decision"] input').setValue(true);
    await wrapper.find('[data-test="accept-run-decisions"]').trigger('click');
    await flushPromises();

    expect(apiMock.mock.calls).toContainEqual([
      'POST',
      'migration/decisions',
      { approved_reviews: [item.review_id] },
    ]);
  });

  it('requires one clear product choice and never offers overwrite', async () => {
    const item = {
      review_id: 'product-0123456789ab',
      kind: 'product_conflict',
      title: 'Store membership',
      summary: 'A likely match already exists in FluentCart.',
      choices: [
        {
          choice_id: 'choice-111111111111',
          label: 'Use existing product',
          description: 'Use Store membership in FluentCart. It will not be changed.',
        },
        {
          choice_id: 'choice-222222222222',
          label: 'Skip this product',
          description: 'Do not migrate this WooCommerce product.',
        },
      ],
    };
    serve(
      status({
        run: {
          phase: 'awaiting_decisions',
          completed_steps: 3,
          total_steps: 12,
          last_step: 'Review migration decisions',
          review: { blockers: [], items: [item], proposal_counts: {} },
        },
      })
    );
    const wrapper = mountScreen();
    await flushPromises();

    expect(wrapper.find('[data-test="review-decision"]').text()).toContain('Use existing product');
    expect(wrapper.find('[data-test="review-decision"]').text()).toContain('Skip this product');
    expect(wrapper.text()).not.toContain('Overwrite');
    expect(wrapper.find('[data-test="accept-run-decisions"]').attributes('disabled')).toBeDefined();

    await wrapper.find('[data-test="product-choice"] input').setValue(true);
    expect(wrapper.find('[data-test="accept-run-decisions"]').attributes('disabled')).toBeUndefined();
    await wrapper.find('[data-test="accept-run-decisions"]').trigger('click');
    await flushPromises();

    expect(apiMock.mock.calls).toContainEqual([
      'POST',
      'migration/decisions',
      {
        approved_reviews: [item.review_id],
        review_answers: [{ review_id: item.review_id, choice_id: 'choice-111111111111' }],
      },
    ]);
  });

  it('explains when shop changes replace a review and focuses the new review', async () => {
    const oldItem = {
      review_id: 'decision-old00000001',
      kind: 'migration_decision',
      title: 'Order 116',
      summary: 'Keep two historical notes private.',
    };
    const newItem = { ...oldItem, review_id: 'decision-new00000002', summary: 'Keep three historical notes private.' };
    const payload = status({
      run: {
        phase: 'awaiting_decisions',
        completed_steps: 3,
        total_steps: 12,
        last_step: 'Review migration decisions',
        review: { blockers: [], items: [oldItem], proposal_counts: {} },
      },
    });
    payload.acceptResult = {
      review_changed: true,
      run: {
        ...payload.run,
        review: { blockers: [], items: [newItem], proposal_counts: {} },
      },
    };
    serve(payload);
    const wrapper = mountScreen({ attachTo: document.body });
    await flushPromises();

    await wrapper.find('[data-test="review-decision"] input').setValue(true);
    await wrapper.find('[data-test="accept-run-decisions"]').trigger('click');
    await flushPromises();

    expect(wrapper.find('[data-test="review-changed"]').text()).toContain('Nothing was recorded');
    expect(wrapper.find('[data-test="review-decision"]').text()).toContain('three historical notes');
    expect(wrapper.find('[data-test="review-decision"] input').element.checked).toBe(false);
    expect(document.activeElement).toBe(wrapper.find('[data-test="run-review"] h3').element);
    wrapper.unmount();
  });

  it('drives one persisted server step at a time until review is required', async () => {
    const payload = status();
    payload.startResults = [
      { phase: 'running', completed_steps: 1, total_steps: 12, last_step: 'Checking source compatibility' },
      { phase: 'running', completed_steps: 2, total_steps: 12, last_step: 'Checking target compatibility' },
      {
        phase: 'awaiting_decisions',
        completed_steps: 3,
        total_steps: 12,
        last_step: 'Review migration decisions',
        review: { blockers: [], items: [], proposal_counts: {} },
      },
    ];
    serve(payload);
    const wrapper = mountScreen();
    await flushPromises();

    await wrapper.find('[data-test="start"]').trigger('click');
    await flushPromises();

    expect(apiMock.mock.calls.filter(([method, endpoint]) => method === 'POST' && endpoint === 'migration/start')).toHaveLength(3);
    expect(wrapper.find('[data-test="run-review"]').exists()).toBe(true);
  });

  it('lets a cancelled review start a fresh rehearsal', async () => {
    serve(
      status({
        run: {
          phase: 'cancelled',
          completed_steps: 3,
          total_steps: 12,
          last_step: 'propose-decisions',
          review: null,
        },
      })
    );
    const wrapper = mountScreen();
    await flushPromises();

    expect(wrapper.find('[data-test="start"]').text()).toContain('Start a new check');
    await wrapper.find('[data-test="start"]').trigger('click');
    await flushPromises();

    expect(apiMock.mock.calls).toContainEqual(['POST', 'migration/start']);
  });

  it('keeps cutover in the GUI and unavailable until rollback proof exists', async () => {
    serve(status());
    const wrapper = mountScreen();
    await flushPromises();

    expect(wrapper.find('[data-test="cutover"]').text()).toContain('roll back a completed rehearsal');
    expect(wrapper.find('[data-test="cutover"]').text()).not.toContain('WP-CLI');
    expect(wrapper.text()).not.toContain('command');
  });

  it('shows the shared-stock compromise and manual choices without blocking the whole migration', async () => {
    serve(
      status({
        run: {
          phase: 'failed',
          completed_steps: 5,
          total_steps: 12,
          last_step: 'Validate the rehearsal package',
          failure: {
            message: 'This CartShift core cannot yet roll back a completed rehearsal.',
            can_restart: false,
          },
          migration_exceptions: [
            {
              title: 'Trail harness',
              variations: [
                { title: 'Harness size: Large', sku: 'HARNESS-L' },
                { title: 'Harness size: Small', sku: 'HARNESS-S' },
              ],
              source_quantity: 11,
              source_quantity_state: 'known',
              message: 'These variations will start unavailable with zero stock and backorders disabled.',
              suggestions: [
                'Allocate stock across the FluentCart variations without exceeding the original shared total.',
                'Enable only the variations you want to sell.',
                'Leave the variations unavailable until stock is confirmed.',
              ],
            },
          ],
        },
      })
    );
    const wrapper = mountScreen();
    await flushPromises();

    const report = wrapper.find('[data-test="migration-exceptions"]');
    expect(report.text()).toContain('Shared stock needs manual setup');
    expect(report.text()).toContain('Trail harness');
    expect(report.text()).toContain('Original product-wide shared quantity: 11');
    expect(report.text()).toContain('Harness size: Small');
    expect(report.text()).toContain('without exceeding the original shared total');
    expect(wrapper.find('[data-test="start"]').exists()).toBe(false);
  });

  it('asks for a physical count when WooCommerce has no usable shared quantity', async () => {
    serve(
      status({
        run: {
          phase: 'failed',
          completed_steps: 5,
          total_steps: 12,
          failure: { message: 'The safe check stopped.', can_restart: false },
          migration_exceptions: [
            {
              title: 'Mystery stock',
              variations: [{ title: 'Large', sku: '' }],
              source_quantity: null,
              source_quantity_state: 'unknown',
              target_state: 'planned',
              message: 'WooCommerce did not provide a usable stock total.',
              suggestions: ['Count the available stock before entering quantities in FluentCart.'],
            },
          ],
        },
      })
    );
    const wrapper = mountScreen();
    await flushPromises();

    const report = wrapper.find('[data-test="migration-exceptions"]');
    expect(report.text()).toContain('did not provide a shared quantity');
    expect(report.text()).toContain('Count physical stock');
    expect(report.text()).not.toContain('Original product-wide shared quantity');
  });

  it('does not present an older unsafe completion as successful progress', async () => {
    serve(
      status({
        run: {
          phase: 'unsafe_completion',
          completed_steps: 12,
          total_steps: 12,
          last_step: 'Finish the rehearsal',
          failure: {
            message: 'This older rehearsal completed without rollback proof. Cutover remains unavailable.',
            can_restart: false,
          },
        },
      })
    );
    const wrapper = mountScreen();
    await flushPromises();

    expect(wrapper.text()).not.toContain('12 of 12 steps complete');
    expect(wrapper.find('[data-test="run-failure"]').text()).toContain('without rollback proof');
    expect(wrapper.find('[data-test="start"]').exists()).toBe(false);
  });

  it('reports a cross-runtime shop as a different route, not a broken one', async () => {
    serve({
      guided_available: false,
      message: 'This WordPress holds only one side of the migration.',
    });
    const wrapper = mountScreen();
    await flushPromises();

    expect(wrapper.find('[data-test="cross-runtime"]').exists()).toBe(true);
    expect(wrapper.text()).not.toContain('error');
    expect(wrapper.text()).not.toContain('wp cartshift');
  });

  it('requires a fresh approval after cancelling and restarting the same review', async () => {
    const item = {
      review_id: 'decision-0123456789ab',
      kind: 'migration_decision',
      title: 'Order 116',
      summary: 'Keep the reviewed note private.',
    };
    const payload = status({
      run: {
        phase: 'awaiting_decisions',
        completed_steps: 3,
        total_steps: 12,
        last_step: 'Review migration decisions',
        review: { blockers: [], items: [item], proposal_counts: {} },
      },
    });
    payload.startResult = {
      phase: 'awaiting_decisions',
      completed_steps: 3,
      total_steps: 12,
      last_step: 'Review migration decisions',
      review: { blockers: [], items: [item], proposal_counts: {} },
    };
    serve(payload);
    const wrapper = mountScreen();
    await flushPromises();

    await wrapper.find('[data-test="review-decision"] input').setValue(true);
    await wrapper.find('[data-test="run-review"] .button:not(.button-primary)').trigger('click');
    await flushPromises();
    await wrapper.find('[data-test="start"]').trigger('click');
    await flushPromises();

    expect(wrapper.find('[data-test="review-decision"] input').element.checked).toBe(false);
    expect(wrapper.find('[data-test="accept-run-decisions"]').attributes('disabled')).toBeDefined();
  });

  it('keeps a changed subscription run visible but prevents stale approval', async () => {
    const item = {
      review_id: 'decision-0123456789ab',
      kind: 'migration_decision',
      title: 'Order 116',
      summary: 'Keep the reviewed note private.',
    };
    serve(
      status({
        run: {
          phase: 'awaiting_decisions',
          completed_steps: 3,
          total_steps: 12,
          last_step: 'Review migration decisions',
          mode_changed: 'Subscription availability changed after this check started. Cancel or restart it.',
          review: { blockers: [], items: [item], proposal_counts: {} },
        },
      })
    );
    const wrapper = mountScreen();
    await flushPromises();

    await wrapper.find('[data-test="review-decision"] input').setValue(true);

    expect(wrapper.find('[data-test="run-mode-changed"]').text()).toContain('Subscription availability changed');
    expect(wrapper.find('[data-test="accept-run-decisions"]').attributes('disabled')).toBeDefined();
  });

  it('shows one stop action when a running check has outdated subscription mode', async () => {
    const payload = status({
      run: {
        phase: 'running',
        completed_steps: 2,
        total_steps: 12,
        last_step: 'Inspect source records',
        mode_changed: 'Subscription availability changed after this check started. Stop this outdated check.',
      },
    });
    payload.startResult = {
      phase: 'failed',
      completed_steps: 2,
      total_steps: 12,
      last_step: 'Check compatibility',
      failure: {
        message: 'Subscription availability changed after this check started. Start a new check when the shop setup is stable.',
        can_restart: false,
      },
    };
    serve(payload);
    const wrapper = mountScreen();
    await flushPromises();

    expect(wrapper.findAll('.button-primary')).toHaveLength(1);
    expect(wrapper.find('[data-test="stop-outdated-run"]').exists()).toBe(true);

    await wrapper.find('[data-test="stop-outdated-run"]').trigger('click');
    await flushPromises();

    expect(apiMock.mock.calls).toContainEqual(['POST', 'migration/start']);
    expect(wrapper.find('[data-test="run-failure"]').text()).toContain('Subscription availability changed');
  });

  it('keeps one stop action when an outdated running check also blocks the current plan', async () => {
    serve(
      status({
        plan_blocked: true,
        plan_message: 'Active subscriptions need a supported migration route.',
        run: {
          phase: 'running',
          completed_steps: 2,
          total_steps: 12,
          last_step: 'Inspect source records',
          mode_changed: 'Subscription availability changed after this check started.',
        },
      })
    );
    const wrapper = mountScreen();
    await flushPromises();

    expect(wrapper.findAll('.button-primary')).toHaveLength(1);
    expect(wrapper.find('[data-test="stop-outdated-run"]').exists()).toBe(true);
    expect(wrapper.find('[data-test="check-again"]').exists()).toBe(false);
  });

  it('makes cancellation the one clear action for an outdated owner review', async () => {
    const item = {
      review_id: 'decision-outdated0001',
      kind: 'migration_decision',
      title: 'Order 116',
      summary: 'Keep the reviewed note private.',
    };
    serve(
      status({
        plan_blocked: true,
        plan_message: 'Active subscriptions need a supported migration route.',
        run: {
          phase: 'awaiting_decisions',
          completed_steps: 3,
          total_steps: 12,
          mode_changed: 'Subscription availability changed after this check started.',
          review: { blockers: [], items: [item], proposal_counts: {} },
        },
      })
    );
    const wrapper = mountScreen();
    await flushPromises();

    expect(wrapper.findAll('.button-primary')).toHaveLength(1);
    expect(wrapper.find('.cartshift-review-actions .button-primary').text()).toContain('Cancel outdated review');
    expect(wrapper.find('[data-test="check-again"]').exists()).toBe(false);
  });

  it('keeps a saved failure visible when the current plan is blocked', async () => {
    serve(
      status({
        plan: [],
        plan_blocked: true,
        plan_message: 'Active subscriptions are not supported yet.',
        run: {
          phase: 'failed',
          completed_steps: 3,
          total_steps: 12,
          last_step: 'Check compatibility',
          failure: {
            message: 'Subscription availability changed after this check started. Start a new check when the shop setup is stable.',
            can_restart: false,
          },
        },
      })
    );
    const wrapper = mountScreen();
    await flushPromises();

    expect(wrapper.find('[data-test="run-progress"]').exists()).toBe(true);
    expect(wrapper.find('[data-test="run-failure"]').text()).toContain('Subscription availability changed');
  });

  it('disables approval when a new shop blocker appears during review', async () => {
    const payload = status({
      preflight: {
        ready: false,
        checks: {
          fc_data: {
            label: 'Existing FluentCart records',
            severity: 'fail',
            message: 'Continuing could create duplicates.',
          },
        },
      },
      run: {
        phase: 'awaiting_decisions',
        completed_steps: 3,
        total_steps: 12,
        last_step: 'Review migration decisions',
        review: {
          blockers: [],
          items: [{
            review_id: 'decision-0123456789ab',
            kind: 'migration_decision',
            title: 'Order 116',
            summary: 'Keep the reviewed note private.',
          }],
          proposal_counts: {},
        },
      },
    });
    serve(payload);
    const wrapper = mountScreen();
    await flushPromises();

    await wrapper.find('[data-test="review-decision"] input').setValue(true);

    expect(wrapper.find('[data-test="accept-run-decisions"]').attributes('disabled')).toBeDefined();
    expect(wrapper.find('[data-test="preflight-blocked"]').exists()).toBe(true);
    expect(wrapper.findAll('.button-primary')).toHaveLength(1);
    expect(wrapper.find('[data-test="check-again"]').classes()).toContain('button-primary');
    expect(wrapper.find('[data-test="accept-run-decisions"]').classes()).not.toContain('button-primary');
  });
});
