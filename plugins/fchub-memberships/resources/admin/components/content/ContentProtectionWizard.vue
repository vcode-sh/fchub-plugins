<template>
  <el-dialog
    :model-value="visible"
    title="Content Protection"
    width="880px"
    modal-class="content-protection-wizard-overlay"
    class="content-protection-wizard"
    :close-on-click-modal="false"
    :close-on-press-escape="false"
    :show-close="!saving"
    destroy-on-close
    @close="emit('close')"
  >
    <div class="cpw-shell">
      <nav class="cpw-progress" aria-label="Protection setup progress">
        <ol class="cpw-progress-list">
          <li
            v-for="progressStep in steps"
            :key="progressStep.id"
            ref="progressItems"
          >
            <button
              type="button"
              class="cpw-progress-card"
              :class="{
                'is-active': progressStep.id === step,
                'is-complete': progressStep.id < step,
              }"
              :aria-current="progressStep.id === step ? 'step' : undefined"
              :disabled="progressStep.id >= step"
              @click="selectStep(progressStep.id)"
            >
              <span class="cpw-progress-marker" aria-hidden="true">
                <el-icon v-if="progressStep.id < step"><Check /></el-icon>
                <template v-else>{{ progressStep.id + 1 }}</template>
              </span>
              <span class="cpw-progress-copy">
                <small>{{ progressStep.eyebrow }}</small>
                <strong>{{ progressStep.label }}</strong>
                <span>{{ progressStep.description }}</span>
              </span>
            </button>
          </li>
        </ol>
      </nav>

      <main class="cpw-stage" aria-live="polite">
        <header class="cpw-task-header">
          <span class="cpw-task-eyebrow">{{ currentStepCopy.eyebrow }}</span>
          <h3>{{ currentStepCopy.title }}</h3>
          <p>{{ currentStepCopy.description }}</p>
        </header>

        <section v-if="step === 0" class="cpw-step cpw-category-step" aria-labelledby="cpw-category-heading">
          <h4 id="cpw-category-heading" class="sr-only">Content types</h4>
          <div class="cpw-category-list">
            <div
              v-for="card in categoryCards"
              :key="card.key"
              class="cpw-category-item"
              :class="{ 'is-selected': form.categoryKey === card.key }"
            >
              <button
                type="button"
                class="cpw-category-choice"
                :aria-pressed="form.categoryKey === card.key"
                :aria-expanded="form.categoryKey === card.key && categoryTypes.length > 1"
                :aria-controls="form.categoryKey === card.key && categoryTypes.length > 1 ? `cpw-types-${card.key}` : undefined"
                @click="emit('select-category', card)"
              >
                <span class="cpw-selection-dot" aria-hidden="true">
                  <el-icon v-if="form.categoryKey === card.key"><Check /></el-icon>
                </span>
                <span class="cpw-category-icon" aria-hidden="true">
                  <el-icon><component :is="card.icon" /></el-icon>
                </span>
                <span class="cpw-category-copy">
                  <strong>{{ card.label }}</strong>
                  <small>{{ categoryDescription(card.key) }}</small>
                </span>
                <el-icon class="cpw-category-arrow" aria-hidden="true"><ArrowRight /></el-icon>
              </button>

              <div
                v-if="form.categoryKey === card.key && categoryTypes.length > 1"
                :id="`cpw-types-${card.key}`"
                class="cpw-inline-types"
              >
                <span class="cpw-inline-label">Choose a type</span>
                <el-radio-group v-model="form.resource_type" @change="emit('type-change')">
                  <el-radio v-for="type in categoryTypes" :key="type.value" :value="type.value">
                    {{ type.label }}
                  </el-radio>
                </el-radio-group>
              </div>
            </div>
          </div>

          <div v-if="form.categoryKey" class="cpw-current-selection">
            <span class="cpw-summary-icon" aria-hidden="true">
              <el-icon><DocumentChecked /></el-icon>
            </span>
            <span>
              <small>Current selection</small>
              <strong>{{ categorySelectionLabel }}</strong>
            </span>
            <span class="cpw-summary-next">Next: choose a specific resource</span>
          </div>
        </section>

        <section v-else-if="step === 1" class="cpw-step cpw-resource-step">
          <div class="cpw-context-strip">
            <span>
              <small>Protecting</small>
              <strong>{{ categorySelectionLabel }}</strong>
            </span>
            <button type="button" @click="selectStep(0)">Change content type</button>
          </div>

          <el-alert
            v-if="resourceError"
            :title="resourceError"
            type="error"
            :closable="false"
            show-icon
            class="cpw-alert"
          />

          <el-form label-position="top" class="cpw-form">
            <el-form-item v-if="!form.resource_type" label="Resource type" required>
              <el-select
                v-model="form.resource_type"
                placeholder="Choose a resource type"
                class="cpw-control"
                @change="emit('type-change')"
              >
                <el-option v-for="type in categoryTypes" :key="type.value" :label="type.label" :value="type.value" />
              </el-select>
            </el-form-item>

            <template v-if="form.resource_type">
              <el-form-item v-if="form.resource_type === 'url_pattern'" label="URL pattern" required>
                <el-input v-model="form.resource_id" placeholder="e.g. /members-only/*" />
                <p class="cpw-field-help">Use <code>*</code> as a wildcard. The pattern <code>/premium/*</code> protects every matching URL.</p>
              </el-form-item>

              <el-form-item v-else-if="form.resource_type === 'special_page'" label="Special page" required>
                <el-select
                  v-model="form.resource_id"
                  placeholder="Choose a special page"
                  class="cpw-control"
                  :loading="resourceLoading"
                  no-data-text="No special pages are available"
                >
                  <el-option v-for="item in resourceOptions" :key="item.id" :label="item.label || item.title" :value="String(item.id)" />
                </el-select>
              </el-form-item>

              <el-form-item v-else-if="form.resource_type === 'menu_item'" label="Menu item" required>
                <el-select
                  v-model="form.resource_id"
                  placeholder="Choose a menu item"
                  class="cpw-control"
                  :loading="resourceLoading"
                  filterable
                  no-data-text="No menu items are available"
                >
                  <el-option v-for="item in resourceOptions" :key="item.id" :label="item.label || item.title" :value="String(item.id)" />
                </el-select>
              </el-form-item>

              <el-form-item v-else-if="form.resource_type === 'comment'" label="Comment protection" required>
                <el-radio-group v-model="form.commentMode" class="cpw-choice-group" @change="emit('comment-mode-change')">
                  <el-radio value="all" border>All protected content comments</el-radio>
                  <el-radio value="specific" border>Comments on one post</el-radio>
                </el-radio-group>
                <el-select
                  v-if="form.commentMode === 'specific'"
                  v-model="form.resource_id"
                  filterable
                  remote
                  :remote-method="searchResources"
                  :loading="resourceLoading"
                  placeholder="Choose or search for a post"
                  class="cpw-control cpw-nested-control"
                  popper-class="cpw-resource-popper"
                  no-data-text="No matching posts"
                  @visible-change="onResourcePickerVisibility"
                >
                  <el-option v-for="item in resourceOptions" :key="item.id" :label="item.label || item.title" :value="String(item.id)" />
                </el-select>
              </el-form-item>

              <el-form-item v-else label="Resource" required>
                <el-select
                  v-model="form.resource_id"
                  filterable
                  remote
                  :remote-method="searchResources"
                  :loading="resourceLoading"
                  :placeholder="resourcePlaceholder"
                  class="cpw-control"
                  popper-class="cpw-resource-popper"
                  no-data-text="No matching content"
                  @visible-change="onResourcePickerVisibility"
                >
                  <el-option v-for="item in resourceOptions" :key="item.id" :label="item.label || item.title" :value="String(item.id)" />
                </el-select>
                <p class="cpw-field-help">Browse recent resources or type at least 2 characters to search. Only the selected resource will be protected.</p>
              </el-form-item>
            </template>
          </el-form>
        </section>

        <section v-else-if="step === 2" class="cpw-step cpw-access-step">
          <el-form label-position="top" class="cpw-form">
            <div class="cpw-form-section">
              <div class="cpw-section-heading">
                <span class="cpw-section-icon" aria-hidden="true"><el-icon><UserFilled /></el-icon></span>
                <span>
                  <h4>Membership access</h4>
                  <p>Select every plan that should unlock this content.</p>
                </span>
              </div>

              <el-alert
                v-if="!planOptionsLoading && planOptions.length === 0"
                title="No membership plans are available. Create a plan before protecting this content."
                type="warning"
                :closable="false"
                show-icon
                class="cpw-alert"
              />
              <el-form-item v-else label="Membership plans" required>
                <el-select
                  v-model="form.plan_ids"
                  multiple
                  placeholder="Choose plans that grant access"
                  class="cpw-control"
                  :loading="planOptionsLoading"
                >
                  <el-option v-for="plan in planOptions" :key="plan.id" :label="plan.title" :value="plan.id" />
                </el-select>
              </el-form-item>
            </div>

            <div class="cpw-form-section">
              <div class="cpw-section-heading">
                <span class="cpw-section-icon" aria-hidden="true"><el-icon><View /></el-icon></span>
                <span>
                  <h4>Blocked visitor experience</h4>
                  <p>Keep the defaults, or tailor what visitors see before they join.</p>
                </span>
              </div>

              <div class="cpw-switch-row">
                <span>
                  <strong>Show a teaser</strong>
                  <small>Keep the excerpt visible above the restriction message.</small>
                </span>
                <el-switch v-model="form.show_teaser" active-value="yes" inactive-value="no" aria-label="Show a teaser" />
              </div>

              <el-form-item label="Restriction message">
                <el-input
                  v-model="form.restriction_message"
                  type="textarea"
                  :rows="3"
                  maxlength="500"
                  show-word-limit
                  placeholder="Use the site default, or write a message for non-members"
                />
              </el-form-item>

              <el-form-item label="Redirect URL">
                <el-input v-model="form.redirect_url" placeholder="https://example.com/join (optional)" />
                <p class="cpw-field-help">When set, blocked visitors are redirected instead of seeing the restriction message.</p>
              </el-form-item>
            </div>
          </el-form>
        </section>

        <section v-else class="cpw-step cpw-review-step">
          <div class="cpw-review-group">
            <div class="cpw-review-heading">
              <span>
                <small>CONTENT</small>
                <strong>Protected resource</strong>
              </span>
              <button type="button" @click="selectStep(0)">Edit</button>
            </div>
            <dl>
              <div>
                <dt>Type</dt>
                <dd>{{ form.resource_type_label || form.resource_type }}</dd>
              </div>
              <div>
                <dt>Resource</dt>
                <dd>{{ resourceDisplayName }}</dd>
              </div>
            </dl>
          </div>

          <div class="cpw-review-group">
            <div class="cpw-review-heading">
              <span>
                <small>ACCESS</small>
                <strong>Membership plans</strong>
              </span>
              <button type="button" @click="selectStep(2)">Edit</button>
            </div>
            <div class="cpw-plan-tags">
              <el-tag v-for="id in form.plan_ids" :key="id" type="info">
                {{ planOptionsMap[id] || `Plan #${id}` }}
              </el-tag>
            </div>
          </div>

          <div class="cpw-review-group">
            <div class="cpw-review-heading">
              <span>
                <small>VISITOR EXPERIENCE</small>
                <strong>When access is blocked</strong>
              </span>
              <button type="button" @click="selectStep(2)">Edit</button>
            </div>
            <dl>
              <div>
                <dt>Teaser</dt>
                <dd>{{ form.show_teaser === 'yes' ? 'Shown' : 'Hidden' }}</dd>
              </div>
              <div v-if="form.restriction_message">
                <dt>Message</dt>
                <dd class="cpw-review-long">{{ form.restriction_message }}</dd>
              </div>
              <div v-if="form.redirect_url">
                <dt>Redirect</dt>
                <dd class="cpw-review-long">{{ form.redirect_url }}</dd>
              </div>
              <div v-if="!form.restriction_message && !form.redirect_url">
                <dt>Fallback</dt>
                <dd>Site default restriction message</dd>
              </div>
            </dl>
          </div>
        </section>
      </main>
    </div>

    <template #footer>
      <div class="cpw-footer">
        <el-button :disabled="saving" @click="emit('close')">Cancel</el-button>
        <div class="cpw-footer-actions">
          <el-button v-if="step > 0" :disabled="saving" @click="emit('back')">Back</el-button>
          <el-button
            v-if="step < 3"
            type="primary"
            :disabled="!canAdvance || saving"
            @click="emit('next')"
          >
            {{ step === 2 ? 'Review rule' : 'Continue' }}
          </el-button>
          <el-button v-else type="primary" :loading="saving" :disabled="saving" @click="emit('submit')">
            Protect content
          </el-button>
        </div>
      </div>
    </template>
  </el-dialog>
</template>

<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import {
  ArrowRight,
  Check,
  DocumentChecked,
  UserFilled,
  View,
} from '@element-plus/icons-vue'
import {
  CONTENT_PROTECTION_STEPS,
  categoryDescription,
  stepCopy,
} from './contentProtectionWizardUi.js'

const props = defineProps({
  visible: Boolean,
  step: Number,
  form: { type: Object, required: true },
  categoryCards: { type: Array, default: () => [] },
  categoryTypes: { type: Array, default: () => [] },
  resourceLoading: Boolean,
  resourceError: { type: String, default: '' },
  resourceOptions: { type: Array, default: () => [] },
  planOptionsLoading: Boolean,
  planOptions: { type: Array, default: () => [] },
  planOptionsMap: { type: Object, required: true },
  resourceDisplayName: { type: String, required: true },
  canAdvance: Boolean,
  saving: Boolean,
  searchResources: { type: Function, required: true },
})

const emit = defineEmits([
  'close',
  'back',
  'next',
  'submit',
  'select-step',
  'select-category',
  'type-change',
  'comment-mode-change',
])

const steps = CONTENT_PROTECTION_STEPS
const progressItems = ref([])
const currentStepCopy = computed(() => stepCopy(props.step, props.form))
const categorySelectionLabel = computed(() => {
  const type = props.categoryTypes.find(({ value }) => value === props.form.resource_type)
  return type ? `${props.form.categoryLabel} · ${type.label}` : props.form.categoryLabel
})
const resourcePlaceholder = computed(() => {
  const label = String(props.form.resource_type_label || 'content').toLowerCase()
  return `Choose or search ${label}`
})

function onResourcePickerVisibility(visible) {
  if (visible) {
    props.searchResources('')
  }
}

function selectStep(targetStep) {
  if (targetStep < props.step) {
    emit('select-step', targetStep)
  }
}

watch(
  () => props.step,
  async (activeStep) => {
    await nextTick()
    progressItems.value[activeStep]?.scrollIntoView({
      behavior: 'smooth',
      block: 'nearest',
      inline: 'center',
    })
  },
  { flush: 'post' },
)
</script>

<style>
.content-protection-wizard-overlay .el-overlay-dialog {
  top: 16px;
  bottom: 16px;
  display: flex;
  align-items: stretch;
  justify-content: center;
  padding: 0 16px;
  box-sizing: border-box;
  overflow: hidden;
}

@media (min-width: 783px) {
  body.wp-admin .content-protection-wizard-overlay .el-overlay-dialog {
    top: 48px;
    left: 160px;
  }

  body.wp-admin.folded .content-protection-wizard-overlay .el-overlay-dialog {
    left: 36px;
  }
}

@media (min-width: 783px) and (max-width: 960px) {
  body.wp-admin.auto-fold .content-protection-wizard-overlay .el-overlay-dialog {
    left: 36px;
  }
}

.content-protection-wizard {
  width: min(880px, 100%) !important;
  height: auto;
  max-height: none;
  margin: 0 !important;
  display: flex;
  flex-direction: column;
  box-sizing: border-box;
  overflow: hidden;
  border-radius: 12px;
}

.content-protection-wizard .el-dialog__header {
  flex: 0 0 auto;
  margin: 0;
  padding: 20px 24px;
  border-bottom: 1px solid var(--fchub-border-color);
}

.content-protection-wizard .el-dialog__title {
  color: var(--fchub-text-primary);
  font-size: 18px;
  font-weight: 650;
}

.content-protection-wizard .el-dialog__headerbtn {
  top: 12px;
  right: 14px;
  width: 44px;
  height: 44px;
}

.content-protection-wizard .el-dialog__body {
  flex: 1 1 auto;
  min-height: 0;
  display: flex;
  padding: 0;
  overflow: hidden;
}

.content-protection-wizard .el-dialog__footer {
  flex: 0 0 auto;
  padding: 0;
  border-top: 1px solid var(--fchub-border-color);
}

.cpw-shell {
  flex: 1 1 auto;
  min-height: 0;
  height: auto;
  display: flex;
  flex-direction: column;
}

.cpw-progress {
  width: 100%;
  flex: 0 0 auto;
  padding: 12px 16px;
  box-sizing: border-box;
  border-bottom: 1px solid var(--fchub-border-color);
  background: color-mix(in srgb, var(--fchub-card-bg) 92%, var(--el-color-primary) 8%);
}

.cpw-progress-list {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 8px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.cpw-progress-list li {
  min-width: 0;
  margin: 0;
}

.cpw-progress-card {
  width: 100%;
  height: 66px;
  min-height: 66px;
  display: grid;
  grid-template-columns: 26px minmax(0, 1fr);
  align-items: center;
  gap: 8px;
  padding: 8px 10px;
  box-sizing: border-box;
  border: 1px solid var(--fchub-border-color);
  border-radius: 8px;
  color: var(--fchub-text-secondary);
  background: var(--fchub-card-bg);
  text-align: left;
  cursor: pointer;
  transition: border-color 160ms ease, box-shadow 160ms ease, color 160ms ease;
}

.cpw-progress-card:disabled {
  cursor: default;
  opacity: 1;
}

.cpw-progress-card:not(:disabled):hover {
  border-color: color-mix(in srgb, var(--el-color-primary) 45%, var(--fchub-border-color));
}

.cpw-progress-card:focus-visible,
.cpw-category-choice:focus-visible,
.cpw-context-strip button:focus-visible,
.cpw-review-heading button:focus-visible {
  outline: 3px solid color-mix(in srgb, var(--el-color-primary) 24%, transparent);
  outline-offset: 2px;
}

.cpw-progress-card.is-active {
  color: var(--fchub-text-primary);
  border-color: var(--el-color-primary);
  box-shadow: inset 0 0 0 1px var(--el-color-primary), 0 6px 16px rgb(38 55 95 / 7%);
}

.cpw-progress-card.is-complete {
  color: var(--fchub-text-primary);
}

.cpw-progress-marker {
  width: 24px;
  height: 24px;
  display: grid;
  place-items: center;
  border: 1px solid var(--fchub-border-color);
  border-radius: 50%;
  background: var(--fchub-card-bg);
  font-size: 11px;
  font-weight: 750;
}

.cpw-progress-card.is-active .cpw-progress-marker {
  color: #fff;
  border-color: var(--el-color-primary);
  background: var(--el-color-primary);
}

.cpw-progress-card.is-complete .cpw-progress-marker {
  color: var(--el-color-success);
  border-color: color-mix(in srgb, var(--el-color-success) 34%, var(--fchub-border-color));
  background: color-mix(in srgb, var(--el-color-success) 9%, var(--fchub-card-bg));
}

.cpw-progress-copy {
  min-width: 0;
  display: grid;
  align-content: center;
  gap: 0;
}

.cpw-progress-copy small,
.cpw-task-eyebrow,
.cpw-review-heading small {
  color: var(--el-color-primary);
  font-size: 10px;
  font-weight: 800;
  letter-spacing: 0.08em;
}

.cpw-progress-copy small {
  font-size: 9px;
  line-height: 1.15;
}

.cpw-progress-copy strong {
  color: inherit;
  font-size: 12px;
  line-height: 1.2;
}

.cpw-progress-copy span {
  display: -webkit-box;
  overflow: hidden;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  color: var(--fchub-text-secondary);
  font-size: 10px;
  line-height: 1.2;
}

.cpw-stage {
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
  padding: 26px 24px 30px;
  scrollbar-gutter: stable;
}

.cpw-task-header {
  margin-bottom: 20px;
}

.cpw-task-header h3 {
  margin: 5px 0 5px;
  color: var(--fchub-text-primary);
  font-size: 22px;
  font-weight: 700;
  line-height: 1.25;
}

.cpw-task-header p {
  max-width: 680px;
  margin: 0;
  color: var(--fchub-text-secondary);
  font-size: 14px;
  line-height: 1.55;
}

.cpw-category-list {
  overflow: hidden;
  border: 1px solid var(--fchub-border-color);
  border-radius: 10px;
  background: var(--fchub-card-bg);
}

.cpw-category-item + .cpw-category-item {
  border-top: 1px solid var(--fchub-border-color);
}

.cpw-category-item.is-selected {
  background: color-mix(in srgb, var(--el-color-primary) 5%, var(--fchub-card-bg));
}

.cpw-category-choice {
  width: 100%;
  min-height: 64px;
  display: grid;
  grid-template-columns: 20px 36px minmax(0, 1fr) 20px;
  align-items: center;
  gap: 12px;
  padding: 11px 16px;
  border: 0;
  color: var(--fchub-text-primary);
  background: transparent;
  text-align: left;
  cursor: pointer;
}

.cpw-category-choice:hover {
  background: color-mix(in srgb, var(--el-color-primary) 4%, transparent);
}

.cpw-selection-dot {
  width: 18px;
  height: 18px;
  display: grid;
  place-items: center;
  border: 1.5px solid var(--fchub-border-color);
  border-radius: 50%;
  background: var(--fchub-card-bg);
}

.cpw-category-item.is-selected .cpw-selection-dot {
  color: #fff;
  border-color: var(--el-color-primary);
  background: var(--el-color-primary);
  font-size: 12px;
}

.cpw-category-icon,
.cpw-summary-icon,
.cpw-section-icon {
  display: grid;
  place-items: center;
  color: var(--fchub-text-secondary);
}

.cpw-category-icon {
  width: 36px;
  height: 36px;
  font-size: 24px;
}

.cpw-category-item.is-selected .cpw-category-icon {
  color: var(--el-color-primary);
}

.cpw-category-copy {
  min-width: 0;
  display: grid;
  gap: 2px;
}

.cpw-category-copy strong {
  font-size: 14px;
  line-height: 1.35;
}

.cpw-category-copy small {
  color: var(--fchub-text-secondary);
  font-size: 12px;
  line-height: 1.4;
}

.cpw-category-arrow {
  color: var(--fchub-text-secondary);
}

.cpw-inline-types {
  display: flex;
  align-items: center;
  gap: 16px;
  margin: -2px 16px 12px 84px;
  padding: 10px 14px;
  border: 1px solid color-mix(in srgb, var(--el-color-primary) 18%, var(--fchub-border-color));
  border-radius: 8px;
  background: color-mix(in srgb, var(--el-color-primary) 4%, var(--fchub-card-bg));
}

.cpw-inline-label {
  color: var(--fchub-text-secondary);
  font-size: 12px;
  font-weight: 600;
}

.cpw-current-selection,
.cpw-context-strip {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-top: 18px;
  padding: 13px 15px;
  border: 1px solid var(--fchub-border-color);
  border-radius: 9px;
  background: color-mix(in srgb, var(--fchub-card-bg) 94%, var(--el-color-primary) 6%);
}

.cpw-current-selection > span:nth-child(2),
.cpw-context-strip > span {
  min-width: 0;
  display: grid;
  gap: 2px;
}

.cpw-current-selection small,
.cpw-context-strip small {
  color: var(--fchub-text-secondary);
  font-size: 11px;
}

.cpw-current-selection strong,
.cpw-context-strip strong {
  color: var(--fchub-text-primary);
  font-size: 13px;
}

.cpw-summary-next {
  margin-left: auto;
  color: var(--fchub-text-secondary);
  font-size: 12px;
}

.cpw-context-strip {
  justify-content: space-between;
  margin: 0 0 20px;
}

.cpw-context-strip button,
.cpw-review-heading button {
  border: 0;
  color: var(--el-color-primary);
  background: transparent;
  font: inherit;
  font-size: 12px;
  font-weight: 650;
  cursor: pointer;
}

.cpw-form {
  max-width: 720px;
}

.cpw-form .el-form-item__label {
  margin-bottom: 6px;
  font-weight: 650;
}

.cpw-control {
  width: 100%;
}

.cpw-field-help {
  width: 100%;
  margin: 7px 0 0;
  color: var(--fchub-text-secondary);
  font-size: 12px;
  line-height: 1.45;
}

.cpw-field-help code {
  color: var(--fchub-text-primary);
  background: var(--el-fill-color-light);
  padding: 1px 4px;
  border-radius: 4px;
}

.cpw-resource-popper {
  max-width: calc(100vw - 16px);
  box-sizing: border-box;
}

.cpw-resource-popper .el-select-dropdown,
.cpw-resource-popper .el-select-dropdown__wrap,
.cpw-resource-popper .el-select-dropdown__list {
  max-width: 100%;
}

.cpw-resource-popper .el-select-dropdown__item {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.cpw-alert {
  margin-bottom: 18px;
}

.cpw-choice-group {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
  width: 100%;
}

.cpw-choice-group .el-radio.is-bordered {
  width: 100%;
  height: auto;
  min-height: 44px;
  margin: 0;
}

.cpw-nested-control {
  margin-top: 12px;
}

.cpw-form-section {
  padding: 18px;
  border: 1px solid var(--fchub-border-color);
  border-radius: 10px;
  background: var(--fchub-card-bg);
}

.cpw-form-section + .cpw-form-section {
  margin-top: 16px;
}

.cpw-section-heading {
  display: grid;
  grid-template-columns: 34px minmax(0, 1fr);
  gap: 12px;
  margin-bottom: 18px;
}

.cpw-section-icon {
  width: 34px;
  height: 34px;
  border-radius: 9px;
  color: var(--el-color-primary);
  background: color-mix(in srgb, var(--el-color-primary) 9%, var(--fchub-card-bg));
}

.cpw-section-heading h4 {
  margin: 0 0 3px;
  color: var(--fchub-text-primary);
  font-size: 14px;
}

.cpw-section-heading p {
  margin: 0;
  color: var(--fchub-text-secondary);
  font-size: 12px;
  line-height: 1.4;
}

.cpw-switch-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  margin-bottom: 18px;
  padding-bottom: 18px;
  border-bottom: 1px solid var(--fchub-border-color);
}

.cpw-switch-row > span {
  display: grid;
  gap: 3px;
}

.cpw-switch-row strong {
  color: var(--fchub-text-primary);
  font-size: 13px;
}

.cpw-switch-row small {
  color: var(--fchub-text-secondary);
  font-size: 12px;
}

.cpw-review-step {
  display: grid;
  gap: 14px;
}

.cpw-review-group {
  overflow: hidden;
  border: 1px solid var(--fchub-border-color);
  border-radius: 10px;
  background: var(--fchub-card-bg);
}

.cpw-review-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 13px 16px;
  border-bottom: 1px solid var(--fchub-border-color);
  background: color-mix(in srgb, var(--fchub-card-bg) 94%, var(--el-color-primary) 6%);
}

.cpw-review-heading > span {
  display: grid;
  gap: 2px;
}

.cpw-review-heading strong {
  color: var(--fchub-text-primary);
  font-size: 13px;
}

.cpw-review-group dl {
  margin: 0;
}

.cpw-review-group dl > div {
  display: grid;
  grid-template-columns: 120px minmax(0, 1fr);
  gap: 16px;
  padding: 12px 16px;
}

.cpw-review-group dl > div + div {
  border-top: 1px solid var(--fchub-border-color);
}

.cpw-review-group dt,
.cpw-review-group dd {
  margin: 0;
  font-size: 13px;
  line-height: 1.45;
}

.cpw-review-group dt {
  color: var(--fchub-text-secondary);
  font-weight: 550;
}

.cpw-review-group dd {
  color: var(--fchub-text-primary);
}

.cpw-review-long {
  overflow-wrap: anywhere;
  white-space: pre-wrap;
}

.cpw-plan-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  padding: 14px 16px;
}

.cpw-footer {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 14px 24px;
  box-sizing: border-box;
  background: var(--fchub-card-bg);
}

.cpw-footer-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}

@media (max-width: 782px) {
  .content-protection-wizard-overlay .el-overlay-dialog {
    display: block;
    position: absolute;
    inset: 46px 0 0;
    height: calc(100dvh - 46px);
    padding: 0;
    overflow: hidden;
  }

  .content-protection-wizard {
    width: 100% !important;
    max-width: none;
    height: 100%;
    max-height: 100%;
    margin: 0 !important;
    border-radius: 0;
  }

  .content-protection-wizard .el-dialog__header {
    padding: 16px;
  }

  .content-protection-wizard .el-dialog__headerbtn {
    top: 7px;
    right: 6px;
  }

  .cpw-progress {
    overflow-x: auto;
    padding: 10px 16px;
    scroll-snap-type: x proximity;
    scrollbar-width: none;
  }

  .cpw-progress::-webkit-scrollbar {
    display: none;
  }

  .cpw-progress-list {
    display: flex;
    width: max-content;
    gap: 6px;
  }

  .cpw-progress-list li {
    width: clamp(72px, calc((100vw - 82px) / 4), 98px);
    flex: 0 0 clamp(72px, calc((100vw - 82px) / 4), 98px);
    scroll-snap-align: start;
  }

  .cpw-progress-card {
    height: 64px;
    min-height: 64px;
    grid-template-columns: 1fr;
    gap: 0;
    padding: 9px 10px;
  }

  .cpw-progress-marker,
  .cpw-progress-copy span {
    display: none;
  }

  .cpw-progress-copy strong {
    font-size: 12px;
    line-height: 1.2;
  }

  .cpw-stage {
    padding: 20px 16px 24px;
    scrollbar-gutter: auto;
  }

  .cpw-task-header h3 {
    font-size: 20px;
  }

  .cpw-category-choice {
    grid-template-columns: 18px 30px minmax(0, 1fr);
    gap: 10px;
    padding: 12px;
  }

  .cpw-category-arrow {
    display: none;
  }

  .cpw-category-icon {
    width: 30px;
    height: 30px;
    font-size: 21px;
  }

  .cpw-inline-types {
    align-items: flex-start;
    flex-direction: column;
    gap: 8px;
    margin: -2px 12px 12px 70px;
  }

  .cpw-current-selection {
    align-items: flex-start;
    flex-wrap: wrap;
  }

  .cpw-summary-next {
    width: 100%;
    margin: 0 0 0 30px;
  }

  .cpw-choice-group {
    grid-template-columns: 1fr;
  }

  .cpw-form-section {
    padding: 16px;
  }

  .cpw-review-group dl > div {
    grid-template-columns: 1fr;
    gap: 4px;
  }

  .cpw-footer {
    padding: 12px 16px;
  }

  .cpw-footer-actions {
    min-width: 0;
    flex: 1;
    justify-content: flex-end;
  }

  .cpw-footer-actions .el-button--primary {
    min-width: 112px;
  }
}

@media (max-width: 420px) {
  .cpw-footer > .el-button {
    padding-inline: 10px;
  }

  .cpw-footer-actions {
    gap: 6px;
  }

  .cpw-footer-actions .el-button {
    margin-left: 0;
    padding-inline: 11px;
  }
}
</style>
