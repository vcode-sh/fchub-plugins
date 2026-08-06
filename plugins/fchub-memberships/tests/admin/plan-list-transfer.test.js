import { describe, expect, it, vi } from 'vitest'
import { usePlanTransfer } from '@/composables/plans/usePlanTransfer.js'

function createTransfer({ plansApi = {}, downloadJson = vi.fn() } = {}) {
  const api = {
    export: vi.fn().mockResolvedValue({ data: { id: 7 } }),
    exportAll: vi.fn().mockResolvedValue({ data: [{ id: 7 }, { id: 8 }] }),
    import: vi.fn().mockResolvedValue({}),
    ...plansApi,
  }
  const notify = {
    success: vi.fn(),
    error: vi.fn(),
    warning: vi.fn(),
  }
  const refreshPlans = vi.fn()
  const transfer = usePlanTransfer({
    plansApi: api,
    notify,
    refreshPlans,
    downloadJson,
  })
  return { api, downloadJson, notify, refreshPlans, transfer }
}

describe('plan transfer composable', () => {
  it('exports one plan and all plans with the existing filenames and messages', async () => {
    const { api, downloadJson, notify, transfer } = createTransfer()

    await transfer.exportPlan({ id: 7, slug: 'gold' })
    await transfer.handleBulkExport()

    expect(api.export).toHaveBeenCalledWith(7)
    expect(api.exportAll).toHaveBeenCalledOnce()
    expect(downloadJson).toHaveBeenNthCalledWith(1, { id: 7 }, 'plan-gold.json')
    expect(downloadJson).toHaveBeenNthCalledWith(2, [{ id: 7 }, { id: 8 }], 'plans-export.json')
    expect(notify.success).toHaveBeenNthCalledWith(1, 'Plan exported')
    expect(notify.success).toHaveBeenNthCalledWith(2, '2 plan(s) exported')
    expect(transfer.bulkExporting.value).toBe(false)
  })

  it('imports every valid item, reports partial failures, closes, and refreshes the list', async () => {
    const importPlan = vi.fn()
      .mockResolvedValueOnce({})
      .mockRejectedValueOnce(new Error('Rejected plan'))
    const { notify, refreshPlans, transfer } = createTransfer({
      plansApi: { import: importPlan },
    })
    transfer.openImportDialog()
    transfer.importJson.value = JSON.stringify([{ title: 'Gold' }, { title: 'Silver' }])

    await transfer.handleImport()

    expect(importPlan).toHaveBeenNthCalledWith(1, { title: 'Gold' })
    expect(importPlan).toHaveBeenNthCalledWith(2, { title: 'Silver' })
    expect(notify.warning).toHaveBeenCalledWith('Imported 1 plan(s), 1 failed')
    expect(transfer.importDialogVisible.value).toBe(false)
    expect(transfer.importing.value).toBe(false)
    expect(refreshPlans).toHaveBeenCalledOnce()
  })
})
