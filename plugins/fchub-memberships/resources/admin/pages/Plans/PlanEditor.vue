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
              <el-option label="Draft" value="draft" />
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
            <el-select v-model="form.duration_type" style="width: 280px">
              <el-option label="Lifetime (never expires)" value="lifetime" />
              <el-option label="Fixed Duration (X days)" value="fixed_days" />
              <el-option label="Mirror Subscription" value="subscription_mirror" />
            </el-select>
            <div class="field-hint">
              Determines how long membership access lasts. Applies to all linked products unless overridden.
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
          <template v-if="!isNew">
            <el-divider content-position="left">Schedule Status Change</el-divider>

            <div v-if="schedule.scheduled_status" class="schedule-current">
              <el-tag type="warning" size="small">Scheduled</el-tag>
              <span>
                Status will change to <strong>{{ schedule.scheduled_status }}</strong>
                on <strong>{{ formatWpDateTime(schedule.scheduled_at) }}</strong>
              </span>
              <el-button size="small" text type="danger" @click="clearSchedule" :loading="scheduleSaving">
                Clear
              </el-button>
            </div>

            <div class="schedule-form-row">
              <el-form-item label="New Status">
                <el-select v-model="schedule.new_status" placeholder="Select status..." style="width: 180px" clearable>
                  <el-option label="Active" value="active" />
                  <el-option label="Inactive" value="inactive" />
                  <el-option label="Archived" value="archived" />
                </el-select>
              </el-form-item>
              <el-form-item label="Date & Time">
                <el-date-picker
                  v-model="schedule.new_at"
                  type="datetime"
                  placeholder="Select date and time"
                  :format="wpDateTimePickerFormat"
                  value-format="YYYY-MM-DD HH:mm:ss"
                  style="width: 240px"
                />
              </el-form-item>
              <el-button
                size="small"
                type="primary"
                plain
                style="margin-top: 30px"
                :disabled="!schedule.new_status || !schedule.new_at"
                :loading="scheduleSaving"
                @click="saveSchedule"
              >
                Set Schedule
              </el-button>
            </div>
            <div class="field-hint" style="margin-top: -8px">
              Schedule an automatic status change for this plan at a future date.
            </div>
          </template>
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
          <div v-loading="productsLoading">
            <div class="tab-header-row">
              <p class="tab-description">FluentCart products and integration feeds linked to this plan.</p>
              <el-button size="small" type="primary" plain @click="showLinkProductDialog">
                <el-icon><Plus /></el-icon>
                Link Product
              </el-button>
            </div>
            <el-table v-if="linkedProducts.length > 0" :data="linkedProducts" stripe>
              <el-table-column prop="product_title" label="Product" min-width="200" />
              <el-table-column prop="feed_title" label="Feed" min-width="160" />
              <el-table-column prop="price" label="Price" width="120">
                <template #default="{ row }">
                  {{ row.price ? `$${(row.price / 100).toFixed(2)}` : '-' }}
                </template>
              </el-table-column>
              <el-table-column label="Billing" width="140">
                <template #default="{ row }">
                  {{ row.billing_period ? `Every ${row.billing_interval || 1} ${row.billing_period}(s)` : 'One-time' }}
                </template>
              </el-table-column>
              <el-table-column prop="status" label="Status" width="100">
                <template #default="{ row }">
                  <el-tag :type="row.status === 'active' ? 'success' : 'info'" size="small">
                    {{ row.status }}
                  </el-tag>
                </template>
              </el-table-column>
              <el-table-column label="" width="80" align="center">
                <template #default="{ row }">
                  <el-button
                    type="danger"
                    text
                    size="small"
                    @click="confirmUnlinkProduct(row)"
                  >
                    <el-icon><Delete /></el-icon>
                  </el-button>
                </template>
              </el-table-column>
            </el-table>
            <el-empty v-else description="No FluentCart products linked to this plan yet." :image-size="60">
              <template #description>
                <p>No FluentCart products linked to this plan yet.</p>
                <p style="font-size: 12px; color: #909399">Click "Link Product" to create an integration feed.</p>
              </template>
            </el-empty>
          </div>
        </el-tab-pane>

        <el-tab-pane v-if="!isNew" label="Members" name="members" :lazy="true">
          <div v-loading="planMembersLoading">
            <div class="tab-header-row">
              <p class="tab-description">Members who have access through this plan.</p>
              <router-link :to="`/members?plan=${route.params.id}`">
                <el-button size="small" text type="primary">View All →</el-button>
              </router-link>
            </div>
            <el-table v-if="planMembers.length > 0" :data="planMembers" stripe>
              <el-table-column prop="user_email" label="Member" min-width="200" />
              <el-table-column prop="status" label="Status" width="100">
                <template #default="{ row }">
                  <el-tag
                    :type="row.status === 'active' ? 'success' : row.status === 'expired' ? 'info' : 'danger'"
                    size="small"
                  >
                    {{ row.status }}
                  </el-tag>
                </template>
              </el-table-column>
              <el-table-column label="Granted" width="160">
                <template #default="{ row }">
                  {{ formatWpDate(row.created_at) }}
                </template>
              </el-table-column>
              <el-table-column prop="expires_at" label="Expires" width="160">
                <template #default="{ row }">
                  {{ row.expires_at ? formatWpDate(row.expires_at) : 'Never' }}
                </template>
              </el-table-column>
            </el-table>
            <el-empty v-else description="No members have been granted this plan yet." :image-size="60" />
            <el-pagination
              v-if="planMembersTotal > planMembersPerPage"
              :current-page="planMembersPage"
              :page-size="planMembersPerPage"
              :total="planMembersTotal"
              layout="prev, pager, next"
              style="margin-top: 16px; justify-content: flex-end"
              @current-change="onMembersPageChange"
            />
          </div>
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

    <!-- T15: Link Product Dialog -->
    <el-dialog v-model="linkProductVisible" title="Link Product" width="520px" :close-on-click-modal="false">
      <p style="font-size: 13px; color: var(--fchub-text-secondary); margin: 0 0 16px 0">
        Search for a FluentCart product and create a membership integration feed.
      </p>
      <el-input
        v-model="productSearchQuery"
        placeholder="Search products..."
        clearable
        @input="debouncedSearchProducts"
      />
      <div v-loading="productSearchLoading" class="product-search-results">
        <div
          v-for="p in productSearchResults"
          :key="p.id"
          class="product-search-item"
          :class="{ selected: selectedProduct?.id === p.id }"
          @click="selectedProduct = p"
        >
          <div class="product-search-item-title">{{ p.title }}</div>
          <div class="product-search-item-meta">
            <span>{{ p.price ? `$${(p.price / 100).toFixed(2)}` : 'Free' }}</span>
            <span v-if="p.billing_period"> / {{ p.billing_period }}</span>
            <el-tag v-if="p.status" size="small" :type="p.status === 'active' ? 'success' : 'info'" style="margin-left: 8px">
              {{ p.status }}
            </el-tag>
          </div>
        </div>
        <div v-if="!productSearchLoading && productSearchResults.length === 0 && productSearchQuery" class="product-search-empty">
          No products found.
        </div>
      </div>
      <template #footer>
        <el-button @click="linkProductVisible = false">Cancel</el-button>
        <el-button
          type="primary"
          :disabled="!selectedProduct"
          :loading="linkingProduct"
          @click="confirmLinkProduct"
        >
          Link Product
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { plans, members, content } from '@/api/index.js'
import { formatWpDate, formatWpDateTime, wpDatePickerFormat, wpDateTimePickerFormat } from '@/utils/wpDate.js'

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
  status: 'draft',
  includes_plan_ids: [],
  rules: [],
  duration_type: 'lifetime',
  duration_days: null,
  trial_days: 0,
  grace_period_days: 0,
  level: 0,
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
    form.status = plan.status || 'draft'
    form.level = plan.level ?? 0
    form.includes_plan_ids = plan.includes_plan_ids || []
    form.duration_type = plan.duration_type || 'lifetime'
    form.duration_days = plan.duration_days ?? null
    form.trial_days = plan.trial_days ?? 0
    form.grace_period_days = plan.grace_period_days ?? 0

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

  try {
    await ElMessageBox.confirm(
      `This will create a FluentCart integration feed linking "${selectedProduct.value.title}" to this plan. Continue?`,
      'Link Product',
      { confirmButtonText: 'Link', cancelButtonText: 'Cancel', type: 'info' },
    )
  } catch {
    return
  }

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
  try {
    await ElMessageBox.confirm(
      `Remove the integration feed linking "${row.product_title}" to this plan? This will not affect existing memberships.`,
      'Unlink Product',
      { confirmButtonText: 'Unlink', cancelButtonText: 'Cancel', type: 'warning' },
    )
  } catch {
    return
  }

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
      plan: route.params.id,
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

.tab-description {
  font-size: 13px;
  color: var(--fchub-text-secondary);
  margin: 0 0 16px 0;
}

.tab-header-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
}

.tab-header-row .tab-description {
  margin-bottom: 0;
}

/* T15: Product search dialog */
.product-search-results {
  margin-top: 12px;
  max-height: 300px;
  overflow-y: auto;
  min-height: 60px;
}

.product-search-item {
  padding: 10px 12px;
  border: 1px solid var(--fchub-border-color);
  border-radius: 6px;
  margin-bottom: 8px;
  cursor: pointer;
  transition: border-color 0.15s, background-color 0.15s;
}

.product-search-item:hover {
  border-color: var(--el-color-primary-light-5);
  background: var(--el-fill-color-lighter, #fafafa);
}

.product-search-item.selected {
  border-color: var(--el-color-primary);
  background: var(--el-color-primary-light-9);
}

.product-search-item-title {
  font-weight: 500;
  font-size: 14px;
}

.product-search-item-meta {
  font-size: 12px;
  color: var(--fchub-text-secondary);
  margin-top: 4px;
}

.product-search-empty {
  text-align: center;
  padding: 20px 0;
  color: var(--fchub-text-secondary);
  font-size: 13px;
}

/* T17: Schedule section */
.schedule-current {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  background: var(--el-color-warning-light-9);
  border: 1px solid var(--el-color-warning-light-5);
  border-radius: 6px;
  margin-bottom: 16px;
  font-size: 13px;
}

.schedule-form-row {
  display: flex;
  align-items: flex-start;
  gap: 16px;
  flex-wrap: wrap;
}
</style>
