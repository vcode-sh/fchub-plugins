<template>
  <div class="plan-editor-page">
    <WorkspacePageHeader
      eyebrow="Memberships"
      :title="isNew ? 'Create membership plan' : 'Edit membership plan'"
      description="Build the offer, choose what it unlocks, then review the member experience."
      back-to="/plans"
      back-label="Back to plans"
    >
      <template #actions>
        <span class="page-status" :class="`is-${form.status}`">{{ planSummary.status }}</span>
      </template>
    </WorkspacePageHeader>

    <el-form
      ref="formRef"
      v-loading="pageLoading"
      :model="form"
      :rules="rules"
      label-position="top"
      class="plan-form"
      @submit.prevent="handlePrimaryAction"
    >
      <div class="plan-builder-shell">
        <PlanBuilderProgress
          class="plan-builder-progress"
          :steps="PLAN_BUILDER_STEPS"
          :active-step="activeStep"
          :completed-steps="completedSteps"
          @select="selectBuilderStep"
        />

        <PlanBuilderSummary
          class="plan-builder-summary-mobile"
          id-prefix="mobile-plan-summary"
          :summary="planSummary"
          :mobile-open="mobileSummaryOpen"
          @toggle-mobile="mobileSummaryOpen = !mobileSummaryOpen"
        />

        <main class="plan-builder-content">
          <PlanOfferStep
            v-show="activeStep === 'offer'"
            v-model:advanced-open="advancedOpen"
            :form="form"
            :is-new="isNew"
            :duration-options="durationOptions"
            :plan-options="planOptions"
            :slug-preview-loading="slugPreviewLoading"
            :slug-preview-error="slugPreviewError"
            :slug-manually-edited="slugManuallyEdited"
            :slug-available="slugAvailable"
            :term-hint-text="termHintText"
            :schedule="schedule"
            :schedule-saving="scheduleSaving"
            :format-date-time="formatWpDateTime"
            :date-picker-format="wpDatePickerFormat"
            :date-time-picker-format="wpDateTimePickerFormat"
            @title-input="onTitleInput"
            @slug-input="onSlugInput"
            @duration-select="selectDuration"
            @term-mode-change="onTermModeChange"
            @save-schedule="saveSchedule"
            @clear-schedule="clearSchedule"
          />

          <PlanAccessStep
            v-show="activeStep === 'access'"
            v-model:selected-space-group-id="selectedSpaceGroupId"
            :form="form"
            :has-fc-space-resource-type="hasFcSpaceResourceType"
            :has-read-only-rules="hasReadOnlyRules"
            :space-groups="spaceGroups"
            :space-groups-loading="spaceGroupsLoading"
            :resource-type-groups="resourceTypeGroups"
            :special-page-options="specialPageOptions"
            :get-type-config="getTypeConfig"
            :resource-id-rules="resourceIdRules"
            :rule-summary="ruleSummary"
            :is-past-date="isPastDate"
            :date-picker-format="wpDatePickerFormat"
            @add-space-group="addSelectedSpaceGroup"
            @add-rule="addRule"
            @remove-rule="removeRule"
            @resource-type-change="onResourceTypeChange"
            @drip-type-change="onDripTypeChange"
          />

          <PlanReviewStep
            v-show="activeStep === 'review'"
            :summary="planSummary"
            :description="form.description"
            :rule-count="form.rules.length"
            :status="form.status"
          />
        </main>

        <PlanBuilderSummary
          class="plan-builder-summary-desktop"
          id-prefix="desktop-plan-summary"
          :summary="planSummary"
          :mobile-open="mobileSummaryOpen"
          @toggle-mobile="mobileSummaryOpen = !mobileSummaryOpen"
        />
      </div>

      <PlanManagementTabs
        v-if="!isNew || dripPreviewRules.length > 0"
        v-model:active-tab="activeTab"
        :is-new="isNew"
        :drip-preview-rules="dripPreviewRules"
        :products-loading="productsLoading"
        :linked-products="linkedProducts"
        :linked-products-error="linkedProductsError"
        :members-loading="planMembersLoading"
        :members="planMembers"
        :members-error="planMembersError"
        :members-total="planMembersTotal"
        :members-page="planMembersPage"
        :members-per-page="planMembersPerPage"
        :format-date="formatWpDate"
        :members-link="`/members?plan_id=${route.params.id}`"
        @show-link="showLinkProductDialog"
        @retry-products="loadLinkedProducts"
        @unlink="confirmUnlinkProduct"
        @members-page-change="onMembersPageChange"
        @retry-members="loadPlanMembers(planMembersPage)"
      />

      <footer class="form-actions">
        <el-button @click="router.push('/plans')">Cancel</el-button>
        <div class="form-actions-primary">
          <el-button v-if="activeStep !== 'offer'" @click="goToPreviousStep">Back</el-button>
          <el-button type="primary" native-type="submit" :loading="saving">
            {{ primaryActionLabel }}
          </el-button>
        </div>
      </footer>
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
import { ref, reactive, computed, nextTick, onMounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { ElMessage } from 'element-plus'
import { plans, members, content } from '@/api/index.js'
import { formatWpDate, formatWpDateTime, wpDatePickerFormat, wpDateTimePickerFormat } from '@/utils/wpDate.js'
import PlanAccessStep from '@/components/plans/PlanAccessStep.vue'
import PlanManagementTabs from '@/components/plans/PlanManagementTabs.vue'
import PlanOfferStep from '@/components/plans/PlanOfferStep.vue'
import PlanReviewStep from '@/components/plans/PlanReviewStep.vue'
import PlanLinkProductDialog from '@/components/plans/PlanLinkProductDialog.vue'
import PlanBuilderProgress from '@/components/plans/PlanBuilderProgress.vue'
import PlanBuilderSummary from '@/components/plans/PlanBuilderSummary.vue'
import WorkspacePageHeader from '@/components/workspace/WorkspacePageHeader.vue'
import { usePlanAccessRules } from '@/composables/plans/usePlanAccessRules.js'
import { usePlanMembers } from '@/composables/plans/usePlanMembers.js'
import { usePlanProducts } from '@/composables/plans/usePlanProducts.js'
import { usePlanSchedule } from '@/composables/plans/usePlanSchedule.js'
import { usePlanSlugPreview } from '@/composables/plans/usePlanSlugPreview.js'
import {
  PLAN_BUILDER_STEPS,
  buildPlanSummary,
  hasAdvancedPlanSettings,
  isOfferStepComplete,
  isAdvancedValidationField,
  isValidPlanSlug,
  previousBuilderStep,
  stepForValidationFields,
} from './planEditorUi.js'
import {
  applyDurationSelection,
  applyMembershipTermMode,
  buildPlanSavePayload,
  createPlanForm,
  membershipTermHint,
  normalisePlanForm,
} from './planEditorForm.js'

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
const planOptions = ref([])
const activeTab = ref(props.isNew ? 'drip' : 'products')
const activeStep = ref('offer')
const advancedOpen = ref(false)
const mobileSummaryOpen = ref(false)

const form = reactive(createPlanForm())

const {
  slugManuallyEdited,
  slugPreviewLoading,
  slugPreviewError,
  slugAvailable,
  onTitleInput,
  onSlugInput,
  flushSlugPreview,
  markPersistedSlug,
} = usePlanSlugPreview({
  plansApi: plans,
  form,
  formRef,
  isNew: () => props.isNew,
  planId: () => route.params.id,
})

const {
  specialPageOptions,
  resourceTypeGroups,
  spaceGroups,
  spaceGroupsLoading,
  selectedSpaceGroupId,
  hasReadOnlyRules,
  hasFcSpaceResourceType,
  getTypeConfig,
  addRule,
  removeRule,
  onDripTypeChange,
  addSelectedSpaceGroup,
  resourceIdRules,
  ruleSummary,
  onResourceTypeChange,
  loadResourceTypes,
  loadSpaceGroups,
  isPastDate,
} = usePlanAccessRules({
  contentApi: content,
  rules: () => form.rules,
})

const {
  linkedProducts,
  productsLoading,
  productsLoaded,
  linkedProductsError,
  linkProductVisible,
  productSearchQuery,
  productSearchResults,
  productSearchLoading,
  selectedProduct,
  linkingProduct,
  loadLinkedProducts,
  showLinkProductDialog,
  debouncedSearchProducts,
  confirmLinkProduct,
  confirmUnlinkProduct,
} = usePlanProducts({
  plansApi: plans,
  planId: () => route.params.id,
  isNew: () => props.isNew,
})

const {
  schedule,
  scheduleSaving,
  hydrateSchedule,
  saveSchedule,
  clearSchedule,
} = usePlanSchedule({
  plansApi: plans,
  planId: () => route.params.id,
})

const {
  planMembers,
  planMembersLoading,
  planMembersLoaded,
  planMembersError,
  planMembersPage,
  planMembersPerPage,
  planMembersTotal,
  loadPlanMembers,
  onMembersPageChange,
} = usePlanMembers({
  membersApi: members,
  planId: () => route.params.id,
  isNew: () => props.isNew,
  perPage: 10,
})

const durationOptions = Object.freeze([
  {
    value: 'lifetime',
    label: 'Lifetime access',
    description: 'Access never expires unless it is manually revoked.',
  },
  {
    value: 'fixed_days',
    label: 'Fixed duration',
    description: 'Access ends after a set number of days.',
  },
  {
    value: 'subscription_mirror',
    label: 'Mirror subscription',
    description: 'Access follows the linked subscription lifecycle.',
  },
  {
    value: 'fixed_anchor',
    label: 'Monthly billing anchor',
    description: 'Access renews against a calendar day each month.',
  },
])

const completedSteps = computed(() => [
  ...(isOfferStepComplete(form) ? ['offer'] : []),
  ...(form.rules.length > 0 ? ['access'] : []),
])

const planSummary = computed(() => buildPlanSummary(form, form.rules.length))

const primaryActionLabel = computed(() => {
  if (activeStep.value === 'offer') return 'Continue'
  if (activeStep.value === 'access') return 'Review plan'
  return props.isNew ? 'Create plan' : 'Save changes'
})

function validateSlug(_rule, value, callback) {
  if (!form.title) {
    callback()
    return
  }

  if (slugPreviewError.value) {
    callback(new Error(slugPreviewError.value))
    return
  }

  if (!value) {
    callback(new Error('Enter a slug for this title'))
    return
  }

  if (!isValidPlanSlug(value)) {
    callback(new Error('Wait for WordPress to generate a valid slug'))
    return
  }

  if (!slugAvailable.value) {
    callback(new Error('This slug is already in use'))
    return
  }

  callback()
}

const rules = {
  title: [
    { required: true, message: 'Title is required', trigger: 'blur' },
  ],
  slug: [
    { validator: validateSlug, trigger: ['blur', 'change'] },
  ],
}

function onTermModeChange(mode) {
  applyMembershipTermMode(form, mode)
}

const termHintText = computed(() => membershipTermHint(form.duration_type))

function selectDuration(value) {
  applyDurationSelection(form, value)
}

function selectBuilderStep(step) {
  if (!PLAN_BUILDER_STEPS.some(({ id }) => id === step)) return
  activeStep.value = step
}

function goToPreviousStep() {
  activeStep.value = previousBuilderStep(activeStep.value)
}

async function validateOfferStep() {
  await flushSlugPreview()

  const fields = ['title', 'slug']
  if (form.duration_type === 'fixed_days') fields.push('duration_days')
  if (form.duration_type === 'fixed_anchor') fields.push('meta.billing_anchor_day')

  try {
    await formRef.value?.validateField(fields)
    return true
  } catch (validationFields) {
    await revealValidationError(validationFields || { title: [] })
    ElMessage.warning('Please complete the highlighted offer details')
    return false
  }
}

async function handlePrimaryAction() {
  if (saving.value) return

  if (activeStep.value === 'offer') {
    if (await validateOfferStep()) activeStep.value = 'access'
    return
  }

  if (activeStep.value === 'access') {
    try {
      await formRef.value?.validate()
      activeStep.value = 'review'
    } catch (validationFields) {
      await revealValidationError(validationFields)
      ElMessage.warning('Please fix the highlighted content access details')
    }
    return
  }

  await savePlan()
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
    Object.assign(form, normalisePlanForm(plan))
    advancedOpen.value = hasAdvancedPlanSettings(form)

    hydrateSchedule(plan)

    markPersistedSlug()
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

async function revealValidationError(fields = {}) {
  const firstField = Object.keys(fields)[0]

  activeStep.value = stepForValidationFields(fields)
  if (isAdvancedValidationField(firstField)) {
    advancedOpen.value = true
  }

  await nextTick()

  if (firstField) {
    formRef.value?.scrollToField(firstField)
  }

  const firstInvalidControl = document.querySelector(
    '#fchub-memberships-app .el-form-item.is-error input, '
      + '#fchub-memberships-app .el-form-item.is-error textarea, '
      + '#fchub-memberships-app .el-form-item.is-error button',
  )
  firstInvalidControl?.focus()
}

async function savePlan() {
  if (!formRef.value || saving.value) return

  saving.value = true

  try {
    await formRef.value.validate()
  } catch (fields) {
    await revealValidationError(fields)
    ElMessage.warning('Please fix the highlighted fields before saving')
    saving.value = false
    return
  }

  try {
    const payload = buildPlanSavePayload(form, {
      isNew: props.isNew,
      slugManuallyEdited: slugManuallyEdited.value,
    })

    if (props.isNew) {
      await plans.create(payload)
      ElMessage.success('Plan created successfully')
    } else {
      await plans.update(route.params.id, payload)
      ElMessage.success('Plan updated successfully')
    }

    await router.push('/plans')
  } catch (err) {
    ElMessage.error(err.message || 'Failed to save plan')
    saving.value = false
  }
}

// Lazy-load tab data on switch
watch(activeTab, (tab) => {
  if (tab === 'products' && !productsLoaded.value) {
    loadLinkedProducts()
  }
  if (tab === 'members' && !planMembersLoaded.value) {
    loadPlanMembers()
  }
}, { immediate: true })

onMounted(() => {
  loadPlanOptions()
  loadResourceTypes()
  loadSpaceGroups()

  if (!props.isNew && route.params.id) {
    loadPlan(route.params.id)
  }
})
</script>

<style scoped>
.plan-editor-page {
  max-width: 1320px;
  margin: 0 auto;
  padding: 0 4px 40px;
}

.page-status {
  flex: 0 0 auto;
  display: inline-flex;
  align-items: center;
  padding: 6px 10px;
  border: 1px solid var(--fchub-border-color);
  border-radius: 999px;
  color: var(--fchub-text-secondary);
  background: var(--fchub-card-bg);
  font-size: 11px;
  font-weight: 700;
}

.page-status.is-active {
  color: var(--el-color-success-dark-2, #3b8b54);
  border-color: color-mix(in srgb, var(--el-color-success) 30%, var(--fchub-border-color));
  background: color-mix(in srgb, var(--el-color-success) 8%, var(--fchub-card-bg));
}

.plan-form,
.plan-builder-content {
  min-width: 0;
}

.plan-builder-shell {
  display: grid;
  grid-template-columns: 190px minmax(420px, 1fr) 260px;
  gap: 20px;
  align-items: start;
}

.plan-builder-progress,
.plan-builder-summary-desktop {
  position: sticky;
  top: 48px;
}

.plan-builder-summary-mobile {
  display: none;
}

.form-actions {
  position: sticky;
  z-index: 10;
  bottom: 0;
  display: flex;
  justify-content: space-between;
  gap: 12px;
  margin: 20px 0 0 210px;
  padding: 13px 16px;
  border: 1px solid var(--fchub-border-color);
  border-radius: 10px;
  background: var(--fchub-card-bg);
  box-shadow: 0 -8px 20px rgb(0 0 0 / 4%);
}

.form-actions-primary {
  display: flex;
  gap: 10px;
}

@media (max-width: 1180px) {
  .plan-builder-shell {
    grid-template-columns: 180px minmax(380px, 1fr) 230px;
    gap: 16px;
  }

  .form-actions {
    margin-left: 196px;
  }
}

@media (max-width: 960px) {
  .plan-builder-shell {
    grid-template-columns: 170px minmax(0, 1fr);
  }

  .plan-builder-summary-desktop {
    display: none;
  }

  .plan-builder-summary-mobile {
    grid-column: 2;
    display: block;
  }

  .plan-builder-content {
    grid-column: 2;
  }

  .plan-builder-progress {
    grid-row: 1 / span 2;
  }

  .form-actions {
    margin-left: 186px;
  }
}

@media (max-width: 782px) {
  .plan-editor-page {
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
    padding: 0 0 28px;
    overflow-x: clip;
  }

  .page-status {
    margin-top: 2px;
  }

  .plan-builder-shell {
    width: 100%;
    display: flex;
    flex-direction: column;
    gap: 12px;
  }

  .plan-builder-progress,
  .plan-builder-summary-mobile,
  .plan-builder-content {
    position: static;
    width: 100%;
    max-width: 100%;
    grid-column: auto;
    box-sizing: border-box;
  }

  .plan-builder-progress {
    order: 1;
  }

  .plan-builder-summary-mobile {
    order: 2;
  }

  .plan-builder-content {
    order: 3;
  }

  .form-actions {
    gap: 8px;
    margin-left: 0;
    padding: 11px 12px;
  }

  .form-actions-primary {
    min-width: 0;
    justify-content: flex-end;
  }

  .form-actions .el-button {
    margin-left: 0;
  }
}
</style>
