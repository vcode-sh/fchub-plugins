<template>
  <el-dialog
    :model-value="visible"
    title="Protect Content"
    width="640px"
    :close-on-click-modal="false"
    @close="$emit('close')"
    class="wizard-dialog"
  >
    <el-steps :active="step" align-center finish-status="success" class="wizard-steps">
      <el-step title="Category" />
      <el-step title="Resource" />
      <el-step title="Configure" />
      <el-step title="Review" />
    </el-steps>

    <div class="wizard-body">
      <div v-if="step === 0" class="wizard-step-content">
        <p class="wizard-instruction">What type of content do you want to protect?</p>
        <div class="wizard-category-grid">
          <div
            v-for="card in categoryCards"
            :key="card.key"
            class="wizard-category-card"
            :class="{ selected: form.categoryKey === card.key }"
            @click="$emit('select-category', card)"
          >
            <el-icon :size="28"><component :is="card.icon" /></el-icon>
            <span class="wizard-category-label">{{ card.label }}</span>
          </div>
        </div>
      </div>

      <div v-if="step === 1" class="wizard-step-content">
        <p class="wizard-instruction">Select the {{ form.categoryLabel }} to protect</p>

        <el-form-item v-if="categoryTypes.length > 1" label="Resource Type" class="wizard-form-item">
          <el-select v-model="form.resource_type" placeholder="Select type" style="width: 100%" @change="$emit('type-change')">
            <el-option v-for="t in categoryTypes" :key="t.value" :label="t.label" :value="t.value" />
          </el-select>
        </el-form-item>

        <el-form-item v-if="form.resource_type === 'url_pattern'" label="URL Pattern" class="wizard-form-item">
          <el-input v-model="form.resource_id" placeholder="e.g. /members-only/* or /premium/*" />
          <div class="field-hint">Use * as wildcard. Example: /premium/* matches all URLs starting with /premium/</div>
        </el-form-item>

        <el-form-item v-else-if="form.resource_type === 'special_page'" label="Special Page" class="wizard-form-item">
          <el-select v-model="form.resource_id" placeholder="Select a special page" style="width: 100%" :loading="resourceLoading">
            <el-option v-for="item in resourceOptions" :key="item.id" :label="item.label || item.title" :value="String(item.id)" />
          </el-select>
        </el-form-item>

        <el-form-item v-else-if="form.resource_type === 'comment'" label="Comment Protection" class="wizard-form-item">
          <el-radio-group v-model="form.commentMode" @change="$emit('comment-mode-change')">
            <el-radio value="all">All protected content comments</el-radio>
            <el-radio value="specific">Comments on a specific post</el-radio>
          </el-radio-group>
          <el-select
            v-if="form.commentMode === 'specific'"
            v-model="form.resource_id"
            filterable
            remote
            :remote-method="searchResources"
            :loading="resourceLoading"
            placeholder="Search for a post..."
            style="width: 100%; margin-top: 8px"
          >
            <el-option v-for="item in resourceOptions" :key="item.id" :label="item.label || item.title" :value="String(item.id)" />
          </el-select>
        </el-form-item>

        <el-form-item v-else label="Resource" class="wizard-form-item">
          <el-select
            v-model="form.resource_id"
            filterable
            remote
            :remote-method="searchResources"
            :loading="resourceLoading"
            placeholder="Search for content..."
            style="width: 100%"
          >
            <el-option v-for="item in resourceOptions" :key="item.id" :label="item.label || item.title" :value="String(item.id)" />
          </el-select>
        </el-form-item>
      </div>

      <div v-if="step === 2" class="wizard-step-content">
        <p class="wizard-instruction">Configure protection settings</p>

        <el-form label-position="top">
          <el-form-item label="Plans" required>
            <el-select v-model="form.plan_ids" multiple placeholder="Select plans that grant access" style="width: 100%" :loading="planOptionsLoading">
              <el-option v-for="plan in planOptions" :key="plan.id" :label="plan.title" :value="plan.id" />
            </el-select>
          </el-form-item>

          <el-form-item label="Show Teaser">
            <el-select v-model="form.show_teaser" style="width: 200px">
              <el-option label="No" value="no" />
              <el-option label="Yes" value="yes" />
            </el-select>
            <div class="field-hint">Show a preview excerpt before the restriction message.</div>
          </el-form-item>

          <el-form-item label="Restriction Message">
            <el-input v-model="form.restriction_message" type="textarea" :rows="3" placeholder="Custom message shown to non-members (leave empty for default)" />
          </el-form-item>

          <el-form-item label="Redirect URL">
            <el-input v-model="form.redirect_url" placeholder="https://example.com/upgrade (optional)" />
            <div class="field-hint">Redirect non-members to this URL instead of showing the restriction message.</div>
          </el-form-item>
        </el-form>
      </div>

      <div v-if="step === 3" class="wizard-step-content">
        <p class="wizard-instruction">Review and confirm</p>

        <div class="review-summary">
          <div class="review-row">
            <span class="review-label">Type</span>
            <span class="review-value"><el-tag size="small">{{ form.resource_type_label || form.resource_type }}</el-tag></span>
          </div>
          <div class="review-row">
            <span class="review-label">Resource</span>
            <span class="review-value">{{ resourceDisplayName }}</span>
          </div>
          <div class="review-row">
            <span class="review-label">Plans</span>
            <span class="review-value">
              <el-tag v-for="id in form.plan_ids" :key="id" size="small" type="info" class="plan-tag">
                {{ planOptionsMap[id] || `Plan #${id}` }}
              </el-tag>
            </span>
          </div>
          <div class="review-row">
            <span class="review-label">Teaser</span>
            <span class="review-value">{{ form.show_teaser === 'yes' ? 'Enabled' : 'Disabled' }}</span>
          </div>
          <div class="review-row" v-if="form.restriction_message">
            <span class="review-label">Message</span>
            <span class="review-value review-message">{{ form.restriction_message }}</span>
          </div>
          <div class="review-row" v-if="form.redirect_url">
            <span class="review-label">Redirect</span>
            <span class="review-value">{{ form.redirect_url }}</span>
          </div>
        </div>
      </div>
    </div>

    <template #footer>
      <div class="wizard-footer">
        <el-button v-if="step > 0" @click="$emit('back')">Back</el-button>
        <div class="wizard-footer-right">
          <el-button @click="$emit('close')">Cancel</el-button>
          <el-button v-if="step < 3" type="primary" :disabled="!canAdvance" @click="$emit('next')">
            Next
          </el-button>
          <el-button v-else type="primary" :loading="saving" @click="$emit('submit')">
            Protect
          </el-button>
        </div>
      </div>
    </template>
  </el-dialog>
</template>

<script setup>
defineProps({
  visible: Boolean,
  step: Number,
  form: { type: Object, required: true },
  categoryCards: { type: Array, default: () => [] },
  categoryTypes: { type: Array, default: () => [] },
  resourceLoading: Boolean,
  resourceOptions: { type: Array, default: () => [] },
  planOptionsLoading: Boolean,
  planOptions: { type: Array, default: () => [] },
  planOptionsMap: { type: Object, required: true },
  resourceDisplayName: { type: String, required: true },
  canAdvance: Boolean,
  saving: Boolean,
  searchResources: { type: Function, required: true },
})

defineEmits(['close', 'back', 'next', 'submit', 'select-category', 'type-change', 'comment-mode-change'])
</script>
