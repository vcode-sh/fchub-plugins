import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useMigration } from '@/composables/useMigration.js';
import { fakeConfig, withSetup } from './helpers/withSetup.js';

let unmount;

beforeEach(() => {
  globalThis.fetch = vi.fn();
});

afterEach(() => {
  if (unmount) unmount();
  unmount = null;
});

describe('the legacy start boundary through the real API composable', () => {
  it('refuses before fetch can receive a migration request', async () => {
    const mounted = withSetup(() => useMigration(), { config: fakeConfig() });
    unmount = mounted.unmount;
    mounted.result.state.selectedEntities = ['order'];

    await expect(mounted.result.actions.startMigration()).resolves.toBe(false);

    expect(globalThis.fetch).not.toHaveBeenCalled();
    expect(mounted.result.state.error).toContain('legacy_generic_migration_closed');
    expect(mounted.result.state.error).toContain('wp cartshift transfer prepare');
  });

  it('refuses a legacy dry-run selection exactly like a live one', async () => {
    const mounted = withSetup(() => useMigration(), { config: fakeConfig() });
    unmount = mounted.unmount;
    mounted.result.state.selectedEntities = ['product', 'customer', 'order'];
    mounted.result.state.dryRun = true;

    await mounted.result.actions.startMigration();

    expect(globalThis.fetch).not.toHaveBeenCalled();
    expect(mounted.result.state.migrating).toBe(false);
  });
});
