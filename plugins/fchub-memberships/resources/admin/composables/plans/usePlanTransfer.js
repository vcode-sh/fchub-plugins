import { ref } from 'vue'
import { ElMessage } from 'element-plus'
import { plans } from '@/api/index.js'

export function downloadJson(data, filename) {
  const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = filename
  document.body.appendChild(anchor)
  anchor.click()
  document.body.removeChild(anchor)
  URL.revokeObjectURL(url)
}

export function usePlanTransfer({
  plansApi = plans,
  notify = ElMessage,
  refreshPlans = () => {},
  downloadJson: download = downloadJson,
} = {}) {
  const importDialogVisible = ref(false)
  const importJson = ref('')
  const importing = ref(false)
  const importMode = ref('paste')
  const importFileName = ref('')
  const bulkExporting = ref(false)

  function openImportDialog() {
    importJson.value = ''
    importFileName.value = ''
    importMode.value = 'paste'
    importDialogVisible.value = true
  }

  function onFileSelected(event) {
    const file = event.target.files?.[0]
    if (!file) return

    importFileName.value = file.name

    const reader = new FileReader()
    reader.onload = (loadEvent) => {
      importJson.value = loadEvent.target.result
    }
    reader.readAsText(file)

    event.target.value = ''
  }

  async function exportPlan(plan) {
    try {
      const response = await plansApi.export(plan.id)
      const data = response.data ?? response
      download(data, `plan-${plan.slug || plan.id}.json`)
      notify.success('Plan exported')
    } catch (error) {
      notify.error(error.message || 'Failed to export plan')
    }
  }

  async function handleBulkExport() {
    bulkExporting.value = true
    try {
      const response = await plansApi.exportAll()
      const data = response.data ?? response
      download(data, 'plans-export.json')
      notify.success(`${Array.isArray(data) ? data.length : 0} plan(s) exported`)
    } catch (error) {
      notify.error(error.message || 'Failed to export plans')
    } finally {
      bulkExporting.value = false
    }
  }

  async function handleImport() {
    if (!importJson.value.trim()) {
      notify.warning('Please provide plan JSON data')
      return
    }

    importing.value = true
    try {
      const raw = JSON.parse(importJson.value)
      const items = Array.isArray(raw) ? raw : [raw]
      let imported = 0
      let errors = 0

      for (const data of items) {
        try {
          await plansApi.import(data)
          imported++
        } catch {
          errors++
        }
      }

      if (errors > 0) {
        notify.warning(`Imported ${imported} plan(s), ${errors} failed`)
      } else {
        notify.success(`${imported} plan(s) imported successfully`)
      }

      importDialogVisible.value = false
      refreshPlans()
    } catch (error) {
      notify.error(error.message || 'Invalid JSON data')
    } finally {
      importing.value = false
    }
  }

  function handleUtility(command) {
    if (command === 'import') openImportDialog()
    if (command === 'export') handleBulkExport()
  }

  return {
    importDialogVisible,
    importJson,
    importing,
    importMode,
    importFileName,
    bulkExporting,
    openImportDialog,
    onFileSelected,
    exportPlan,
    handleBulkExport,
    handleImport,
    handleUtility,
  }
}
