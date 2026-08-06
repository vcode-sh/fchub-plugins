import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  buildImportReportCsv,
  buildLevelMappings,
  importPreview,
  membersForImport,
} from '@/composables/import/memberImportPolicy.js'
import { useMemberImportWizard } from '@/composables/import/useMemberImportWizard.js'

describe('member import policy', () => {
  const levels = [
    { name: 'Starter', count: 3, has_expiry: false },
    { name: 'Annual', count: 2, has_expiry: true },
    { name: 'Legacy', count: 1, has_expiry: false },
  ]

  const mappings = {
    Starter: { action: 'create', title: 'Starter access', duration_type: 'lifetime', duration_days: 365, plan_id: null },
    Annual: { action: 'map', title: 'Annual', duration_type: 'fixed_days', duration_days: 365, plan_id: 9 },
    Legacy: { action: 'skip', title: 'Legacy', duration_type: 'lifetime', duration_days: 365, plan_id: null },
  }

  it('protects the backend mapping payload from UI action names', () => {
    expect(buildLevelMappings(levels, mappings)).toEqual([
      { level_name: 'Starter', action: 'create_new', title: 'Starter access', duration_type: 'lifetime', duration_days: 365, plan_id: null },
      { level_name: 'Annual', action: 'map_existing', title: 'Annual', duration_type: 'fixed_days', duration_days: 365, plan_id: 9 },
      { level_name: 'Legacy', action: 'skip', title: 'Legacy', duration_type: 'lifetime', duration_days: 365, plan_id: null },
    ])
  })

  it('keeps skipped members out of batches without changing their source order', () => {
    const members = [
      { email: 'one@example.com', level_name: 'Annual' },
      { email: 'two@example.com', level_name: 'Legacy' },
      { email: 'three@example.com', level_name: 'Starter' },
    ]

    expect(membersForImport(members, buildLevelMappings(levels, mappings))).toEqual([
      { email: 'one@example.com', level_name: 'Annual' },
      { email: 'three@example.com', level_name: 'Starter' },
    ])
  })

  it('derives the exact preview counters and fallback plan label', () => {
    expect(importPreview(levels, mappings, [{ id: 9, title: 'Annual plan' }])).toEqual({
      membersToImport: 5,
      plansToCreate: 1,
      levelsSkipped: 1,
      breakdown: [
        { level: 'Starter', action: 'create', target_plan: 'Starter access', member_count: 3, expiry_type: 'Lifetime' },
        { level: 'Annual', action: 'map', target_plan: 'Annual plan', member_count: 2, expiry_type: 'Dated' },
        { level: 'Legacy', action: 'skip', target_plan: '-', member_count: 1, expiry_type: 'Lifetime' },
      ],
    })
  })

  it('escapes commas and quotes in the downloadable CSV report', () => {
    expect(buildImportReportCsv([
      {
        email: 'alice@example.com',
        plan: 'Gold, Plus',
        status: 'failed',
        message: 'Said "no"',
      },
    ])).toBe([
      'Email,Plan,Status,Message',
      '"alice@example.com","Gold, Plus","failed","Said ""no"""',
    ].join('\n'))
  })
})

describe('member import workflow', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
  })

  it('prepares the exact mappings then executes ordered batches with the selected options', async () => {
    const preparedMappings = [
      { level_name: 'Starter', action: 'map_existing', title: 'Starter', duration_type: 'lifetime', duration_days: 365, plan_id: 44 },
      { level_name: 'Legacy', action: 'skip', title: 'Legacy', duration_type: 'lifetime', duration_days: 365, plan_id: null },
    ]
    const execute = vi.fn()
      .mockResolvedValueOnce({ data: { results: [{ email: 'member-1@example.com', status: 'imported' }], summary: { imported: 50 } } })
      .mockResolvedValueOnce({ data: { results: [{ email: 'member-51@example.com', status: 'extended' }], summary: { extended: 1 } } })
    const prepare = vi.fn().mockResolvedValue({ data: { mappings: preparedMappings } })
    const wizard = useMemberImportWizard({
      importApi: { prepare, execute, parse: vi.fn() },
      plansApi: { options: vi.fn() },
      notifyError: vi.fn(),
    })

    wizard.parseResult.value = {
      levels: [
        { name: 'Starter', count: 51, has_expiry: false },
        { name: 'Legacy', count: 1, has_expiry: false },
      ],
    }
    wizard.parsedMembers.value = [
      ...Array.from({ length: 51 }, (_, index) => ({ email: `member-${index + 1}@example.com`, level_name: 'Starter' })),
      { email: 'legacy@example.com', level_name: 'Legacy' },
    ]
    Object.assign(wizard.mappings, {
      Starter: { action: 'create', title: 'Starter', duration_type: 'lifetime', duration_days: 365, plan_id: null },
      Legacy: { action: 'skip', title: 'Legacy', duration_type: 'lifetime', duration_days: 365, plan_id: null },
    })
    wizard.options.conflict_mode = 'extend'
    wizard.options.create_customers = false

    await wizard.startImport()

    expect(prepare).toHaveBeenCalledWith({
      mappings: [
        { level_name: 'Starter', action: 'create_new', title: 'Starter', duration_type: 'lifetime', duration_days: 365, plan_id: null },
        { level_name: 'Legacy', action: 'skip', title: 'Legacy', duration_type: 'lifetime', duration_days: 365, plan_id: null },
      ],
    })
    expect(execute).toHaveBeenCalledTimes(2)
    expect(execute.mock.calls[0][0]).toEqual({
      members: Array.from({ length: 50 }, (_, index) => ({ email: `member-${index + 1}@example.com`, level_name: 'Starter' })),
      mappings: preparedMappings,
      conflict_mode: 'extend',
      create_customers: false,
    })
    expect(execute.mock.calls[1][0].members).toEqual([
      { email: 'member-51@example.com', level_name: 'Starter' },
    ])
    expect(wizard.currentBatch.value).toBe(2)
    expect(wizard.totalBatches.value).toBe(2)
    expect(wizard.importComplete.value).toBe(true)
    expect(wizard.counters).toEqual({ imported: 50, skipped: 0, extended: 1, failed: 0 })
  })

  it('rejects non-CSV files before creating a reader', () => {
    const reader = vi.fn()
    vi.stubGlobal('FileReader', reader)
    const wizard = useMemberImportWizard()

    wizard.processFile({ name: 'members.txt' })

    expect(wizard.parseError.value).toBe('Please select a CSV file.')
    expect(wizard.parsing.value).toBe(false)
    expect(reader).not.toHaveBeenCalled()
  })

  it('surfaces parse API failures and finishes the parsing state', async () => {
    const readers = []
    vi.stubGlobal('FileReader', class {
      constructor() {
        readers.push(this)
      }

      readAsText() {}
    })
    const wizard = useMemberImportWizard({
      importApi: {
        parse: vi.fn().mockRejectedValue(new Error('Malformed membership export')),
        prepare: vi.fn(),
        execute: vi.fn(),
      },
      plansApi: { options: vi.fn() },
      notifyError: vi.fn(),
    })

    wizard.processFile({ name: 'members.csv' })
    await readers[0].onload({ target: { result: 'email,level\ninvalid' } })

    expect(wizard.parseError.value).toBe('Malformed membership export')
    expect(wizard.parseResult.value).toBeNull()
    expect(wizard.parsing.value).toBe(false)
  })

  it('clears stale plans when loading plan options fails', async () => {
    const wizard = useMemberImportWizard({
      importApi: { parse: vi.fn(), prepare: vi.fn(), execute: vi.fn() },
      plansApi: { options: vi.fn().mockRejectedValue(new Error('Unavailable')) },
      notifyError: vi.fn(),
    })
    wizard.existingPlans.value = [{ id: 5, title: 'Stale plan' }]

    await wizard.loadPlans()

    expect(wizard.existingPlans.value).toEqual([])
  })

  it('continues after a failed batch and aggregates its members as failed', async () => {
    const notifyError = vi.fn()
    const execute = vi.fn()
      .mockResolvedValueOnce({ data: { results: [], summary: { imported: 50 } } })
      .mockRejectedValueOnce(new Error('Gateway unavailable'))
    const mapping = {
      level_name: 'Starter',
      action: 'map_existing',
      title: 'Starter',
      duration_type: 'lifetime',
      duration_days: 365,
      plan_id: 44,
    }
    const wizard = useMemberImportWizard({
      importApi: {
        parse: vi.fn(),
        prepare: vi.fn().mockResolvedValue({ data: { mappings: [mapping] } }),
        execute,
      },
      plansApi: { options: vi.fn() },
      notifyError,
    })
    wizard.parseResult.value = {
      levels: [{ name: 'Starter', count: 51, has_expiry: false }],
    }
    wizard.parsedMembers.value = Array.from({ length: 51 }, (_, index) => ({
      email: `member-${index + 1}@example.com`,
      level_name: 'Starter',
    }))
    wizard.mappings.Starter = {
      action: 'map',
      title: 'Starter',
      duration_type: 'lifetime',
      duration_days: 365,
      plan_id: 44,
    }

    await wizard.startImport()

    expect(wizard.counters).toEqual({ imported: 50, skipped: 0, extended: 0, failed: 1 })
    expect(wizard.currentBatch.value).toBe(2)
    expect(wizard.importComplete.value).toBe(true)
    expect(notifyError).toHaveBeenCalledWith('Batch 2 failed: Gateway unavailable')
  })

  it('downloads the generated report through a revocable object URL', () => {
    const createObjectURL = vi.fn().mockReturnValue('blob:membership-report')
    const revokeObjectURL = vi.fn()
    vi.stubGlobal('URL', { createObjectURL, revokeObjectURL })
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    const wizard = useMemberImportWizard()
    wizard.importResults.value = [{
      email: 'alice@example.com',
      plan: 'Gold, Plus',
      status: 'failed',
      message: 'Said "no"',
    }]

    wizard.downloadReport()

    expect(createObjectURL).toHaveBeenCalledWith(expect.any(Blob))
    expect(click).toHaveBeenCalledOnce()
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:membership-report')
  })

  it('restores every resettable workflow default', () => {
    const wizard = useMemberImportWizard()

    wizard.currentStep.value = 4
    wizard.parseError.value = 'Old error'
    wizard.parseResult.value = { levels: [] }
    wizard.parsedMembers.value = [{ email: 'alice@example.com' }]
    wizard.mappings.Starter = { action: 'map' }
    wizard.options.conflict_mode = 'overwrite'
    wizard.options.create_customers = false
    wizard.importComplete.value = true
    wizard.currentBatch.value = 2
    wizard.totalBatches.value = 2
    wizard.counters.imported = 4
    wizard.counters.skipped = 3
    wizard.counters.extended = 2
    wizard.counters.failed = 1

    wizard.resetWizard()

    expect(wizard.currentStep.value).toBe(0)
    expect(wizard.parseError.value).toBe('')
    expect(wizard.parseResult.value).toBeNull()
    expect(wizard.parsedMembers.value).toEqual([])
    expect(wizard.mappings).toEqual({})
    expect(wizard.options).toEqual({ conflict_mode: 'skip', create_customers: true })
    expect(wizard.importComplete.value).toBe(false)
    expect(wizard.currentBatch.value).toBe(0)
    expect(wizard.totalBatches.value).toBe(0)
    expect(wizard.importResults.value).toEqual([])
    expect(wizard.counters).toEqual({ imported: 0, skipped: 0, extended: 0, failed: 0 })
  })
})
