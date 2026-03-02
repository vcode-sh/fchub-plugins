<template>
  <div class="import-wizard-page">
    <!-- Header -->
    <div class="page-header">
      <a class="back-link" @click.prevent="$router.push('/members')">
        <el-icon><ArrowLeft /></el-icon>
        Back to Members
      </a>
      <h2 class="fchub-page-title">Import Members</h2>
    </div>

    <!-- Steps indicator -->
    <el-steps :active="currentStep" finish-status="success" class="wizard-steps">
      <el-step title="Upload" />
      <el-step title="Map Levels" />
      <el-step title="Options" />
      <el-step title="Preview" />
      <el-step title="Import" />
    </el-steps>

    <!-- Step 1: Upload & Parse -->
    <el-card v-if="currentStep === 0" shadow="never" class="wizard-card">
      <div
        class="upload-zone"
        :class="{ 'drag-over': isDragOver }"
        @dragover.prevent="isDragOver = true"
        @dragleave.prevent="isDragOver = false"
        @drop.prevent="onFileDrop"
        @click="fileInputRef?.click()"
      >
        <input
          ref="fileInputRef"
          type="file"
          accept=".csv"
          style="display: none"
          @change="onFileSelected"
        />
        <el-icon class="upload-icon"><UploadFilled /></el-icon>
        <p class="upload-text">Drag and drop a CSV file here, or click to select</p>
        <p class="upload-hint">Supported formats: WishList Member, Generic CSV</p>
      </div>

      <div v-if="parsing" class="parse-loading">
        <el-icon class="is-loading"><Loading /></el-icon>
        <span>Parsing CSV file...</span>
      </div>

      <div v-if="parseError" class="parse-error">
        <el-alert :title="parseError" type="error" show-icon :closable="false" />
      </div>

      <template v-if="parseResult">
        <div class="parse-summary">
          <el-tag type="info" size="large" class="format-badge">
            {{ parseResult.format }}
          </el-tag>
        </div>

        <div class="stats-cards">
          <div class="stat-card">
            <div class="stat-value">{{ parseResult.total_members }}</div>
            <div class="stat-label">Total Members</div>
          </div>
          <div class="stat-card">
            <div class="stat-value">{{ parseResult.unique_emails }}</div>
            <div class="stat-label">Unique Emails</div>
          </div>
          <div class="stat-card">
            <div class="stat-value">{{ parseResult.levels?.length || 0 }}</div>
            <div class="stat-label">Levels Found</div>
          </div>
        </div>

        <div v-if="parseResult.warnings?.length" class="parse-warnings">
          <el-alert
            v-for="(warning, i) in parseResult.warnings"
            :key="i"
            :title="warning"
            type="warning"
            show-icon
            :closable="false"
            class="warning-item"
          />
        </div>

        <el-table :data="parseResult.preview" stripe class="preview-table">
          <el-table-column
            v-for="col in previewColumns"
            :key="col"
            :prop="col"
            :label="col"
            min-width="140"
          />
        </el-table>
        <div v-if="parseResult.total_members > 10" class="preview-note">
          Showing first 10 of {{ parseResult.total_members }} rows
        </div>
      </template>

      <div class="wizard-actions">
        <el-button @click="$router.push('/members')">Cancel</el-button>
        <el-button
          type="primary"
          :disabled="!parseResult"
          @click="currentStep = 1"
        >
          Next
        </el-button>
      </div>
    </el-card>

    <!-- Step 2: Map Levels to Plans -->
    <el-card v-if="currentStep === 1" shadow="never" class="wizard-card">
      <p class="step-description">Map each detected membership level to an existing plan or create a new one.</p>

      <div
        v-for="(level, index) in parseResult.levels"
        :key="index"
        class="level-card"
        :class="{ 'level-skipped': mappings[level.name]?.action === 'skip' }"
      >
        <div class="level-header">
          <span class="level-name">{{ level.name }}</span>
          <el-tag size="small" type="info">{{ level.count }} members</el-tag>
        </div>

        <el-radio-group
          v-model="mappings[level.name].action"
          class="level-actions"
        >
          <el-radio value="create">Create New Plan</el-radio>
          <el-radio value="map">Map to Existing Plan</el-radio>
          <el-radio value="skip">Skip this Level</el-radio>
        </el-radio-group>

        <div v-if="mappings[level.name].action === 'create'" class="mapping-details">
          <el-form label-position="top" class="mapping-form">
            <el-form-item label="Plan Title">
              <el-input v-model="mappings[level.name].title" placeholder="Plan title" />
            </el-form-item>
            <el-form-item label="Duration Type">
              <el-select v-model="mappings[level.name].duration_type" style="width: 100%">
                <el-option label="Lifetime (never expires)" value="lifetime" />
                <el-option label="Fixed Duration (days)" value="fixed_days" />
              </el-select>
            </el-form-item>
            <el-form-item
              v-if="mappings[level.name].duration_type === 'fixed_days'"
              label="Duration (days)"
            >
              <el-input-number
                v-model="mappings[level.name].duration_days"
                :min="1"
                :max="36500"
                controls-position="right"
              />
            </el-form-item>
          </el-form>
        </div>

        <div v-if="mappings[level.name].action === 'map'" class="mapping-details">
          <el-select
            v-model="mappings[level.name].plan_id"
            placeholder="Select existing plan..."
            filterable
            style="width: 100%"
          >
            <el-option
              v-for="plan in existingPlans"
              :key="plan.id"
              :label="plan.title"
              :value="plan.id"
            />
          </el-select>
        </div>
      </div>

      <div class="wizard-actions">
        <el-button @click="currentStep = 0">Back</el-button>
        <el-button
          type="primary"
          :disabled="!allLevelsMapped"
          @click="currentStep = 2"
        >
          Next
        </el-button>
      </div>
    </el-card>

    <!-- Step 3: Configure Options -->
    <el-card v-if="currentStep === 2" shadow="never" class="wizard-card">
      <p class="step-description">Configure how the import should handle existing members and other options.</p>

      <div class="option-section">
        <h4 class="option-title">Conflict Resolution</h4>
        <p class="option-help">How to handle members who already have an active grant for the mapped plan.</p>
        <el-radio-group v-model="options.conflict_mode" class="option-radio-group">
          <div class="option-radio-item">
            <el-radio value="skip">Skip existing members</el-radio>
            <p class="option-radio-help">Members who already have an active grant for the mapped plan will be skipped.</p>
          </div>
          <div class="option-radio-item">
            <el-radio value="extend">Extend expiry</el-radio>
            <p class="option-radio-help">If a member has an active grant, extend its expiry date to the imported expiry date.</p>
          </div>
          <div class="option-radio-item">
            <el-radio value="overwrite">Overwrite</el-radio>
            <p class="option-radio-help">Revoke existing grant and create a new one with imported data.</p>
          </div>
        </el-radio-group>
      </div>

      <div class="option-section">
        <h4 class="option-title">FluentCart Customers</h4>
        <div class="option-switch-row">
          <el-switch v-model="options.create_customers" />
          <span class="option-switch-label">Create customer records in FluentCart for imported members</span>
        </div>
        <p class="option-help">When enabled, FluentCart customer records will be created for members who don't have one yet.</p>
      </div>

      <div class="wizard-actions">
        <el-button @click="currentStep = 1">Back</el-button>
        <el-button type="primary" @click="currentStep = 3">
          Next
        </el-button>
      </div>
    </el-card>

    <!-- Step 4: Preview & Confirm -->
    <el-card v-if="currentStep === 3" shadow="never" class="wizard-card">
      <p class="step-description">Review your import configuration before starting.</p>

      <div class="stats-cards">
        <div class="stat-card">
          <div class="stat-value">{{ membersToImport }}</div>
          <div class="stat-label">Members to Import</div>
        </div>
        <div class="stat-card">
          <div class="stat-value">{{ plansToCreate }}</div>
          <div class="stat-label">Plans to Create</div>
        </div>
        <div class="stat-card">
          <div class="stat-value">{{ levelsSkipped }}</div>
          <div class="stat-label">Levels Skipped</div>
        </div>
      </div>

      <el-table :data="previewBreakdown" stripe class="breakdown-table">
        <el-table-column prop="level" label="Level" min-width="140" />
        <el-table-column label="Action" width="120">
          <template #default="{ row }">
            <el-tag :type="row.action === 'create' ? 'success' : row.action === 'map' ? 'primary' : 'info'" size="small">
              {{ row.action }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="target_plan" label="Target Plan" min-width="160" />
        <el-table-column prop="member_count" label="Members" width="100" align="center" />
        <el-table-column label="Expiry" width="120">
          <template #default="{ row }">
            {{ row.expiry_type }}
          </template>
        </el-table-column>
      </el-table>

      <div v-if="membersWithoutUsers > 0" class="preview-warning">
        <el-alert
          :title="`${membersWithoutUsers} member(s) have no matching WordPress user account.`"
          type="warning"
          show-icon
          :closable="false"
          description="These members will be skipped unless their WordPress accounts are created first."
        />
      </div>

      <div class="wizard-actions">
        <el-button @click="currentStep = 2">Back</el-button>
        <el-button type="primary" @click="startImport">
          Start Import
        </el-button>
      </div>
    </el-card>

    <!-- Step 5: Execute & Report -->
    <el-card v-if="currentStep === 4" shadow="never" class="wizard-card">
      <template v-if="!importComplete">
        <div class="import-progress">
          <el-progress
            :percentage="importProgress"
            :stroke-width="20"
            :text-inside="true"
          />
          <p class="progress-label">Processing batch {{ currentBatch }} of {{ totalBatches }}...</p>
        </div>

        <div class="import-counters">
          <div class="counter counter-success">
            <span class="counter-value">{{ counters.imported }}</span>
            <span class="counter-label">Imported</span>
          </div>
          <div class="counter counter-warning">
            <span class="counter-value">{{ counters.skipped }}</span>
            <span class="counter-label">Skipped</span>
          </div>
          <div class="counter counter-info">
            <span class="counter-value">{{ counters.extended }}</span>
            <span class="counter-label">Extended</span>
          </div>
          <div class="counter counter-danger">
            <span class="counter-value">{{ counters.failed }}</span>
            <span class="counter-label">Failed</span>
          </div>
        </div>
      </template>

      <template v-if="importComplete">
        <el-result
          icon="success"
          title="Import Complete"
          :sub-title="`${counters.imported} members imported, ${counters.skipped} skipped, ${counters.extended} extended, ${counters.failed} failed.`"
        />

        <div v-if="importResults.length > 0" class="results-detail">
          <el-collapse>
            <el-collapse-item title="Imported" name="imported" v-if="resultsByStatus.imported.length > 0">
              <el-table :data="resultsByStatus.imported" size="small" stripe>
                <el-table-column prop="email" label="Email" min-width="200" />
                <el-table-column prop="plan" label="Plan" min-width="140" />
                <el-table-column prop="message" label="Details" min-width="160" />
              </el-table>
            </el-collapse-item>
            <el-collapse-item title="Skipped" name="skipped" v-if="resultsByStatus.skipped.length > 0">
              <el-table :data="resultsByStatus.skipped" size="small" stripe>
                <el-table-column prop="email" label="Email" min-width="200" />
                <el-table-column prop="plan" label="Plan" min-width="140" />
                <el-table-column prop="message" label="Reason" min-width="160" />
              </el-table>
            </el-collapse-item>
            <el-collapse-item title="Extended" name="extended" v-if="resultsByStatus.extended.length > 0">
              <el-table :data="resultsByStatus.extended" size="small" stripe>
                <el-table-column prop="email" label="Email" min-width="200" />
                <el-table-column prop="plan" label="Plan" min-width="140" />
                <el-table-column prop="message" label="Details" min-width="160" />
              </el-table>
            </el-collapse-item>
            <el-collapse-item title="Failed" name="failed" v-if="resultsByStatus.failed.length > 0">
              <el-table :data="resultsByStatus.failed" size="small" stripe>
                <el-table-column prop="email" label="Email" min-width="200" />
                <el-table-column prop="plan" label="Plan" min-width="140" />
                <el-table-column prop="message" label="Error" min-width="160" />
              </el-table>
            </el-collapse-item>
          </el-collapse>
        </div>

        <div class="wizard-actions">
          <el-button @click="downloadReport">
            <el-icon><Download /></el-icon>
            Download Report
          </el-button>
          <el-button @click="resetWizard">Import Again</el-button>
          <el-button type="primary" @click="$router.push('/members')">View Members</el-button>
        </div>
      </template>
    </el-card>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { ArrowLeft, UploadFilled, Download, Loading } from '@element-plus/icons-vue'
import { importMembers, plans } from '@/api/index.js'

// Wizard state
const currentStep = ref(0)
const fileInputRef = ref(null)
const isDragOver = ref(false)

// Step 1: Upload & Parse
const parsing = ref(false)
const parseError = ref('')
const parseResult = ref(null)
const fileContent = ref('')
const parsedMembers = ref([])

// Step 2: Level mappings
const mappings = reactive({})
const existingPlans = ref([])

// Step 3: Options
const options = reactive({
  conflict_mode: 'skip',
  create_customers: true,
})

// Step 5: Import execution
const importComplete = ref(false)
const currentBatch = ref(0)
const totalBatches = ref(0)
const importResults = ref([])
const counters = reactive({
  imported: 0,
  skipped: 0,
  extended: 0,
  failed: 0,
})

// Computed
const previewColumns = computed(() => {
  if (!parseResult.value?.preview?.length) return []
  return Object.keys(parseResult.value.preview[0])
})

const allLevelsMapped = computed(() => {
  if (!parseResult.value?.levels) return false
  return parseResult.value.levels.every((level) => {
    const mapping = mappings[level.name]
    if (!mapping) return false
    if (mapping.action === 'skip') return true
    if (mapping.action === 'create') return !!mapping.title
    if (mapping.action === 'map') return !!mapping.plan_id
    return false
  })
})

const membersToImport = computed(() => {
  if (!parseResult.value?.levels) return 0
  return parseResult.value.levels
    .filter((l) => mappings[l.name]?.action !== 'skip')
    .reduce((sum, l) => sum + l.count, 0)
})

const plansToCreate = computed(() => {
  if (!parseResult.value?.levels) return 0
  return parseResult.value.levels
    .filter((l) => mappings[l.name]?.action === 'create')
    .length
})

const levelsSkipped = computed(() => {
  if (!parseResult.value?.levels) return 0
  return parseResult.value.levels
    .filter((l) => mappings[l.name]?.action === 'skip')
    .length
})

const membersWithoutUsers = computed(() => {
  return parseResult.value?.members_without_users || 0
})

const previewBreakdown = computed(() => {
  if (!parseResult.value?.levels) return []
  return parseResult.value.levels.map((level) => {
    const mapping = mappings[level.name]
    let targetPlan = '-'
    if (mapping?.action === 'create') {
      targetPlan = mapping.title || level.name
    } else if (mapping?.action === 'map') {
      const plan = existingPlans.value.find((p) => p.id === mapping.plan_id)
      targetPlan = plan?.title || `Plan #${mapping.plan_id}`
    }
    return {
      level: level.name,
      action: mapping?.action || 'skip',
      target_plan: targetPlan,
      member_count: level.count,
      expiry_type: level.has_expiry ? 'Dated' : 'Lifetime',
    }
  })
})

const importProgress = computed(() => {
  if (totalBatches.value === 0) return 0
  return Math.round((currentBatch.value / totalBatches.value) * 100)
})

const resultsByStatus = computed(() => {
  const grouped = { imported: [], skipped: [], extended: [], failed: [] }
  for (const r of importResults.value) {
    const key = r.status || 'failed'
    if (grouped[key]) {
      grouped[key].push(r)
    } else {
      grouped.failed.push(r)
    }
  }
  return grouped
})

// Methods
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
  reader.onload = async (e) => {
    fileContent.value = e.target.result
    try {
      const res = await importMembers.parse({ content: fileContent.value })
      const data = res.data ?? res
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
      initMappings()
    } catch (err) {
      parseError.value = err.message || 'Failed to parse CSV file.'
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

function initMappings() {
  if (!parseResult.value?.levels) return

  for (const level of parseResult.value.levels) {
    const matchingPlan = existingPlans.value.find(
      (p) => p.title.toLowerCase() === level.name.toLowerCase()
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

async function loadPlans() {
  try {
    const res = await plans.options()
    const raw = res.data ?? res
    existingPlans.value = (Array.isArray(raw) ? raw : []).map((o) => ({
      id: o.id ?? Number(o.value),
      title: o.label ?? o.title,
    }))
  } catch {
    existingPlans.value = []
  }
}

async function startImport() {
  currentStep.value = 4
  importComplete.value = false
  currentBatch.value = 0
  counters.imported = 0
  counters.skipped = 0
  counters.extended = 0
  counters.failed = 0
  importResults.value = []

  // Build mapping payload as array with level_name field
  const levelMappings = []
  for (const level of parseResult.value.levels) {
    const m = mappings[level.name]
    const backendAction = m.action === 'create' ? 'create_new' : m.action === 'map' ? 'map_existing' : 'skip'
    levelMappings.push({
      level_name: level.name,
      action: backendAction,
      title: m.title,
      duration_type: m.duration_type,
      duration_days: m.duration_days,
      plan_id: m.plan_id,
    })
  }

  // Step 1: Prepare (create new plans)
  let preparedMappings = levelMappings
  try {
    const prepResult = await importMembers.prepare({ mappings: levelMappings })
    const prepData = prepResult.data ?? prepResult
    preparedMappings = prepData.mappings || levelMappings
  } catch (err) {
    ElMessage.error(err.message || 'Failed to prepare import')
    importComplete.value = true
    return
  }

  // Step 2: Filter members to import (exclude skipped levels)
  const skippedLevels = new Set(
    levelMappings.filter((m) => m.action === 'skip').map((m) => m.level_name)
  )
  const membersForImport = parsedMembers.value.filter(
    (m) => !skippedLevels.has(m.level_name)
  )

  // Step 3: Execute in batches
  const batchSize = 50
  totalBatches.value = Math.max(1, Math.ceil(membersForImport.length / batchSize))

  for (let i = 0; i < totalBatches.value; i++) {
    currentBatch.value = i + 1
    const batch = membersForImport.slice(i * batchSize, (i + 1) * batchSize)
    try {
      const result = await importMembers.execute({
        members: batch,
        mappings: preparedMappings,
        conflict_mode: options.conflict_mode,
        create_customers: options.create_customers,
      })
      const batchData = result.data ?? result

      if (batchData.results) {
        importResults.value.push(...batchData.results)
      }
      const summary = batchData.summary || {}
      counters.imported += summary.imported || 0
      counters.skipped += summary.skipped || 0
      counters.extended += summary.extended || 0
      counters.failed += summary.failed || 0
    } catch (err) {
      counters.failed += batch.length
      ElMessage.error(`Batch ${i + 1} failed: ${err.message}`)
    }
  }

  importComplete.value = true
}

function downloadReport() {
  const headers = ['Email', 'Plan', 'Status', 'Message']
  const rows = importResults.value.map((r) => [
    r.email || '',
    r.plan || '',
    r.status || '',
    r.message || '',
  ])

  const csvContent = [
    headers.join(','),
    ...rows.map((row) => row.map((cell) => `"${String(cell).replace(/"/g, '""')}"`).join(',')),
  ].join('\n')

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
  counters.imported = 0
  counters.skipped = 0
  counters.extended = 0
  counters.failed = 0
}

onMounted(() => {
  loadPlans()
})
</script>

<style scoped>
.import-wizard-page {
  /* full width like other pages */
}

.page-header {
  margin-bottom: 20px;
}

.page-header .fchub-page-title {
  margin-bottom: 0;
}

.back-link {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 13px;
  color: var(--fchub-text-secondary);
  text-decoration: none;
  margin-bottom: 8px;
  cursor: pointer;
}

.back-link:hover {
  color: var(--el-color-primary);
}

.wizard-steps {
  margin-bottom: 24px;
}

.wizard-card {
  margin-bottom: 20px;
}

/* Upload zone */
.upload-zone {
  border: 2px dashed var(--fchub-border-color);
  border-radius: 8px;
  padding: 48px 24px;
  text-align: center;
  cursor: pointer;
  transition: border-color 0.2s, background-color 0.2s;
}

.upload-zone:hover,
.upload-zone.drag-over {
  border-color: var(--el-color-primary);
  background-color: var(--el-color-primary-light-9);
}

.upload-icon {
  font-size: 48px;
  color: var(--fchub-text-secondary);
  margin-bottom: 12px;
}

.upload-text {
  font-size: 15px;
  color: var(--fchub-text-primary);
  margin: 0 0 6px 0;
  font-weight: 500;
}

.upload-hint {
  font-size: 13px;
  color: var(--fchub-text-secondary);
  margin: 0;
}

/* Parse loading */
.parse-loading {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 24px;
  font-size: 14px;
  color: var(--fchub-text-secondary);
}

.parse-error {
  margin-top: 16px;
}

/* Parse summary */
.parse-summary {
  margin-top: 20px;
  margin-bottom: 16px;
}

.format-badge {
  font-weight: 500;
}

/* Stats cards */
.stats-cards {
  display: flex;
  gap: 16px;
  margin-bottom: 20px;
}

.stat-card {
  flex: 1;
  padding: 16px;
  background: var(--el-fill-color-lighter, #fafafa);
  border: 1px solid var(--fchub-border-color);
  border-radius: 8px;
  text-align: center;
}

.stat-value {
  font-size: 28px;
  font-weight: 600;
  color: var(--fchub-text-primary);
  line-height: 1.2;
}

.stat-label {
  font-size: 13px;
  color: var(--fchub-text-secondary);
  margin-top: 4px;
}

/* Parse warnings */
.parse-warnings {
  margin-bottom: 16px;
}

.warning-item {
  margin-bottom: 8px;
}

/* Preview */
.preview-table {
  margin-top: 16px;
}

.preview-note {
  font-size: 12px;
  color: var(--fchub-text-secondary);
  margin-top: 8px;
  text-align: center;
}

/* Wizard actions */
.wizard-actions {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  margin-top: 24px;
  padding-top: 16px;
  border-top: 1px solid var(--fchub-border-color);
}

/* Step description */
.step-description {
  font-size: 14px;
  color: var(--fchub-text-secondary);
  margin: 0 0 20px 0;
}

/* Level cards (Step 2) */
.level-card {
  padding: 16px;
  margin-bottom: 16px;
  background: var(--el-fill-color-lighter, #fafafa);
  border: 1px solid var(--fchub-border-color);
  border-radius: 8px;
  transition: opacity 0.2s;
}

.level-card.level-skipped {
  opacity: 0.6;
}

.level-header {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 12px;
}

.level-name {
  font-weight: 600;
  font-size: 15px;
  color: var(--fchub-text-primary);
}

.level-actions {
  display: flex;
  gap: 16px;
  margin-bottom: 12px;
}

.mapping-details {
  margin-top: 12px;
  padding: 12px 16px;
  background: var(--fchub-card-bg);
  border: 1px solid var(--fchub-border-color);
  border-radius: 6px;
}

.mapping-form {
  display: flex;
  gap: 16px;
  flex-wrap: wrap;
}

.mapping-form .el-form-item {
  margin-bottom: 0;
  min-width: 180px;
}

/* Options (Step 3) */
.option-section {
  margin-bottom: 24px;
}

.option-title {
  font-size: 15px;
  font-weight: 600;
  color: var(--fchub-text-primary);
  margin: 0 0 4px 0;
}

.option-help {
  font-size: 13px;
  color: var(--fchub-text-secondary);
  margin: 0 0 12px 0;
}

.option-radio-group {
  display: flex;
  flex-direction: column;
  gap: 0;
}

.option-radio-item {
  padding: 8px 0;
}

.option-radio-help {
  font-size: 12px;
  color: var(--fchub-text-secondary);
  margin: 2px 0 0 24px;
}

.option-switch-row {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 8px;
}

.option-switch-label {
  font-size: 14px;
  color: var(--fchub-text-primary);
}

/* Preview (Step 4) */
.breakdown-table {
  margin-bottom: 16px;
}

.preview-warning {
  margin-top: 16px;
}

/* Import progress (Step 5) */
.import-progress {
  padding: 24px 0;
  text-align: center;
}

.progress-label {
  font-size: 14px;
  color: var(--fchub-text-secondary);
  margin-top: 12px;
}

/* Counters */
.import-counters {
  display: flex;
  gap: 16px;
  margin-bottom: 24px;
}

.counter {
  flex: 1;
  padding: 12px;
  border-radius: 8px;
  text-align: center;
  border: 1px solid var(--fchub-border-color);
}

.counter-success {
  background: var(--el-color-success-light-9);
}

.counter-warning {
  background: var(--el-color-warning-light-9);
}

.counter-info {
  background: var(--el-color-primary-light-9);
}

.counter-danger {
  background: var(--el-color-danger-light-9);
}

.counter-value {
  display: block;
  font-size: 24px;
  font-weight: 600;
  color: var(--fchub-text-primary);
}

.counter-label {
  display: block;
  font-size: 12px;
  color: var(--fchub-text-secondary);
  margin-top: 2px;
}

/* Results */
.results-detail {
  margin: 20px 0;
}
</style>
