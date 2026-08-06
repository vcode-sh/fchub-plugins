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
          <section v-show="activeStep === 'offer'" class="builder-panel" aria-labelledby="offer-step-heading">
            <div class="builder-panel-heading">
              <span class="builder-panel-step">Step 1 of 3</span>
              <h2 id="offer-step-heading">Shape the offer</h2>
              <p>Give members a clear name and decide how long their access lasts.</p>
            </div>

            <div class="builder-form-section">
              <el-form-item label="Plan name" prop="title">
                <el-input
                  v-model="form.title"
                  placeholder="e.g. Gold Membership"
                  size="large"
                  @input="onTitleInput"
                />
              </el-form-item>

              <el-form-item label="Short description" prop="description">
                <el-input
                  v-model="form.description"
                  type="textarea"
                  :rows="3"
                  maxlength="240"
                  show-word-limit
                  placeholder="What will members get from this plan?"
                />
              </el-form-item>

              <div class="offer-field-grid">
                <el-form-item label="Availability" prop="status">
                  <el-select v-model="form.status">
                    <el-option label="Active" value="active" />
                    <el-option label="Inactive" value="inactive" />
                    <el-option label="Archived" value="archived" />
                  </el-select>
                  <div class="field-hint">Inactive keeps the plan hidden while you finish setting it up.</div>
                </el-form-item>
              </div>
            </div>

            <div class="builder-form-section" aria-labelledby="plan-duration-heading">
              <div class="section-heading-inline">
                <div>
                  <h3 id="plan-duration-heading">How long does access last?</h3>
                  <p>Choose the rule that best matches the offer.</p>
                </div>
              </div>

              <el-form-item prop="duration_type" class="duration-form-item">
                <div class="duration-options" role="group" aria-labelledby="plan-duration-heading">
                  <button
                    v-for="option in durationOptions"
                    :key="option.value"
                    type="button"
                    class="duration-option"
                    :class="{ 'is-selected': form.duration_type === option.value }"
                    :aria-pressed="String(form.duration_type === option.value)"
                    @click="selectDuration(option.value)"
                  >
                    <span class="duration-radio" aria-hidden="true"></span>
                    <span>
                      <strong>{{ option.label }}</strong>
                      <small>{{ option.description }}</small>
                    </span>
                  </button>
                </div>
              </el-form-item>

              <el-form-item
                v-if="form.duration_type === 'fixed_days'"
                label="Duration (days)"
                prop="duration_days"
                :rules="[{ required: true, message: 'Enter the access duration', trigger: 'blur' }]"
                class="conditional-duration-field"
              >
                <el-input-number v-model="form.duration_days" :min="1" :max="36500" controls-position="right" />
                <div class="field-hint">Access ends this many days after it begins.</div>
              </el-form-item>

              <el-form-item
                v-if="form.duration_type === 'fixed_anchor'"
                label="Billing anchor day"
                prop="meta.billing_anchor_day"
                :rules="[{ required: true, message: 'Choose a day from 1 to 31', trigger: 'blur' }]"
                class="conditional-duration-field"
              >
                <el-input-number v-model="form.meta.billing_anchor_day" :min="1" :max="31" controls-position="right" />
                <div class="field-hint">Shorter months use their final calendar day.</div>
              </el-form-item>
            </div>

            <section class="plan-advanced-section" aria-labelledby="advanced-settings-heading">
              <button
                type="button"
                class="advanced-settings-toggle"
                :aria-expanded="String(advancedOpen)"
                aria-controls="plan-advanced-settings"
                @click="advancedOpen = !advancedOpen"
              >
                <span>
                  <strong id="advanced-settings-heading">Advanced settings</strong>
                  <small>Slug, hierarchy, plan inclusion, membership term, trial and grace period</small>
                </span>
                <el-icon :class="{ 'is-open': advancedOpen }"><ArrowDown /></el-icon>
              </button>

              <div v-show="advancedOpen" id="plan-advanced-settings" class="advanced-settings-content">
                <el-form-item label="Slug" prop="slug">
                  <el-input
                    v-model="form.slug"
                    placeholder="e.g. gold-membership"
                    :loading="slugPreviewLoading"
                    @input="onSlugInput"
                  >
                    <template #prepend>/</template>
                  </el-input>
                  <div class="field-hint">
                    <span v-if="slugPreviewLoading">Checking the exact WordPress slug…</span>
                    <span v-else-if="slugPreviewError" class="field-hint--error">{{ slugPreviewError }}</span>
                    <span v-else-if="slugManuallyEdited && !slugAvailable" class="field-hint--error">This slug is already used by another plan.</span>
                    <span v-else>
                      {{ slugManuallyEdited ? 'Custom slug. Clear the field to return to automatic mode.' : 'Generated by WordPress from the plan name.' }}
                    </span>
                  </div>
                </el-form-item>

                <div class="advanced-field-grid">
                  <el-form-item label="Level" prop="level">
                    <el-input-number v-model="form.level" :min="0" :max="100" controls-position="right" />
                    <div class="field-hint">Used only by Upgrade Only membership mode.</div>
                  </el-form-item>

                  <el-form-item label="Trial period (days)" prop="trial_days">
                    <el-input-number v-model="form.trial_days" :min="0" :max="365" controls-position="right" />
                    <div class="field-hint">Leave at 0 for no trial.</div>
                  </el-form-item>

                  <el-form-item label="Grace period (days)" prop="grace_period_days">
                    <el-input-number v-model="form.grace_period_days" :min="0" :max="365" controls-position="right" />
                    <div class="field-hint">Leave at 0 to revoke access immediately.</div>
                  </el-form-item>
                </div>

                <el-form-item label="Includes plans" prop="includes_plan_ids">
                  <el-select v-model="form.includes_plan_ids" multiple filterable placeholder="Select plans to include...">
                    <el-option v-for="opt in planOptions" :key="opt.id" :label="opt.title" :value="opt.id" />
                  </el-select>
                  <div class="field-hint">Members also receive access granted by the selected plans.</div>
                </el-form-item>

                <el-divider content-position="left">Membership term</el-divider>

                <el-form-item label="Membership term">
                  <el-select v-model="form.meta.membership_term.mode" @change="onTermModeChange">
                    <el-option label="No limit" value="none" />
                    <el-option label="1 year" value="1y" />
                    <el-option label="2 years" value="2y" />
                    <el-option label="3 years" value="3y" />
                    <el-option label="Custom" value="custom" />
                    <el-option label="Specific date" value="date" />
                  </el-select>
                  <div class="field-hint">{{ termHintText }}</div>
                </el-form-item>

                <div v-if="form.meta.membership_term.mode === 'custom'" class="term-custom-row">
                  <el-form-item label="Term length">
                    <el-input-number v-model="form.meta.membership_term.value" :min="1" :max="999" controls-position="right" />
                  </el-form-item>
                  <el-form-item label="Term unit">
                    <el-select v-model="form.meta.membership_term.unit">
                      <el-option label="Days" value="days" />
                      <el-option label="Weeks" value="weeks" />
                      <el-option label="Months" value="months" />
                      <el-option label="Years" value="years" />
                    </el-select>
                  </el-form-item>
                </div>

                <el-form-item v-if="form.meta.membership_term.mode === 'date'" label="Term end date">
                  <el-date-picker
                    v-model="form.meta.membership_term.date"
                    type="date"
                    placeholder="Select end date"
                    :format="wpDatePickerFormat"
                    value-format="YYYY-MM-DD"
                  />
                </el-form-item>

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
              </div>
            </section>
          </section>

          <section v-show="activeStep === 'access'" class="builder-panel" aria-labelledby="access-step-heading">
            <div class="builder-panel-heading access-rules-heading">
              <div>
                <span class="builder-panel-step">Step 2 of 3</span>
                <h2 id="access-step-heading">Choose content access</h2>
                <p>Define what members unlock now or later. You can safely skip this step.</p>
              </div>
              <div class="access-rules-actions">
                <el-select
                  v-if="hasFcSpaceResourceType && !hasReadOnlyRules"
                  v-model="selectedSpaceGroupId"
                  class="space-group-selector"
                  clearable
                  filterable
                  :loading="spaceGroupsLoading"
                  placeholder="Add Spaces from group"
                  @change="addSelectedSpaceGroup"
                >
                  <el-option
                    v-for="group in spaceGroups"
                    :key="group.id"
                    :label="`${group.label} (${group.spaces.length})`"
                    :value="group.id"
                    :disabled="group.spaces.length === 0"
                  />
                </el-select>
                <el-button v-if="form.rules.length" :disabled="hasReadOnlyRules" @click="addRule">
                  <el-icon><Plus /></el-icon>
                  Add content access
                </el-button>
              </div>
            </div>

            <el-alert
              v-if="hasReadOnlyRules"
              title="Legacy lesson access is preserved as read-only until its migration is available."
              description="All content rule controls are locked, and the complete rule set will be preserved unchanged when you save other plan details."
              type="info"
              :closable="false"
              show-icon
            />

            <div v-if="form.rules.length === 0" class="access-empty-state">
              <div class="access-empty-icon" aria-hidden="true"><el-icon><Lock /></el-icon></div>
              <h3>No content access yet</h3>
              <p>Members can still receive the plan. Add a rule when it should unlock protected content.</p>
              <el-button type="primary" class="empty-state-action" @click="addRule">
                <el-icon><Plus /></el-icon>
                Add content access
              </el-button>
            </div>

            <article
              v-for="(rule, index) in form.rules"
              :key="index"
              class="rule-row"
              :aria-labelledby="`rule-title-${index}`"
            >
              <header class="rule-row-header">
                <div>
                  <span>CONTENT RULE {{ index + 1 }}</span>
                  <h3 :id="`rule-title-${index}`">{{ ruleSummary(rule) }}</h3>
                </div>
                <el-button
                  type="danger"
                  text
                  class="remove-rule-btn"
                  :disabled="hasReadOnlyRules"
                  :aria-label="`Remove rule ${index + 1}`"
                  @click="removeRule(index)"
                >
                  <el-icon><Delete /></el-icon>
                  <span>Remove</span>
                </el-button>
              </header>

              <div class="rule-fields">
                <el-form-item
                  :prop="`rules.${index}.resource_type`"
                  :rules="[{ required: true, message: 'Choose a resource type', trigger: 'change' }]"
                  label="Resource type"
                >
                  <el-select
                    v-model="rule.resource_type"
                    placeholder="Type"
                    :disabled="isPlanRuleControlLocked(form.rules, rule)"
                    @change="onResourceTypeChange(index, rule)"
                  >
                    <el-option-group v-for="group in resourceTypeGroups" :key="group.key" :label="group.label">
                      <el-option v-for="type in group.types" :key="type.value" :label="type.displayLabel" :value="type.value">
                        <span>{{ type.label }}</span>
                        <span v-if="type.source" class="resource-type-source">{{ type.source }}</span>
                      </el-option>
                    </el-option-group>
                  </el-select>
                </el-form-item>

                <el-form-item
                  :prop="`rules.${index}.resource_id`"
                  :rules="resourceIdRules(rule)"
                  label="Resource"
                >
                  <template v-if="rule.resource_type === 'url_pattern'">
                    <el-input v-model="rule.resource_id" :disabled="isPlanRuleControlLocked(form.rules, rule)" placeholder="/members/* or /premium/content/*" />
                    <span class="resource-hint">Use * as a wildcard. Example: /members/*</span>
                  </template>
                  <template v-else-if="rule.resource_type === 'special_page'">
                    <el-select v-model="rule.resource_id" :disabled="isPlanRuleControlLocked(form.rules, rule)" placeholder="Select page...">
                      <el-option v-for="sp in specialPageOptions" :key="sp.id" :label="sp.label" :value="sp.id" />
                    </el-select>
                  </template>
                  <template v-else>
                    <el-select
                      v-model="rule.resource_id"
                      filterable
                      remote
                      clearable
                      :disabled="isPlanRuleControlLocked(form.rules, rule)"
                      :remote-method="(q) => searchRuleResources(index, rule.resource_type, q)"
                      :loading="ruleResourceLoading[index]"
                      placeholder="Search by title..."
                      @clear="resetRuleResource(rule)"
                    >
                      <el-option v-if="getTypeConfig(rule.resource_type)?.allow_all" label="All of this type" value="0" />
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
                  :rules="[{ required: true, message: 'Choose when access begins', trigger: 'change' }]"
                  label="Access begins"
                >
                  <el-select v-model="rule.drip_type" :disabled="hasReadOnlyRules" placeholder="Timing" @change="onDripTypeChange(rule)">
                    <el-option label="Immediately" value="immediate" />
                    <el-option label="After a delay" value="delayed" />
                    <el-option label="On a fixed date" value="fixed_date" />
                  </el-select>
                </el-form-item>

                <el-form-item
                  v-if="rule.drip_type === 'delayed'"
                  :prop="`rules.${index}.drip_delay_days`"
                  :rules="[{ required: true, message: 'Enter the delay', trigger: 'blur' }]"
                  label="Delay (days)"
                >
                  <el-input-number v-model="rule.drip_delay_days" :disabled="hasReadOnlyRules" :min="1" :max="730" controls-position="right" />
                </el-form-item>

                <el-form-item
                  v-if="rule.drip_type === 'fixed_date'"
                  :prop="`rules.${index}.drip_date`"
                  :rules="[{ required: true, message: 'Choose an unlock date', trigger: 'change' }]"
                  label="Unlock date"
                >
                  <el-date-picker
                    v-model="rule.drip_date"
                    type="date"
                    placeholder="Select date"
                    :disabled="hasReadOnlyRules"
                    :format="wpDatePickerFormat"
                    value-format="YYYY-MM-DD"
                    :disabled-date="isPastDate"
                  />
                </el-form-item>
              </div>
            </article>
          </section>

          <section v-show="activeStep === 'review'" class="builder-panel review-panel" aria-labelledby="review-step-heading">
            <div class="builder-panel-heading">
              <span class="builder-panel-step">Step 3 of 3</span>
              <h2 id="review-step-heading">Review the member experience</h2>
              <p>One last check before this plan is created.</p>
            </div>

            <div class="review-hero">
              <span>Your membership plan</span>
              <h3>{{ planSummary.title }}</h3>
              <p>{{ form.description.trim() || 'No member-facing description has been added.' }}</p>
            </div>

            <dl class="review-list">
              <div><dt>Availability</dt><dd>{{ planSummary.status }}</dd></div>
              <div><dt>Access duration</dt><dd>{{ planSummary.duration }}</dd></div>
              <div><dt>Content access</dt><dd>{{ planSummary.contentAccess }}</dd></div>
              <div><dt>Trial</dt><dd>{{ planSummary.trial }}</dd></div>
            </dl>

            <el-alert
              v-if="form.rules.length === 0"
              title="This plan does not unlock protected content yet."
              description="That is valid. You can add content access now or edit the plan later."
              type="warning"
              :closable="false"
              show-icon
            />
            <el-alert
              v-if="form.status !== 'active'"
              :title="form.status === 'archived' ? 'This plan will be archived.' : 'This plan will stay inactive.'"
              description="Members will not see it as an available active plan until its status changes."
              type="info"
              :closable="false"
              show-icon
            />
          </section>
        </main>

        <PlanBuilderSummary
          class="plan-builder-summary-desktop"
          id-prefix="desktop-plan-summary"
          :summary="planSummary"
          :mobile-open="mobileSummaryOpen"
          @toggle-mobile="mobileSummaryOpen = !mobileSummaryOpen"
        />
      </div>

      <section v-if="!isNew || dripPreviewRules.length > 0" class="plan-management" aria-labelledby="plan-management-heading">
        <div class="plan-management-heading">
          <h2 id="plan-management-heading">{{ isNew ? 'Preview scheduled access' : 'Manage existing plan' }}</h2>
          <p>
            {{ isNew
              ? 'Check when scheduled content becomes available.'
              : 'Related tools stay available without cluttering the creation flow.' }}
          </p>
        </div>
        <el-tabs v-model="activeTab">
          <el-tab-pane v-if="dripPreviewRules.length > 0" label="Drip Preview" name="drip">
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
              :error="linkedProductsError"
              @link="showLinkProductDialog"
              @retry="loadLinkedProducts"
              @unlink="confirmUnlinkProduct"
            />
          </el-tab-pane>
          <el-tab-pane v-if="!isNew" label="Members" name="members" :lazy="true">
            <PlanMembersTab
              :loading="planMembersLoading"
              :members="planMembers"
              :error="planMembersError"
              :total="planMembersTotal"
              :page="planMembersPage"
              :per-page="planMembersPerPage"
              :format-date="formatWpDate"
              :members-link="`/members?plan_id=${route.params.id}`"
              @page-change="onMembersPageChange"
              @retry="loadPlanMembers(planMembersPage)"
            />
          </el-tab-pane>
        </el-tabs>
      </section>

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
import { ArrowDown, Delete, Lock, Plus, WarningFilled } from '@element-plus/icons-vue'
import { useRouter, useRoute } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { plans, members, content } from '@/api/index.js'
import { formatWpDate, formatWpDateTime, wpDatePickerFormat, wpDateTimePickerFormat } from '@/utils/wpDate.js'
import { buildPlanRulesPayload, hasReadOnlyPlanRules, isPlanRuleControlLocked } from '@/utils/planRulePayload.js'
import PlanSchedulePanel from '@/components/plans/PlanSchedulePanel.vue'
import PlanLinkedProductsTab from '@/components/plans/PlanLinkedProductsTab.vue'
import PlanMembersTab from '@/components/plans/PlanMembersTab.vue'
import PlanLinkProductDialog from '@/components/plans/PlanLinkProductDialog.vue'
import PlanBuilderProgress from '@/components/plans/PlanBuilderProgress.vue'
import PlanBuilderSummary from '@/components/plans/PlanBuilderSummary.vue'
import WorkspacePageHeader from '@/components/workspace/WorkspacePageHeader.vue'
import {
  PLAN_BUILDER_STEPS,
  appendCommunitySpaceRules,
  buildPlanSummary,
  hasAdvancedPlanSettings,
  isOfferStepComplete,
  isAdvancedValidationField,
  isValidPlanSlug,
  previousBuilderStep,
  stepForValidationFields,
} from './planEditorUi.js'

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
const slugPreviewLoading = ref(false)
const slugPreviewError = ref('')
const slugAvailable = ref(true)
const planOptions = ref([])
const activeTab = ref(props.isNew ? 'drip' : 'products')
const activeStep = ref('offer')
const advancedOpen = ref(false)
const mobileSummaryOpen = ref(false)

// Linked Products tab state
const linkedProducts = ref([])
const productsLoading = ref(false)
const productsLoaded = ref(false)
const linkedProductsError = ref('')

// T15: Link Product dialog state
const linkProductVisible = ref(false)
const productSearchQuery = ref('')
const productSearchResults = ref([])
const productSearchLoading = ref(false)
const selectedProduct = ref(null)
const linkingProduct = ref(false)
let productSearchTimer = null
let slugPreviewTimer = null
let slugPreviewSequence = 0

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

// FluentCommunity Space Group quick-add state
const spaceGroups = ref([])
const spaceGroupsLoading = ref(false)
const selectedSpaceGroupId = ref('')

// Members tab state
const planMembers = ref([])
const planMembersLoading = ref(false)
const planMembersLoaded = ref(false)
const planMembersError = ref('')
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
    membership_term: {
      mode: 'none',
      value: null,
      unit: 'months',
      date: null,
    },
  },
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
const hasReadOnlyRules = computed(() => hasReadOnlyPlanRules(form.rules))
const linkedProductIds = computed(() => new Set(
  linkedProducts.value.map((product) => Number(product.product_id)),
))
const hasFcSpaceResourceType = computed(() => Boolean(getTypeConfig('fc_space')))

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
  if (hasReadOnlyRules.value) return
  form.rules.push(createEmptyRule())
}

function removeRule(index) {
  if (hasReadOnlyRules.value) return
  form.rules.splice(index, 1)
}

function onDripTypeChange(rule) {
  if (hasReadOnlyRules.value) return
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

function onTermModeChange(mode) {
  if (mode === 'none' || mode === '1y' || mode === '2y' || mode === '3y') {
    form.meta.membership_term.value = null
    form.meta.membership_term.unit = 'months'
    form.meta.membership_term.date = null
  } else if (mode === 'custom') {
    form.meta.membership_term.date = null
    if (!form.meta.membership_term.value) form.meta.membership_term.value = 1
    if (!form.meta.membership_term.unit) form.meta.membership_term.unit = 'months'
  } else if (mode === 'date') {
    form.meta.membership_term.value = null
    form.meta.membership_term.unit = 'months'
  }
}

const termHintText = computed(() => {
  switch (form.duration_type) {
    case 'lifetime':
      return 'Sets a maximum membership duration. Without a term, lifetime memberships never expire.'
    case 'fixed_days':
      return 'Overrides the fixed days duration with a more flexible term. The shorter of the two wins.'
    case 'subscription_mirror':
      return 'Caps total membership duration regardless of how many times the subscription renews.'
    case 'fixed_anchor':
      return 'Caps total membership duration regardless of how many monthly anchor cycles pass.'
    default:
      return 'Sets an absolute upper bound on how long the membership can remain active.'
  }
})

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

function addSelectedSpaceGroup(groupId) {
  if (!groupId || hasReadOnlyRules.value) return

  const group = spaceGroups.value.find((item) => String(item.id) === String(groupId))
  selectedSpaceGroupId.value = ''
  if (!group) return

  const previousRuleCount = form.rules.length
  const result = appendCommunitySpaceRules(form.rules, group.spaces)
  if (result.added.length === 0) {
    ElMessage.info(`All Spaces from ${group.label} are already in this plan`)
    return
  }

  form.rules.push(...result.rules.slice(previousRuleCount))
  result.added.forEach((spaceId, offset) => {
    const space = group.spaces.find((item) => String(item.id) === spaceId)
    ruleResourceOptions[previousRuleCount + offset] = space ? [space] : []
  })
  ElMessage.success(`Added ${result.added.length} Space${result.added.length === 1 ? '' : 's'} from ${group.label}`)
}

function resourceIdRules(rule) {
  if (hasReadOnlyRules.value || getTypeConfig(rule.resource_type)?.allow_all !== false) {
    return []
  }

  return [{
    trigger: ['blur', 'change'],
    validator: (_rule, value, callback) => {
      const identifier = getTypeConfig(rule.resource_type)?.identifier || 'positive_int'
      const resourceId = String(value ?? '')

      if (identifier === 'slug' && resourceId !== '' && /\D/.test(resourceId)) {
        callback()
        return
      }

      if (identifier === 'positive_int' && /^[1-9]\d*$/.test(resourceId)) {
        callback()
        return
      }

      callback(new Error('Choose a valid provider resource'))
    },
  }]
}

function ruleSummary(rule) {
  const type = getTypeConfig(rule.resource_type)?.displayLabel
    || capitalize(rule.resource_type)
    || 'Resource'
  const scope = !rule.resource_id || String(rule.resource_id) === '0'
    ? 'all of this type'
    : 'selected resource'
  const drip = rule.drip_type === 'delayed'
    ? `after ${rule.drip_delay_days || 1} day${Number(rule.drip_delay_days || 1) === 1 ? '' : 's'}`
    : rule.drip_type === 'fixed_date'
      ? 'on a fixed date'
      : 'immediately'

  return `${type} · ${scope} · ${drip}`
}

function isSearchableType(resourceType) {
  if (resourceType === 'url_pattern' || resourceType === 'special_page') return false
  const config = getTypeConfig(resourceType)
  return config ? config.searchable : true
}

function onResourceTypeChange(index, rule) {
  if (hasReadOnlyRules.value) return
  if (rule.resource_type === 'url_pattern') {
    rule.resource_id = ''
  } else {
    rule.resource_id = getTypeConfig(rule.resource_type)?.allow_all ? '0' : ''
  }
  rule.resource_label = null
  delete ruleResourceOptions[index]
}

function resetRuleResource(rule) {
  rule.resource_id = getTypeConfig(rule.resource_type)?.allow_all ? '0' : ''
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
        allow_all: type.allow_all === true,
        identifier: type.identifier || 'positive_int',
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

async function loadSpaceGroups() {
  spaceGroupsLoading.value = true
  try {
    const res = await content.spaceGroups({ search: '' })
    const data = res.data ?? res
    spaceGroups.value = (Array.isArray(data) ? data : []).map((group) => ({
      id: String(group.id),
      label: group.label || `Group #${group.id}`,
      spaces: Array.isArray(group.spaces)
        ? group.spaces.map((space) => ({
          id: String(space.id),
          label: space.label || `Space #${space.id}`,
        }))
        : [],
    }))
  } catch {
    spaceGroups.value = []
  } finally {
    spaceGroupsLoading.value = false
  }
}

function isPastDate(date) {
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  return date.getTime() < today.getTime()
}

function onTitleInput(value) {
  if (!slugManuallyEdited.value) {
    scheduleSlugPreview(value)
  }
}

function onSlugInput(value) {
  slugManuallyEdited.value = String(value || '').trim() !== ''
  scheduleSlugPreview(form.title, slugManuallyEdited.value ? value : null)
}

function scheduleSlugPreview(title, customSlug = null) {
  clearTimeout(slugPreviewTimer)
  slugPreviewError.value = ''

  if (!String(title || '').trim() && !String(customSlug || '').trim()) {
    form.slug = ''
    slugAvailable.value = false
    return
  }

  slugPreviewLoading.value = true
  slugPreviewTimer = setTimeout(() => {
    slugPreviewTimer = null
    previewSlug(title, customSlug)
  }, 250)
}

async function previewSlug(title, customSlug = null) {
  const sequence = ++slugPreviewSequence
  slugPreviewLoading.value = true

  try {
    const res = await plans.previewSlug({
      title,
      slug: customSlug,
      exclude_id: props.isNew ? null : route.params.id,
    })
    if (sequence !== slugPreviewSequence) return

    const preview = res.data ?? res
    form.slug = preview.slug || ''
    slugAvailable.value = preview.available !== false
    slugPreviewError.value = ''
    formRef.value?.validateField('slug').catch(() => {})
  } catch (error) {
    if (sequence !== slugPreviewSequence) return

    form.slug = ''
    slugAvailable.value = false
    slugPreviewError.value = error.message || 'WordPress could not generate a slug'
  } finally {
    if (sequence === slugPreviewSequence) slugPreviewLoading.value = false
  }
}

function selectDuration(value) {
  if (!durationOptions.some((option) => option.value === value)) return

  form.duration_type = value
  if (value !== 'fixed_days') form.duration_days = null
  if (value !== 'fixed_anchor') form.meta.billing_anchor_day = null
}

function selectBuilderStep(step) {
  if (!PLAN_BUILDER_STEPS.some(({ id }) => id === step)) return
  activeStep.value = step
}

function goToPreviousStep() {
  activeStep.value = previousBuilderStep(activeStep.value)
}

async function validateOfferStep() {
  if (slugPreviewTimer !== null) {
    clearTimeout(slugPreviewTimer)
    slugPreviewTimer = null
    await previewSlug(form.title, slugManuallyEdited.value ? form.slug : null)
  }

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

    // Load membership term
    const savedTerm = planMeta.membership_term || {}
    form.meta.membership_term = {
      mode: savedTerm.mode || 'none',
      value: savedTerm.value ?? null,
      unit: savedTerm.unit || 'months',
      date: savedTerm.date ?? null,
    }
    advancedOpen.value = hasAdvancedPlanSettings(form)

    // T17: load schedule
    schedule.scheduled_status = plan.scheduled_status || null
    schedule.scheduled_at = plan.scheduled_at || null

    form.rules = (plan.rules || []).map((r, index) => {
      const resourceId = String(r.resource_id ?? '0')
      const resourceLabel = r.resource_label || null
      const resourceType = r.resource_type === 'sfwd-courses' ? 'ld_course' : r.resource_type

      // Pre-populate the search options for this rule so the select shows the label
      if (resourceId && resourceId !== '0' && resourceLabel) {
        ruleResourceOptions[index] = [{ id: resourceId, label: resourceLabel }]
      }

      return {
        resource_type: resourceType || 'post',
        resource_id: resourceId,
        resource_label: resourceLabel,
        read_only: r.read_only === true,
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
    // Build meta: only include billing_anchor_day for fixed_anchor
    const meta = form.duration_type === 'fixed_anchor'
      ? { billing_anchor_day: form.meta.billing_anchor_day }
      : {}

    // Always include membership term — mode 'none' must overwrite any
    // previously stored term config (PlanService merges meta via array_merge)
    const termMode = form.meta.membership_term.mode
    const term = { mode: termMode }
    if (termMode === 'custom') {
      term.value = form.meta.membership_term.value
      term.unit = form.meta.membership_term.unit
    } else if (termMode === 'date') {
      term.date = form.meta.membership_term.date
    }
    meta.membership_term = term

    const payload = {
      title: form.title,
      description: form.description,
      status: form.status,
      includes_plan_ids: form.includes_plan_ids,
      duration_type: form.duration_type,
      duration_days: form.duration_type === 'fixed_days' ? form.duration_days : null,
      trial_days: form.trial_days,
      grace_period_days: form.grace_period_days,
      level: form.level,
      meta,
    }

    if (!props.isNew || slugManuallyEdited.value) {
      payload.slug = form.slug
    }

    const rulesPayload = buildPlanRulesPayload(form.rules)
    if (rulesPayload !== undefined) {
      payload.rules = rulesPayload
    }

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

// Linked Products loader
async function loadLinkedProducts() {
  if (productsLoaded.value || props.isNew) return
  productsLoading.value = true
  linkedProductsError.value = ''
  try {
    const res = await plans.linkedProducts(route.params.id)
    linkedProducts.value = res.data ?? res
    productsLoaded.value = true
  } catch (error) {
    linkedProductsError.value = error.message || 'Failed to load linked products.'
  } finally {
    productsLoading.value = false
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
    const productsData = res.data ?? res
    productSearchResults.value = (Array.isArray(productsData) ? productsData : []).map((product) => ({
      ...product,
      already_linked: linkedProductIds.value.has(Number(product.id)),
    }))
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
  planMembersError.value = ''
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
    planMembersLoaded.value = true
  } catch (error) {
    planMembersError.value = error.message || 'Failed to load plan members.'
  } finally {
    planMembersLoading.value = false
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
}

.plan-details-layout {
  max-width: 840px;
  padding: 8px 4px 4px;
}

.plan-section + .plan-section,
.plan-advanced-section {
  margin-top: 28px;
}

.plan-section-heading {
  margin-bottom: 18px;
}

.plan-section-heading h3 {
  margin: 0;
  color: var(--fchub-text-primary);
  font-size: 16px;
  line-height: 1.4;
}

.plan-section-heading p {
  margin: 4px 0 0;
  color: var(--fchub-text-secondary);
  font-size: 13px;
  line-height: 1.5;
}

.plan-advanced-section {
  border-top: 1px solid var(--fchub-border-color);
  padding-top: 12px;
}

.advanced-settings-toggle {
  padding-left: 0;
  font-weight: 600;
}

.advanced-settings-toggle .el-icon {
  margin-left: 6px;
  transition: transform 0.15s ease;
}

.advanced-settings-toggle .el-icon.is-open {
  transform: rotate(180deg);
}

.advanced-settings-content {
  padding-top: 16px;
}

.access-rules-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
}

.access-rules-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 8px;
  min-width: 0;
}

.space-group-selector {
  min-width: 220px;
}

.access-rules-heading {
  gap: 20px;
  margin: 8px 0 20px;
}

.access-rules-heading h3,
.rule-row-header h4 {
  margin: 0;
  color: var(--fchub-text-primary);
}

.access-rules-heading h3 {
  font-size: 16px;
}

.access-rules-heading p,
.rule-row-header p,
.empty-state-help {
  color: var(--fchub-text-secondary);
  font-size: 13px;
  line-height: 1.5;
}

.access-rules-heading p,
.rule-row-header p {
  margin: 4px 0 0;
}

.empty-state-help {
  max-width: 460px;
  margin: -4px auto 16px;
  text-align: center;
}

.access-rules-heading .el-icon,
.empty-state-action .el-icon {
  margin-right: 6px;
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
  padding: 16px;
  margin-bottom: 12px;
  background: var(--el-fill-color-lighter, #fafafa);
  border: 1px solid var(--fchub-border-color);
  border-radius: 8px;
}

.rule-row-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding-bottom: 14px;
  margin-bottom: 16px;
  border-bottom: 1px solid var(--fchub-border-color);
}

.rule-row-header h4 {
  font-size: 14px;
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
  margin: 0;
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
  position: sticky;
  z-index: 10;
  bottom: 0;
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  padding: 14px 24px;
  border-top: 1px solid var(--fchub-border-color);
  background: var(--fchub-card-bg);
  box-shadow: 0 -8px 20px rgb(0 0 0 / 4%);
  border-radius: 0 0 var(--fchub-radius-card) var(--fchub-radius-card);
}

@media (max-width: 782px) {
  .plan-details-layout {
    max-width: none;
  }
}

/* Guided builder */
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

.plan-form {
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

.plan-builder-content {
  min-width: 0;
}

.builder-panel {
  overflow: hidden;
  border: 1px solid var(--fchub-border-color);
  border-radius: 12px;
  background: var(--fchub-card-bg);
  box-shadow: 0 10px 30px rgb(38 55 95 / 5%);
}

.builder-panel-heading {
  padding: 26px 28px 22px;
  border-bottom: 1px solid var(--fchub-border-color);
}

.builder-panel-step {
  display: block;
  margin-bottom: 7px;
  color: var(--el-color-primary);
  font-size: 10px;
  font-weight: 800;
  letter-spacing: 0.09em;
  text-transform: uppercase;
}

.builder-panel-heading h2 {
  margin: 0;
  color: var(--fchub-text-primary);
  font-size: 21px;
  line-height: 1.3;
  letter-spacing: -0.015em;
}

.builder-panel-heading p {
  margin: 6px 0 0;
  color: var(--fchub-text-secondary);
  font-size: 13px;
  line-height: 1.55;
}

.builder-form-section {
  padding: 24px 28px 6px;
}

.builder-form-section + .builder-form-section {
  border-top: 1px solid var(--fchub-border-color);
}

.offer-field-grid,
.advanced-field-grid,
.term-custom-row {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
}

.advanced-field-grid {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.section-heading-inline {
  margin-bottom: 16px;
}

.section-heading-inline h3 {
  margin: 0;
  color: var(--fchub-text-primary);
  font-size: 15px;
  line-height: 1.4;
}

.section-heading-inline p {
  margin: 4px 0 0;
  color: var(--fchub-text-secondary);
  font-size: 12px;
}

.duration-form-item :deep(.el-form-item__content) {
  display: block;
}

.duration-options {
  width: 100%;
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.duration-option {
  min-width: 0;
  display: grid;
  grid-template-columns: 18px minmax(0, 1fr);
  gap: 10px;
  align-items: start;
  min-height: 92px;
  padding: 14px;
  border: 1px solid var(--fchub-border-color);
  border-radius: 9px;
  color: var(--fchub-text-primary);
  background: var(--fchub-card-bg);
  text-align: left;
  cursor: pointer;
  transition: border-color 160ms ease, box-shadow 160ms ease, background-color 160ms ease;
}

.duration-option:hover {
  border-color: color-mix(in srgb, var(--el-color-primary) 45%, var(--fchub-border-color));
}

.duration-option:focus-visible {
  outline: 3px solid color-mix(in srgb, var(--el-color-primary) 25%, transparent);
  outline-offset: 2px;
}

.duration-option.is-selected {
  border-color: var(--el-color-primary);
  background: color-mix(in srgb, var(--el-color-primary) 4%, var(--fchub-card-bg));
  box-shadow: inset 0 0 0 1px var(--el-color-primary);
}

.duration-radio {
  width: 16px;
  height: 16px;
  box-sizing: border-box;
  margin-top: 2px;
  border: 1.5px solid var(--fchub-border-color);
  border-radius: 50%;
  background: var(--fchub-card-bg);
}

.duration-option.is-selected .duration-radio {
  border: 5px solid var(--el-color-primary);
}

.duration-option span:last-child {
  min-width: 0;
  display: grid;
  gap: 4px;
}

.duration-option strong {
  font-size: 13px;
  line-height: 1.35;
}

.duration-option small {
  color: var(--fchub-text-secondary);
  font-size: 11px;
  line-height: 1.45;
}

.conditional-duration-field {
  max-width: 280px;
  margin-top: 4px;
}

.plan-advanced-section {
  margin: 18px 28px 26px;
  padding: 0;
  overflow: hidden;
  border: 1px solid var(--fchub-border-color);
  border-radius: 9px;
}

.advanced-settings-toggle {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 15px 16px;
  border: 0;
  color: var(--fchub-text-primary);
  background: var(--el-fill-color-extra-light, #fafbfc);
  text-align: left;
  cursor: pointer;
}

.advanced-settings-toggle > span {
  min-width: 0;
  display: grid;
  gap: 2px;
}

.advanced-settings-toggle strong {
  font-size: 13px;
}

.advanced-settings-toggle small {
  color: var(--fchub-text-secondary);
  font-size: 11px;
  line-height: 1.4;
}

.advanced-settings-toggle:focus-visible {
  outline: 3px solid color-mix(in srgb, var(--el-color-primary) 25%, transparent);
  outline-offset: -3px;
}

.advanced-settings-toggle .el-icon {
  flex: 0 0 auto;
  transition: transform 160ms ease;
}

.advanced-settings-toggle .el-icon.is-open {
  transform: rotate(180deg);
}

.advanced-settings-content {
  padding: 22px 18px 4px;
  border-top: 1px solid var(--fchub-border-color);
}

.access-rules-heading {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 20px;
  margin: 0;
}

.access-empty-state {
  max-width: 480px;
  margin: 60px auto 72px;
  padding: 0 24px;
  text-align: center;
}

.access-empty-icon {
  width: 46px;
  height: 46px;
  display: grid;
  place-items: center;
  margin: 0 auto 14px;
  border-radius: 12px;
  color: var(--el-color-primary);
  background: color-mix(in srgb, var(--el-color-primary) 9%, var(--fchub-card-bg));
  font-size: 20px;
}

.access-empty-state h3 {
  margin: 0;
  color: var(--fchub-text-primary);
  font-size: 17px;
}

.access-empty-state p {
  margin: 7px 0 18px;
  color: var(--fchub-text-secondary);
  font-size: 13px;
  line-height: 1.55;
}

.rule-row {
  margin: 18px 24px;
  padding: 0;
  overflow: hidden;
  border: 1px solid var(--fchub-border-color);
  border-radius: 10px;
  background: var(--fchub-card-bg);
}

.rule-row + .rule-row {
  margin-top: 12px;
}

.rule-row-header {
  align-items: center;
  margin: 0;
  padding: 14px 16px;
  background: var(--el-fill-color-extra-light, #fafbfc);
}

.rule-row-header span:first-child {
  display: block;
  margin-bottom: 3px;
  color: var(--el-color-primary);
  font-size: 9px;
  font-weight: 800;
  letter-spacing: 0.08em;
}

.rule-row-header h3 {
  margin: 0;
  color: var(--fchub-text-primary);
  font-size: 13px;
  line-height: 1.4;
}

.rule-fields {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 2px 16px;
  padding: 18px 16px;
}

.rule-fields .el-form-item,
.rule-fields :deep(.el-select),
.rule-fields :deep(.el-input),
.rule-fields :deep(.el-date-editor) {
  width: 100%;
}

.review-panel {
  padding-bottom: 24px;
}

.review-hero {
  margin: 24px 28px 16px;
  padding: 22px;
  border-radius: 10px;
  color: var(--fchub-text-primary);
  background: linear-gradient(135deg, color-mix(in srgb, var(--el-color-primary) 8%, var(--fchub-card-bg)), var(--fchub-card-bg));
}

.review-hero span {
  color: var(--el-color-primary);
  font-size: 10px;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.review-hero h3 {
  overflow-wrap: anywhere;
  margin: 7px 0 4px;
  font-size: 21px;
  line-height: 1.35;
}

.review-hero p {
  margin: 0;
  color: var(--fchub-text-secondary);
  font-size: 13px;
  line-height: 1.55;
}

.review-list {
  margin: 0 28px 18px;
  border: 1px solid var(--fchub-border-color);
  border-radius: 10px;
}

.review-list div {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  padding: 13px 15px;
  border-bottom: 1px solid var(--fchub-border-color);
}

.review-list div:last-child {
  border-bottom: 0;
}

.review-list dt {
  color: var(--fchub-text-secondary);
  font-size: 12px;
}

.review-list dd {
  margin: 0;
  color: var(--fchub-text-primary);
  font-size: 13px;
  font-weight: 600;
  text-align: right;
}

.review-panel :deep(.el-alert) {
  width: auto;
  margin: 12px 28px 0;
}

.plan-management {
  margin: 24px 0 0 210px;
  padding: 22px;
  border: 1px solid var(--fchub-border-color);
  border-radius: 12px;
  background: var(--fchub-card-bg);
}

.plan-management-heading h2 {
  margin: 0;
  color: var(--fchub-text-primary);
  font-size: 16px;
}

.plan-management-heading p {
  margin: 4px 0 14px;
  color: var(--fchub-text-secondary);
  font-size: 12px;
}

.form-actions {
  margin: 20px 0 0 210px;
  padding: 13px 16px;
  justify-content: space-between;
  border: 1px solid var(--fchub-border-color);
  border-radius: 10px;
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

  .advanced-field-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .plan-management,
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

  .plan-management,
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

  .builder-panel-heading,
  .builder-form-section {
    padding-left: 18px;
    padding-right: 18px;
  }

  .builder-panel-heading h2 {
    font-size: 19px;
  }

  .duration-options,
  .offer-field-grid,
  .advanced-field-grid,
  .term-custom-row,
  .rule-fields {
    grid-template-columns: minmax(0, 1fr);
  }

  .duration-option {
    min-height: 78px;
  }

  .plan-advanced-section {
    margin-left: 18px;
    margin-right: 18px;
  }

  .advanced-settings-toggle small {
    display: none;
  }

  .access-rules-heading {
    display: block;
  }

  .access-rules-actions {
    display: block;
  }

  .space-group-selector,
  .access-rules-actions .el-button {
    width: 100%;
    margin-top: 14px;
  }

  .rule-row {
    margin-left: 14px;
    margin-right: 14px;
  }

  .rule-row-header {
    align-items: flex-start;
  }

  .remove-rule-btn span:last-child {
    display: none;
  }

  .review-hero,
  .review-list,
  .review-panel :deep(.el-alert) {
    margin-left: 16px;
    margin-right: 16px;
  }

  .review-list div {
    align-items: flex-start;
  }

  .plan-management,
  .form-actions {
    margin-left: 0;
  }

  .plan-management {
    padding: 16px;
  }

  .form-actions {
    gap: 8px;
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
