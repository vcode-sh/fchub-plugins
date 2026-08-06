<template>
  <section class="builder-panel" aria-labelledby="access-step-heading">
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
          @change="$emit('add-space-group', $event)"
        >
          <el-option
            v-for="group in spaceGroups"
            :key="group.id"
            :label="`${group.label} (${group.spaces.length})`"
            :value="group.id"
            :disabled="group.spaces.length === 0"
          />
        </el-select>
        <el-button v-if="form.rules.length" :disabled="hasReadOnlyRules" @click="$emit('add-rule')">
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
      <el-button type="primary" class="empty-state-action" @click="$emit('add-rule')">
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
          @click="$emit('remove-rule', index)"
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
            @change="$emit('resource-type-change', index, rule)"
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
              <el-option v-for="page in specialPageOptions" :key="page.id" :label="page.label" :value="page.id" />
            </el-select>
          </template>
          <template v-else>
            <el-select
              v-model="rule.resource_id"
              filterable
              remote
              clearable
              :disabled="isPlanRuleControlLocked(form.rules, rule)"
              :remote-method="(query) => $emit('search-resource', index, rule.resource_type, query)"
              :loading="ruleResourceLoading[index]"
              placeholder="Search by title..."
              @clear="$emit('reset-resource', rule)"
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
          <el-select v-model="rule.drip_type" :disabled="hasReadOnlyRules" placeholder="Timing" @change="$emit('drip-type-change', rule)">
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
            :format="datePickerFormat"
            value-format="YYYY-MM-DD"
            :disabled-date="isPastDate"
          />
        </el-form-item>
      </div>
    </article>
  </section>
</template>

<script setup>
import { Delete, Lock, Plus, WarningFilled } from '@element-plus/icons-vue'
import { isPlanRuleControlLocked } from '@/utils/planRulePayload.js'

defineProps({
  form: { type: Object, required: true },
  hasFcSpaceResourceType: { type: Boolean, required: true },
  hasReadOnlyRules: { type: Boolean, required: true },
  spaceGroups: { type: Array, required: true },
  spaceGroupsLoading: { type: Boolean, required: true },
  resourceTypeGroups: { type: Array, required: true },
  ruleResourceOptions: { type: Object, required: true },
  ruleResourceLoading: { type: Object, required: true },
  specialPageOptions: { type: Array, required: true },
  getTypeConfig: { type: Function, required: true },
  resourceIdRules: { type: Function, required: true },
  ruleSummary: { type: Function, required: true },
  isPastDate: { type: Function, required: true },
  datePickerFormat: { type: String, required: true },
})

defineEmits([
  'add-space-group',
  'add-rule',
  'remove-rule',
  'resource-type-change',
  'search-resource',
  'reset-resource',
  'drip-type-change',
])

const selectedSpaceGroupId = defineModel('selectedSpaceGroupId', { type: String, required: true })
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

.builder-panel-heading p {
  margin: 6px 0 0;
  color: var(--fchub-text-secondary);
  font-size: 13px;
  line-height: 1.55;
}

.access-rules-heading {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 20px;
  margin: 0;
}

.access-rules-actions {
  min-width: 0;
  display: flex;
  align-items: center;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 8px;
}

.space-group-selector {
  min-width: 220px;
}

.access-rules-actions .el-icon,
.empty-state-action .el-icon {
  margin-right: 6px;
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
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin: 0;
  padding: 14px 16px;
  border-bottom: 1px solid var(--fchub-border-color);
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

.remove-rule-btn {
  flex-shrink: 0;
  margin: 0;
}

.rule-warning-icon {
  margin-left: 6px;
  color: var(--el-color-warning);
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
  margin-top: 4px;
  color: var(--el-text-color-secondary);
  font-size: 12px;
  line-height: 1.4;
}

@media (max-width: 782px) {
  .builder-panel-heading {
    padding-left: 18px;
    padding-right: 18px;
  }

  .builder-panel-heading h2 {
    font-size: 19px;
  }

  .access-rules-heading,
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

  .rule-fields {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
