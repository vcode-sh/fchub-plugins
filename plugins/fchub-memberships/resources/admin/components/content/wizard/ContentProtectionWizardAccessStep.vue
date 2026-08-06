<template>
  <section class="cpw-step cpw-access-step">
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
</template>

<script setup>
import { UserFilled, View } from '@element-plus/icons-vue'

defineProps({
  form: { type: Object, required: true },
  planOptionsLoading: Boolean,
  planOptions: { type: Array, default: () => [] },
})
</script>

<style>
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
</style>
