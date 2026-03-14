<template>
  <div class="plan-editor-page">
    <div class="page-header">
      <a class="back-link" @click.prevent="$router.push('/plans')">
        <el-icon><ArrowLeft /></el-icon>
        Back to Plans
      </a>
      <h2 class="fchub-page-title">{{ isNew ? 'Create Plan' : 'Edit Plan' }}</h2>
    </div>

    <el-form
      ref="formRef"
      v-loading="pageLoading"
      :model="form"
      :rules="rules"
      label-width="140px"
      label-position="top"
      class="plan-form"
      @submit.prevent
    >
      <el-tabs v-model="activeTab" type="border-card">
        <el-tab-pane label="General" name="general">
          <el-form-item label="Title" prop="title">
            <el-input
              v-model="form.title"
              placeholder="e.g. Gold Membership"
              @input="onTitleInput"
            />
          </el-form-item>

          <el-form-item label="Slug" prop="slug">
            <el-input v-model="form.slug" placeholder="e.g. gold-membership">
              <template #prepend>/</template>
            </el-input>
            <div class="field-hint">
              URL-friendly identifier. Auto-generated from title if left empty.
            </div>
          </el-form-item>

          <el-form-item label="Description" prop="description">
            <el-input
              v-model="form.description"
              type="textarea"
              :rows="3"
              placeholder="Describe what this plan offers..."
            />
          </el-form-item>

          <el-form-item label="Status" prop="status">
            <el-select v-model="form.status" style="width: 200px">
              <el-option label="Active" value="active" />
              <el-option label="Inactive" value="inactive" />
              <el-option label="Archived" value="archived" />
            </el-select>
          </el-form-item>

          <el-form-item label="Level" prop="level">
            <el-input-number
              v-model="form.level"
              :min="0"
              :max="100"
              controls-position="right"
              style="width: 200px"
            />
            <div class="field-hint">
              Plan hierarchy level (0-100). Higher number = higher tier. Used for upgrade/downgrade logic when Membership Mode is set to "Upgrade Only".
            </div>
          </el-form-item>

          <el-form-item label="Includes Plans" prop="includes_plan_ids">
            <el-select
              v-model="form.includes_plan_ids"
              multiple
              filterable
              placeholder="Select plans to include..."
              style="width: 100%"
            >
              <el-option
                v-for="opt in planOptions"
                :key="opt.id"
                :label="opt.title"
                :value="opt.id"
              />
            </el-select>
            <div class="field-hint">
              Members of this plan will also receive access from the selected plans.
            </div>
          </el-form-item>

          <el-divider content-position="left">Duration & Trial</el-divider>

          <el-form-item label="Duration Type" prop="duration_type">
            <el-select v-model="form.duration_type" style="width: 340px">
              <el-option label="Lifetime (never expires)" value="lifetime" />
              <el-option label="Fixed Duration (X days)" value="fixed_days" />
              <el-option label="Mirror Subscription" value="subscription_mirror" />
              <el-option label="Fixed Billing Anchor (monthly due date)" value="fixed_anchor" />
            </el-select>
            <div class="field-hint">
              Determines how long membership access lasts. Fixed Billing Anchor ties access to a calendar day each month. Applies to all linked products unless overridden.
            </div>
          </el-form-item>

          <el-form-item
            v-if="form.duration_type === 'fixed_days'"
            label="Duration (days)"
            prop="duration_days"
            :rules="[{ required: true, message: 'Required for fixed duration', trigger: 'blur' }]"
          >
            <el-input-number
              v-model="form.duration_days"
              :min="1"
              :max="36500"
              controls-position="right"
              style="width: 200px"
            />
            <div class="field-hint">Number of days the membership remains active after purchase.</div>
          </el-form-item>

          <el-form-item
            v-if="form.duration_type === 'fixed_anchor'"
            label="Billing Anchor Day"
            prop="meta.billing_anchor_day"
            :rules="[{ required: true, message: 'Required for anchor billing', trigger: 'blur' }]"
          >
            <el-input-number
              v-model="form.meta.billing_anchor_day"
              :min="1"
              :max="31"
              controls-position="right"
              style="width: 200px"
            />
            <div class="field-hint">
              Day of the month when payment is due (1-31). Access suspends if unpaid by this date. For months with fewer days (e.g. Feb), the anchor clamps to the last day.
            </div>
          </el-form-item>

          <el-form-item label="Trial Period (days)" prop="trial_days">
            <el-input-number
              v-model="form.trial_days"
              :min="0"
              :max="365"
              controls-position="right"
              style="width: 200px"
            />
            <div class="field-hint">
              Set to 0 for no trial. When set, new members get a trial period before their paid membership begins.
            </div>
          </el-form-item>

          <el-form-item label="Grace Period (days)" prop="grace_period_days">
            <el-input-number
              v-model="form.grace_period_days"
              :min="0"
              :max="365"
              controls-position="right"
              style="width: 200px"
            />
            <div class="field-hint">
              Days to keep access after cancellation or failed renewal. 0 = immediate revocation.
            </div>
          </el-form-item>

          <!-- T17: Schedule Status Change -->
          <PlanSchedulePanel
            :is-new="isNew"
            :scheduled-status="schedule.scheduled_status"
            :scheduled-at="schedule.scheduled_at"
            :new-status="schedule.new_status"
            :new-at="schedule.new_at"
            :loading="scheduleSaving"
            :format-date-time="formatWpDateTime"
            :date-time-picker-format="wpDateTimePickerFormat"
            @update:new-status="schedule.new_status = $event"
            @update:new-at="schedule.new_at = $event"
            @save="saveSchedule"
            @clear="clearSchedule"
          />
        </el-tab-pane>

        <el-tab-pane label="Access Rules" name="rules">
          <div class="section-header-row" style="margin-bottom: 12px">
            <span></span>
            <el-button size="small" @click="addRule">
              <el-icon><Plus /></el-icon>
              Add Rule
            </el-button>
          </div>

          <el-empty
            v-if="form.rules.length === 0"
            description="No access rules defined. Add a rule to control what content this plan unlocks."
            :image-size="60"
          />

          <div
            v-for="(rule, index) in form.rules"
            :key="index"
            class="rule-row"
          >
            <div class="rule-fields">
              <el-form-item
                :prop="`rules.${index}.resource_type`"
                :rules="[{ required: true, message: 'Required', trigger: 'change' }]"
                label="Resource Type"
              >
                <el-select
                  v-model="rule.resource_type"
                  placeholder="Type"
                  style="width: 260px"
                  @change="onResourceTypeChange(index, rule)"
                >
                  <el-option-group
                    v-for="group in resourceTypeGroups"
                    :key="group.key"
                    :label="group.label"
                  >
                    <el-option
                      v-for="type in group.types"
                      :key="type.value"
                      :label="type.displayLabel"
                      :value="type.value"
                    >
                      <span>{{ type.label }}</span>
                      <span v-if="type.source" class="resource-type-source">{{ type.source }}</span>
                    </el-option>
                  </el-option-group>
                </el-select>
              </el-form-item>

              <el-form-item
                :prop="`rules.${index}.resource_id`"
                label="Resource"
              >
                <!-- URL Pattern: free text input -->
                <template v-if="rule.resource_type === 'url_pattern'">
                  <el-input
                    v-model="rule.resource_id"
                    placeholder="/members/* or /premium/content/*"
                    style="width: 280px"
                  />
                  <span class="resource-hint">Use * as wildcard. Example: /members/*</span>
                </template>

                <!-- Special Page: fixed options -->
                <template v-else-if="rule.resource_type === 'special_page'">
                  <el-select
                    v-model="rule.resource_id"
                    placeholder="Select page..."
                    style="width: 240px"
                  >
                    <el-option
                      v-for="sp in specialPageOptions"
                      :key="sp.id"
                      :label="sp.label"
                      :value="sp.id"
                    />
                  </el-select>
                </template>

                <!-- Searchable types: remote search dropdown -->
                <template v-else>
                  <el-select
                    v-model="rule.resource_id"
                    filterable
                    remote
                    clearable
                    :remote-method="(q) => searchRuleResources(index, rule.resource_type, q)"
                    :loading="ruleResourceLoading[index]"
                    placeholder="Search by title..."
                    style="width: 240px"
                    @clear="rule.resource_id = '0'"
                  >
                    <el-option label="All of this type" value="0" />
                    <el-option
                      v-for="item in ruleResourceOptions[index]"
                      :key="item.id"
                      :label="item.label"
                      :value="String(item.id)"
                    />
                  </el-select>
                  <el-icon
                    v-if="rule.resource_id && rule.resource_id !== '0' && rule.resource_label === '(Deleted)'"
                    class="rule-warning-icon"
                    title="This resource has been deleted"
                  ><WarningFilled /></el-icon>
                </template>
              </el-form-item>

              <el-form-item
                :prop="`rules.${index}.drip_type`"
                :rules="[{ required: true, message: 'Required', trigger: 'change' }]"
                label="Drip"
              >
                <el-select
                  v-model="rule.drip_type"
                  placeholder="Drip"
                  style="width: 140px"
                  @change="onDripTypeChange(rule)"
                >
                  <el-option label="Immediate" value="immediate" />
                  <el-option label="Delayed" value="delayed" />
                  <el-option label="Fixed Date" value="fixed_date" />
                </el-select>
              </el-form-item>

              <el-form-item
                v-if="rule.drip_type === 'delayed'"
                :prop="`rules.${index}.drip_delay_days`"
                :rules="[{ required: true, message: 'Required', trigger: 'blur' }]"
                label="Delay (days)"
              >
                <el-input-number
                  v-model="rule.drip_delay_days"
                  :min="1"
                  :max="730"
                  controls-position="right"
                  style="width: 120px"
                />
              </el-form-item>

              <el-form-item
                v-if="rule.drip_type === 'fixed_date'"
                :prop="`rules.${index}.drip_date`"
                :rules="[{ required: true, message: 'Required', trigger: 'change' }]"
                label="Unlock Date"
              >
                <el-date-picker
                  v-model="rule.drip_date"
                  type="date"
                  placeholder="Select date"
                  :format="wpDatePickerFormat"
                  value-format="YYYY-MM-DD"
                  :disabled-date="isPastDate"
                  style="width: 160px"
                />
              </el-form-item>
            </div>

            <el-button
              type="danger"
              text
              size="small"
              class="remove-rule-btn"
              @click="removeRule(index)"
            >
              <el-icon><Delete /></el-icon>
            </el-button>
          </div>
        </el-tab-pane>

        <el-tab-pane label="Drip Preview" name="drip" v-if="dripPreviewRules.length > 0">
          <el-timeline>
            <el-timeline-item
              v-for="(item, index) in dripPreviewRules"
              :key="index"
              :timestamp="item.label"
              placement="top"
              :type="item.type"
            >
              <strong>{{ item.resourceLabel }}</strong>
            </el-timeline-item>
          </el-timeline>
        </el-tab-pane>

        <el-tab-pane v-if="!isNew" label="Linked Products" name="products" :lazy="true">
          <PlanLinkedProductsTab
            :loading="productsLoading"
            :products="linkedProducts"
            @link="showLinkProductDialog"
            @unlink="confirmUnlinkProduct"
          />
        </el-tab-pane>

        <el-tab-pane v-if="!isNew" label="Members" name="members" :lazy="true">
          <PlanMembersTab
            :loading="planMembersLoading"
            :members="planMembers"
            :total="planMembersTotal"
            :page="planMembersPage"
            :per-page="planMembersPerPage"
            :format-date="formatWpDate"
            :members-link="`/members?plan_id=${route.params.id}`"
            @page-change="onMembersPageChange"
          />
        </el-tab-pane>
      </el-tabs>

      <!-- Actions -->
      <div class="form-actions">
        <el-button @click="$router.push('/plans')">Cancel</el-button>
        <el-button
          type="primary"
          :loading="saving"
          @click="savePlan"
        >
          {{ isNew ? 'Create Plan' : 'Save Changes' }}
        </el-button>
      </div>
    </el-form>

    <PlanLinkProductDialog
      :visible="linkProductVisible"
      :query="productSearchQuery"
      :results="productSearchResults"
      :loading="productSearchLoading"
      :selected-product="selectedProduct"
      :linking="linkingProduct"
      @close="linkProductVisible = false"
      @update:query="productSearchQuery = $event"
      @search="debouncedSearchProducts"
      @select="selectedProduct = $event"
      @confirm="confirmLinkProduct"
    />
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { ArrowLeft, Delete, Plus, WarningFilled } from '@element-plus/icons-vue'
import { useRouter, useRoute } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { plans, members, content } from '@/api/index.js'
import { formatWpDate, formatWpDateTime, wpDatePickerFormat, wpDateTimePickerFormat } from '@/utils/wpDate.js'
import PlanSchedulePanel from '@/components/plans/PlanSchedulePanel.vue'
import PlanLinkedProductsTab from '@/components/plans/PlanLinkedProductsTab.vue'
import PlanMembersTab from '@/components/plans/PlanMembersTab.vue'
import PlanLinkProductDialog from '@/components/plans/PlanLinkProductDialog.vue'

const props = defineProps({
  isNew: {
    type: Boolean,
    default: false,
  },
})

const router = useRouter()
const route = useRoute()
const formRef = ref(null)
const pageLoading = ref(false)
const saving = ref(false)
const slugManuallyEdited = ref(false)
const planOptions = ref([])
const activeTab = ref('general')

// Linked Products tab state
const linkedProducts = ref([])
const productsLoading = ref(false)
const productsLoaded = ref(false)

// T15: Link Product dialog state
const linkProductVisible = ref(false)
const productSearchQuery = ref('')
const productSearchResults = ref([])
const productSearchLoading = ref(false)
const selectedProduct = ref(null)
const linkingProduct = ref(false)
let productSearchTimer = null

// T17: Schedule state
const schedule = reactive({
  scheduled_status: null,
  scheduled_at: null,
  new_status: '',
  new_at: '',
})
const scheduleSaving = ref(false)

// Resource type and search state
const resourceTypeGroups = ref([])
const ruleResourceOptions = reactive({})
const ruleResourceLoading = reactive({})
let ruleSearchTimers = {}

// Members tab state
const planMembers = ref([])
const planMembersLoading = ref(false)
const planMembersLoaded = ref(false)
const planMembersPage = ref(1)
const planMembersPerPage = 10
const planMembersTotal = ref(0)

const form = reactive({
  title: '',
  slug: '',
  description: '',
  status: 'inactive',
  includes_plan_ids: [],
  rules: [],
  duration_type: 'lifetime',
  duration_days: null,
  trial_days: 0,
  grace_period_days: 0,
  level: 0,
  meta: {
    billing_anchor_day: null,
  },
})

const rules = {
  title: [
    { required: true, message: 'Title is required', trigger: 'blur' },
  ],
  slug: [
    { required: true, message: 'Slug is required', trigger: 'blur' },
    {
      pattern: /^[a-z0-9]+(?:-[a-z0-9]+)*$/,
      message: 'Slug must be lowercase letters, numbers, and hyphens only',
      trigger: 'blur',
    },
  ],
}

function createEmptyRule() {
  return {
    resource_type: 'post',
    resource_id: '0',
    resource_label: null,
    drip_type: 'immediate',
    drip_delay_days: null,
    drip_date: null,
  }
}

function addRule() {
  form.rules.push(createEmptyRule())
}

function removeRule(index) {
  form.rules.splice(index, 1)
}

function onDripTypeChange(rule) {
  if (rule.drip_type === 'immediate') {
    rule.drip_delay_days = null
    rule.drip_date = null
  } else if (rule.drip_type === 'delayed') {
    rule.drip_date = null
    if (!rule.drip_delay_days) rule.drip_delay_days = 1
  } else if (rule.drip_type === 'fixed_date') {
    rule.drip_delay_days = null
  }
}

const specialPageOptions = [
  { id: 'blog', label: 'Blog / Posts Page' },
  { id: 'front_page', label: 'Front Page' },
  { id: 'search', label: 'Search Results' },
  { id: '404', label: '404 Page' },
  { id: 'author', label: 'Author Archives' },
  { id: 'date', label: 'Date Archives' },
]

function getTypeConfig(resourceType) {
  for (const group of resourceTypeGroups.value) {
    const found = group.types.find((t) => t.value === resourceType)
    if (found) return found
  }
  return null
}

function isSearchableType(resourceType) {
  if (resourceType === 'url_pattern' || resourceType === 'special_page') return false
  const config = getTypeConfig(resourceType)
  return config ? config.searchable : true
}

function onResourceTypeChange(index, rule) {
  if (rule.resource_type === 'url_pattern') {
    rule.resource_id = ''
  } else {
    rule.resource_id = '0'
  }
  rule.resource_label = null
  delete ruleResourceOptions[index]
}

async function searchRuleResources(index, resourceType, query) {
  if (!query || query.length < 1) {
    return
  }
  clearTimeout(ruleSearchTimers[index])
  ruleSearchTimers[index] = setTimeout(async () => {
    ruleResourceLoading[index] = true
    try {
      const res = await content.searchResources({ type: resourceType, query })
      ruleResourceOptions[index] = (res.data ?? res).map((item) => ({
        id: String(item.id),
        label: item.label || item.title || `#${item.id}`,
      }))
    } catch {
      ruleResourceOptions[index] = []
    } finally {
      ruleResourceLoading[index] = false
    }
  }, 300)
}

async function loadResourceTypes() {
  try {
    const res = await content.resourceTypes()
    const data = res.data ?? res
    const types = Array.isArray(data) ? data : (data.data ?? data)
    const groups = res.groups ?? data.groups ?? {}

    // Group types by their group field
    const groupMap = {}
    const groupOrder = ['content', 'taxonomy', 'navigation', 'advanced']
    const defaultLabels = { content: 'Content', taxonomy: 'Taxonomy', navigation: 'Navigation', advanced: 'Advanced' }

    for (const type of types) {
      const g = type.group || 'content'
      if (!groupMap[g]) {
        groupMap[g] = {
          key: g,
          label: groups[g] || defaultLabels[g] || capitalize(g),
          types: [],
        }
      }
      const source = type.source || ''
      groupMap[g].types.push({
        value: type.key || type.value,
        label: type.label,
        source,
        searchable: type.searchable !== false,
        displayLabel: source ? `${type.label} (${source})` : type.label,
      })
    }

    resourceTypeGroups.value = groupOrder
      .filter((g) => groupMap[g])
      .map((g) => groupMap[g])
  } catch {
    // Fallback to hardcoded defaults
    resourceTypeGroups.value = [
      {
        key: 'content',
        label: 'Content',
        types: [
          { value: 'post', label: 'Posts' },
          { value: 'page', label: 'Pages' },
        ],
      },
      {
        key: 'taxonomy',
        label: 'Taxonomy',
        types: [
          { value: 'category', label: 'Categories' },
          { value: 'post_tag', label: 'Tags' },
        ],
      },
    ]
  }
}

function isPastDate(date) {
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  return date.getTime() < today.getTime()
}

function slugify(text) {
  return text
    .toLowerCase()
    .trim()
    .replace(/[^\w\s-]/g, '')
    .replace(/[\s_]+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-+|-+$/g, '')
}

function onTitleInput() {
  if (!slugManuallyEdited.value) {
    form.slug = slugify(form.title)
  }
}

// Drip Schedule Preview
const dripPreviewRules = computed(() => {
  const scheduled = form.rules
    .filter((r) => r.drip_type !== 'immediate')
    .map((r) => {
      let resourceLabel
      if (!r.resource_id || r.resource_id === '0' || r.resource_id === 0) {
        resourceLabel = `All ${capitalize(r.resource_type)}s`
      } else if (r.resource_label && r.resource_label !== '(Deleted)') {
        resourceLabel = r.resource_label
      } else {
        resourceLabel = `${capitalize(r.resource_type)} #${r.resource_id}`
      }

      let label = ''
      let sortKey = 0
      let type = 'primary'

      if (r.drip_type === 'delayed') {
        const days = r.drip_delay_days || 0
        label = `Day ${days} after enrollment`
        sortKey = days
        type = 'primary'
      } else if (r.drip_type === 'fixed_date') {
        label = r.drip_date ? formatWpDate(r.drip_date, 'Date not set') : 'Date not set'
        sortKey = r.drip_date ? new Date(r.drip_date).getTime() : Infinity
        type = 'warning'
      }

      return { resourceLabel, label, sortKey, type }
    })
    .sort((a, b) => a.sortKey - b.sortKey)

  return scheduled
})

function capitalize(str) {
  if (!str) return ''
  return str.charAt(0).toUpperCase() + str.slice(1)
}

// Load plan data (edit mode)
async function loadPlan(id) {
  pageLoading.value = true
  try {
    const res = await plans.get(id)
    const plan = res.data ?? res

    form.title = plan.title || ''
    form.slug = plan.slug || ''
    form.description = plan.description || ''
    form.status = plan.status || 'inactive'
    form.level = plan.level ?? 0
    form.includes_plan_ids = plan.includes_plan_ids || []
    form.duration_type = plan.duration_type || 'lifetime'
    form.duration_days = plan.duration_days ?? null
    form.trial_days = plan.trial_days ?? 0
    form.grace_period_days = plan.grace_period_days ?? 0

    // Load anchor billing meta
    const planMeta = plan.meta || {}
    form.meta.billing_anchor_day = planMeta.billing_anchor_day ?? null

    // T17: load schedule
    schedule.scheduled_status = plan.scheduled_status || null
    schedule.scheduled_at = plan.scheduled_at || null

    form.rules = (plan.rules || []).map((r, index) => {
      const resourceId = String(r.resource_id ?? '0')
      const resourceLabel = r.resource_label || null

      // Pre-populate the search options for this rule so the select shows the label
      if (resourceId && resourceId !== '0' && resourceLabel) {
        ruleResourceOptions[index] = [{ id: resourceId, label: resourceLabel }]
      }

      return {
        resource_type: r.resource_type || 'post',
        resource_id: resourceId,
        resource_label: resourceLabel,
        drip_type: r.drip_type || 'immediate',
        drip_delay_days: r.drip_delay_days ?? null,
        drip_date: r.drip_date ?? null,
      }
    })

    slugManuallyEdited.value = true
  } catch (err) {
    ElMessage.error(err.message || 'Failed to load plan')
    router.push('/plans')
  } finally {
    pageLoading.value = false
  }
}

async function loadPlanOptions() {
  try {
    const res = await plans.options()
    const raw = res.data ?? res
    const opts = (Array.isArray(raw) ? raw : []).map((o) => ({
      id: o.id ?? Number(o.value),
      title: o.label ?? o.title,
    }))
    // Filter out the current plan when editing
    const currentId = route.params.id ? Number(route.params.id) : null
    planOptions.value = opts.filter((o) => o.id !== currentId)
  } catch {
    planOptions.value = []
  }
}

async function savePlan() {
  if (!formRef.value) return

  try {
    await formRef.value.validate()
  } catch {
    ElMessage.warning('Please fix the form errors before saving')
    return
  }

  saving.value = true
  try {
    // Build meta: only include billing_anchor_day for fixed_anchor
    const meta = form.duration_type === 'fixed_anchor'
      ? { billing_anchor_day: form.meta.billing_anchor_day }
      : {}

    const payload = {
      title: form.title,
      slug: form.slug,
      description: form.description,
      status: form.status,
      includes_plan_ids: form.includes_plan_ids,
      duration_type: form.duration_type,
      duration_days: form.duration_type === 'fixed_days' ? form.duration_days : null,
      trial_days: form.trial_days,
      grace_period_days: form.grace_period_days,
      level: form.level,
      meta,
      rules: form.rules.map((r) => {
        const rule = {
          resource_type: r.resource_type,
          resource_id: r.resource_id,
          drip_type: r.drip_type,
        }
        if (r.drip_type === 'delayed') {
          rule.drip_delay_days = r.drip_delay_days
        }
        if (r.drip_type === 'fixed_date') {
          rule.drip_date = r.drip_date
        }
        return rule
      }),
    }

    if (props.isNew) {
      await plans.create(payload)
      ElMessage.success('Plan created successfully')
    } else {
      await plans.update(route.params.id, payload)
      ElMessage.success('Plan updated successfully')
    }

    router.push('/plans')
  } catch (err) {
    ElMessage.error(err.message || 'Failed to save plan')
  } finally {
    saving.value = false
  }
}

// Linked Products loader
async function loadLinkedProducts() {
  if (productsLoaded.value || props.isNew) return
  productsLoading.value = true
  try {
    const res = await plans.linkedProducts(route.params.id)
    linkedProducts.value = res.data ?? res
  } catch {
    linkedProducts.value = []
  } finally {
    productsLoading.value = false
    productsLoaded.value = true
  }
}

// T15: Product search and link
function showLinkProductDialog() {
  productSearchQuery.value = ''
  productSearchResults.value = []
  selectedProduct.value = null
  linkProductVisible.value = true
  searchProducts()
}

function debouncedSearchProducts() {
  clearTimeout(productSearchTimer)
  productSearchTimer = setTimeout(searchProducts, 300)
}

async function searchProducts() {
  productSearchLoading.value = true
  try {
    const res = await plans.searchProducts({ search: productSearchQuery.value })
    productSearchResults.value = res.data ?? res
  } catch {
    productSearchResults.value = []
  } finally {
    productSearchLoading.value = false
  }
}

async function confirmLinkProduct() {
  if (!selectedProduct.value) return

  linkingProduct.value = true
  try {
    await plans.linkProduct(route.params.id, { product_id: selectedProduct.value.id })
    ElMessage.success('Product linked successfully')
    linkProductVisible.value = false
    productsLoaded.value = false
    loadLinkedProducts()
  } catch (err) {
    ElMessage.error(err.message || 'Failed to link product')
  } finally {
    linkingProduct.value = false
  }
}

async function confirmUnlinkProduct(row) {
  productsLoading.value = true
  try {
    await plans.unlinkProduct(route.params.id, row.feed_id)
    ElMessage.success('Product unlinked successfully')
    productsLoaded.value = false
    loadLinkedProducts()
  } catch (err) {
    ElMessage.error(err.message || 'Failed to unlink product')
    productsLoading.value = false
  }
}

// T17: Schedule methods
async function saveSchedule() {
  if (!schedule.new_status || !schedule.new_at) return
  scheduleSaving.value = true
  try {
    const res = await plans.schedule(route.params.id, {
      scheduled_status: schedule.new_status,
      scheduled_at: schedule.new_at,
    })
    const data = res.data ?? res
    schedule.scheduled_status = data.scheduled_status || schedule.new_status
    schedule.scheduled_at = data.scheduled_at || schedule.new_at
    schedule.new_status = ''
    schedule.new_at = ''
    ElMessage.success('Status change scheduled')
  } catch (err) {
    ElMessage.error(err.message || 'Failed to schedule status change')
  } finally {
    scheduleSaving.value = false
  }
}

async function clearSchedule() {
  scheduleSaving.value = true
  try {
    await plans.schedule(route.params.id, { scheduled_status: '', scheduled_at: '' })
    schedule.scheduled_status = null
    schedule.scheduled_at = null
    ElMessage.success('Schedule cleared')
  } catch (err) {
    ElMessage.error(err.message || 'Failed to clear schedule')
  } finally {
    scheduleSaving.value = false
  }
}

// Plan Members loader
async function loadPlanMembers(page = 1) {
  if (props.isNew) return
  planMembersLoading.value = true
  try {
    const res = await members.list({
      plan_id: route.params.id,
      per_page: planMembersPerPage,
      page,
    })
    const data = res.data ?? res
    planMembers.value = Array.isArray(data) ? data : (data.data ?? [])
    planMembersTotal.value = res.total ?? data.total ?? 0
    planMembersPage.value = page
  } catch {
    planMembers.value = []
  } finally {
    planMembersLoading.value = false
    planMembersLoaded.value = true
  }
}

function onMembersPageChange(page) {
  loadPlanMembers(page)
}

// Lazy-load tab data on switch
watch(activeTab, (tab) => {
  if (tab === 'products' && !productsLoaded.value) {
    loadLinkedProducts()
  }
  if (tab === 'members' && !planMembersLoaded.value) {
    loadPlanMembers()
  }
})

onMounted(() => {
  loadPlanOptions()
  loadResourceTypes()

  if (!props.isNew && route.params.id) {
    loadPlan(route.params.id)
  }
})
</script>

<style scoped>
.plan-editor-page {
}

.page-header {
  margin-bottom: 20px;
}

.page-header .fchub-page-title {
  margin-bottom: 0;
}

/* Back link -- FC pattern */
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

.section-header-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
}

.field-hint {
  font-size: 12px;
  color: var(--fchub-text-secondary);
  margin-top: 4px;
  line-height: 1.4;
  flex-basis: 100%;
}

.field-hint-inline {
  font-size: 11px;
  color: var(--fchub-text-secondary);
  margin-left: 8px;
  white-space: nowrap;
}

.rule-row {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  padding: 16px;
  margin-bottom: 12px;
  background: var(--el-fill-color-lighter, #fafafa);
  border: 1px solid var(--fchub-border-color);
  border-radius: 6px;
}

.rule-fields {
  flex: 1;
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
}

.rule-fields .el-form-item {
  margin-bottom: 0;
}

.remove-rule-btn {
  flex-shrink: 0;
  margin-top: 22px;
}

.rule-warning-icon {
  color: var(--el-color-warning);
  margin-left: 6px;
  vertical-align: middle;
  cursor: help;
}

.resource-type-source {
  float: right;
  color: var(--el-text-color-secondary);
  font-size: 12px;
}

.resource-hint {
  display: block;
  color: var(--el-text-color-secondary);
  font-size: 12px;
  line-height: 1.4;
  margin-top: 4px;
}

.form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  padding: 16px 24px;
  border-top: 1px solid var(--fchub-border-color);
  background: var(--fchub-card-bg);
  border-radius: 0 0 var(--fchub-radius-card) var(--fchub-radius-card);
}

</style>
