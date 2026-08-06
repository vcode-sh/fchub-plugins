import { computed, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { importMembers, plans } from '@/api/index.js'
import {
  allLevelsMapped,
  buildImportReportCsv,
  buildLevelMappings,
  groupImportResults,
  importPreview,
  membersForImport,
} from './memberImportPolicy.js'

const BATCH_SIZE = 50

export function useMemberImportWizard({ importApi = importMembers, plansApi = plans, notifyError = ElMessage.error } = {}) {
  const currentStep = ref(0)
  const fileInputRef = ref(null)
  const isDragOver = ref(false)
  const parsing = ref(false)
  const parseError = ref('')
  const parseResult = ref(null)
  const fileContent = ref('')
  const parsedMembers = ref([])
  const mappings = reactive({})
  const existingPlans = ref([])
  const options = reactive({ conflict_mode: 'skip', create_customers: true })
  const importComplete = ref(false)
  const currentBatch = ref(0)
  const totalBatches = ref(0)
  const importResults = ref([])
  const counters = reactive({ imported: 0, skipped: 0, extended: 0, failed: 0 })

  const previewColumns = computed(() => Object.keys(parseResult.value?.preview?.[0] || {}))
  const levels = computed(() => parseResult.value?.levels || [])
  const preview = computed(() => importPreview(levels.value, mappings, existingPlans.value))
  const canContinueFromMappings = computed(() => allLevelsMapped(levels.value, mappings))
  const membersWithoutUsers = computed(() => parseResult.value?.members_without_users || 0)
  const importProgress = computed(() => totalBatches.value === 0
    ? 0
    : Math.round((currentBatch.value / totalBatches.value) * 100))
  const resultsByStatus = computed(() => groupImportResults(importResults.value))

  function resetCounters() {
    counters.imported = 0
    counters.skipped = 0
    counters.extended = 0
    counters.failed = 0
  }

  function initialiseMappings() {
    for (const level of levels.value) {
      const matchingPlan = existingPlans.value.find(
        (plan) => plan.title.toLowerCase() === level.name.toLowerCase()
      )
      mappings[level.name] = {
        action: matchingPlan ? 'map' : 'create',
        title: level.name,
        duration_type: 'lifetime',
        duration_days: 365,
        plan_id: matchingPlan ? matchingPlan.id : null,
      }
    }
  }

  function onFileSelected(event) {
    const file = event.target.files?.[0]
    if (file) processFile(file)
    event.target.value = ''
  }

  function onFileDrop(event) {
    isDragOver.value = false
    const file = event.dataTransfer?.files?.[0]
    if (file) processFile(file)
  }

  function processFile(file) {
    if (!file.name.endsWith('.csv')) {
      parseError.value = 'Please select a CSV file.'
      return
    }

    parseError.value = ''
    parseResult.value = null
    parsing.value = true

    const reader = new FileReader()
    reader.onload = async (event) => {
      fileContent.value = event.target.result
      try {
        const response = await importApi.parse({ content: fileContent.value })
        const data = response.data ?? response
        parseResult.value = {
          format: data.format,
          levels: data.levels,
          total_members: data.stats?.total || 0,
          unique_emails: data.stats?.unique_emails || 0,
          warnings: data.warnings || [],
          preview: data.preview || [],
          members_without_users: 0,
        }
        parsedMembers.value = data.members || []
        initialiseMappings()
      } catch (error) {
        parseError.value = error.message || 'Failed to parse CSV file.'
      } finally {
        parsing.value = false
      }
    }
    reader.onerror = () => {
      parseError.value = 'Failed to read file.'
      parsing.value = false
    }
    reader.readAsText(file)
  }

  async function loadPlans() {
    try {
      const response = await plansApi.options()
      const raw = response.data ?? response
      existingPlans.value = (Array.isArray(raw) ? raw : []).map((option) => ({
        id: option.id ?? Number(option.value),
        title: option.label ?? option.title,
      }))
    } catch {
      existingPlans.value = []
    }
  }

  async function startImport() {
    currentStep.value = 4
    importComplete.value = false
    currentBatch.value = 0
    resetCounters()
    importResults.value = []

    const levelMappings = buildLevelMappings(levels.value, mappings)
    let preparedMappings = levelMappings

    try {
      const response = await importApi.prepare({ mappings: levelMappings })
      const data = response.data ?? response
      preparedMappings = data.mappings || levelMappings
    } catch (error) {
      notifyError(error.message || 'Failed to prepare import')
      importComplete.value = true
      return
    }

    const importableMembers = membersForImport(parsedMembers.value, levelMappings)
    totalBatches.value = Math.max(1, Math.ceil(importableMembers.length / BATCH_SIZE))

    for (let index = 0; index < totalBatches.value; index += 1) {
      currentBatch.value = index + 1
      const batch = importableMembers.slice(index * BATCH_SIZE, (index + 1) * BATCH_SIZE)
      try {
        const response = await importApi.execute({
          members: batch,
          mappings: preparedMappings,
          conflict_mode: options.conflict_mode,
          create_customers: options.create_customers,
        })
        const data = response.data ?? response
        if (data.results) importResults.value.push(...data.results)
        const summary = data.summary || {}
        counters.imported += summary.imported || 0
        counters.skipped += summary.skipped || 0
        counters.extended += summary.extended || 0
        counters.failed += summary.failed || 0
      } catch (error) {
        counters.failed += batch.length
        notifyError(`Batch ${index + 1} failed: ${error.message}`)
      }
    }

    importComplete.value = true
  }

  function downloadReport() {
    const csvContent = buildImportReportCsv(importResults.value)
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `import-report-${new Date().toISOString().slice(0, 10)}.csv`
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    URL.revokeObjectURL(url)
  }

  function resetWizard() {
    currentStep.value = 0
    parseResult.value = null
    parseError.value = ''
    fileContent.value = ''
    parsedMembers.value = []
    Object.keys(mappings).forEach((key) => delete mappings[key])
    options.conflict_mode = 'skip'
    options.create_customers = true
    importComplete.value = false
    currentBatch.value = 0
    totalBatches.value = 0
    importResults.value = []
    resetCounters()
  }

  return {
    currentStep,
    fileInputRef,
    isDragOver,
    parsing,
    parseError,
    parseResult,
    parsedMembers,
    mappings,
    existingPlans,
    options,
    importComplete,
    currentBatch,
    totalBatches,
    importResults,
    counters,
    previewColumns,
    canContinueFromMappings,
    membersToImport: computed(() => preview.value.membersToImport),
    plansToCreate: computed(() => preview.value.plansToCreate),
    levelsSkipped: computed(() => preview.value.levelsSkipped),
    membersWithoutUsers,
    previewBreakdown: computed(() => preview.value.breakdown),
    importProgress,
    resultsByStatus,
    onFileSelected,
    onFileDrop,
    processFile,
    loadPlans,
    startImport,
    downloadReport,
    resetWizard,
  }
}
