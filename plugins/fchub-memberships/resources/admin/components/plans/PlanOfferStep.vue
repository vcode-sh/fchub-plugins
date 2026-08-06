<template>
  <section class="builder-panel" aria-labelledby="offer-step-heading">
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
          @input="$emit('title-input', $event)"
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
            @click="$emit('duration-select', option.value)"
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
            @input="$emit('slug-input', $event)"
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
            <el-option v-for="option in planOptions" :key="option.id" :label="option.title" :value="option.id" />
          </el-select>
          <div class="field-hint">Members also receive access granted by the selected plans.</div>
        </el-form-item>

        <el-divider content-position="left">Membership term</el-divider>

        <el-form-item label="Membership term">
          <el-select v-model="form.meta.membership_term.mode" @change="$emit('term-mode-change', $event)">
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
            :format="datePickerFormat"
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
          :format-date-time="formatDateTime"
          :date-time-picker-format="dateTimePickerFormat"
          @update:new-status="schedule.new_status = $event"
          @update:new-at="schedule.new_at = $event"
          @save="$emit('save-schedule')"
          @clear="$emit('clear-schedule')"
        />
      </div>
    </section>
  </section>
</template>

<script setup>
import { ArrowDown } from '@element-plus/icons-vue'
import PlanSchedulePanel from './PlanSchedulePanel.vue'

defineProps({
  form: { type: Object, required: true },
  isNew: { type: Boolean, required: true },
  durationOptions: { type: Array, required: true },
  planOptions: { type: Array, required: true },
  slugPreviewLoading: { type: Boolean, required: true },
  slugPreviewError: { type: String, required: true },
  slugManuallyEdited: { type: Boolean, required: true },
  slugAvailable: { type: Boolean, required: true },
  termHintText: { type: String, required: true },
  schedule: { type: Object, required: true },
  scheduleSaving: { type: Boolean, required: true },
  formatDateTime: { type: Function, required: true },
  datePickerFormat: { type: String, required: true },
  dateTimePickerFormat: { type: String, required: true },
})

defineEmits([
  'title-input',
  'slug-input',
  'duration-select',
  'term-mode-change',
  'save-schedule',
  'clear-schedule',
])

const advancedOpen = defineModel('advancedOpen', { type: Boolean, required: true })
</script>

<style scoped>
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

.builder-panel-heading p,
.section-heading-inline p {
  color: var(--fchub-text-secondary);
  font-size: 13px;
  line-height: 1.55;
}

.builder-panel-heading p {
  margin: 6px 0 0;
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
  font-size: 12px;
}

.field-hint {
  flex-basis: 100%;
  margin-top: 4px;
  color: var(--fchub-text-secondary);
  font-size: 12px;
  line-height: 1.4;
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
  min-height: 92px;
  display: grid;
  grid-template-columns: 18px minmax(0, 1fr);
  gap: 10px;
  align-items: start;
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

@media (max-width: 1180px) {
  .advanced-field-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 782px) {
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
  .term-custom-row {
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
}
</style>
