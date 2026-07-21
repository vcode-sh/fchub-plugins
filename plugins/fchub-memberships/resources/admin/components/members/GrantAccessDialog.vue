<template>
  <el-dialog
    :model-value="visible"
    title="Grant Access"
    width="680px"
    modal-class="grant-access-dialog-overlay"
    class="grant-access-dialog"
    :close-on-click-modal="false"
    :close-on-press-escape="!loading"
    :show-close="!loading"
    destroy-on-close
    @close="emit('close')"
  >
    <div class="grant-access-shell">
      <header class="grant-task-header">
        <span class="grant-task-eyebrow">MANUAL ACCESS</span>
        <h3>{{ taskTitle }}</h3>
        <p>{{ taskDescription }}</p>
      </header>

      <el-alert
        v-if="userSearchError"
        :title="userSearchError"
        type="error"
        :closable="false"
        show-icon
        class="grant-search-alert"
      />

      <el-form :model="form" label-position="top" class="grant-access-form">
        <section class="grant-form-section" aria-labelledby="grant-member-heading">
          <div class="grant-section-heading">
            <span class="grant-section-icon" aria-hidden="true"><el-icon><UserFilled /></el-icon></span>
            <span>
              <h4 id="grant-member-heading">Member</h4>
              <p>{{ fixedUser ? 'This profile is the selected WordPress account.' : 'Find the WordPress account that should receive access.' }}</p>
            </span>
          </div>

          <div v-if="fixedUser" class="grant-fixed-member" aria-label="Selected member">
            <span class="grant-fixed-avatar" aria-hidden="true">{{ fixedUserInitials }}</span>
            <span class="grant-fixed-copy">
              <strong>{{ fixedUser.display_name }}</strong>
              <small>{{ fixedUser.email || fixedUser.user_email }}</small>
            </span>
            <el-tag type="success" effect="light" size="small">Selected</el-tag>
          </div>

          <el-form-item v-else label="User" required>
            <el-select
              :model-value="form.user_id"
              filterable
              remote
              :remote-method="searchUsers"
              :debounce="250"
              :loading="searchingUsers"
              placeholder="Choose or search for a user"
              class="grant-control grant-user-picker"
              popper-class="grant-user-popper"
              no-data-text="No users found"
              no-match-text="No matching users"
              @visible-change="onUserPickerVisibility"
              @update:model-value="emit('update:userId', $event)"
            >
              <el-option
                v-for="user in userResults"
                :key="user.id"
                :label="`${user.display_name} (${user.email})`"
                :value="user.id"
              >
                <span class="grant-user-option">
                  <strong>{{ user.display_name }}</strong>
                  <small>{{ user.email }}</small>
                </span>
              </el-option>
            </el-select>
            <p class="grant-field-help">Browse recent users or type at least 2 characters to search.</p>
          </el-form-item>
        </section>

        <section class="grant-form-section" aria-labelledby="grant-plan-heading">
          <div class="grant-section-heading">
            <span class="grant-section-icon" aria-hidden="true"><el-icon><Key /></el-icon></span>
            <span>
              <h4 id="grant-plan-heading">Membership access</h4>
              <p>Select the plan that defines the member's permissions and default duration.</p>
            </span>
          </div>

          <el-form-item label="Plan" required>
            <el-select
              :model-value="form.plan_id"
              placeholder="Choose a membership plan"
              class="grant-control"
              popper-class="grant-plan-popper"
              no-data-text="No membership plans are available"
              @update:model-value="emit('update:planId', $event)"
            >
              <el-option
                v-for="plan in planOptions"
                :key="plan.id"
                :label="plan.title"
                :value="plan.id"
              />
            </el-select>
            <p class="grant-field-help">The plan's configured expiry applies unless you override it below.</p>
          </el-form-item>
        </section>

        <section class="grant-form-section grant-optional-section" aria-labelledby="grant-details-heading">
          <div class="grant-section-heading">
            <span class="grant-section-icon" aria-hidden="true"><el-icon><EditPen /></el-icon></span>
            <span>
              <h4 id="grant-details-heading">Optional details</h4>
              <p>Override the access end date or leave an audit note for other administrators.</p>
            </span>
          </div>

          <div class="grant-optional-grid">
            <el-form-item label="Expiry override">
              <el-date-picker
                :model-value="form.expires_at"
                type="date"
                placeholder="Use plan default"
                :format="datePickerFormat"
                value-format="YYYY-MM-DD"
                class="grant-control"
                @update:model-value="emit('update:expiresAt', $event)"
              />
              <p class="grant-field-help">Leave empty to follow the plan automatically.</p>
            </el-form-item>

            <el-form-item label="Audit note">
              <el-input
                :model-value="form.reason"
                type="textarea"
                :rows="2"
                maxlength="300"
                show-word-limit
                placeholder="Why is access being granted?"
                @update:model-value="emit('update:reason', $event)"
              />
            </el-form-item>
          </div>
        </section>
      </el-form>

      <section
        class="grant-access-summary"
        :class="{ 'is-ready': isReady }"
        role="region"
        aria-label="Access summary"
        aria-live="polite"
      >
        <span class="grant-summary-icon" aria-hidden="true"><el-icon><CircleCheck /></el-icon></span>
        <div class="grant-summary-copy">
          <small>ACCESS SUMMARY</small>
          <strong>{{ summaryTitle }}</strong>
          <dl>
            <div>
              <dt>Member</dt>
              <dd>{{ effectiveSelectedUser?.display_name || 'Not selected' }}</dd>
            </div>
            <div>
              <dt>Plan</dt>
              <dd>{{ selectedPlan?.title || 'Not selected' }}</dd>
            </div>
            <div>
              <dt>Access ends</dt>
              <dd>{{ form.expires_at || 'Plan default' }}</dd>
            </div>
          </dl>
        </div>
      </section>
    </div>

    <template #footer>
      <div class="grant-access-footer">
        <span class="grant-footer-hint">
          {{ isReady ? 'Ready to grant access.' : 'Choose a member and plan to continue.' }}
        </span>
        <div class="grant-footer-actions">
          <el-button :disabled="loading" @click="emit('close')">Cancel</el-button>
          <el-button
            type="primary"
            :loading="loading"
            :disabled="!isReady || loading"
            @click="emit('confirm')"
          >
            Grant access
          </el-button>
        </div>
      </div>
    </template>
  </el-dialog>
</template>

<script setup>
import { computed } from 'vue'
import { CircleCheck, EditPen, Key, UserFilled } from '@element-plus/icons-vue'

const props = defineProps({
  visible: {
    type: Boolean,
    default: false,
  },
  form: {
    type: Object,
    required: true,
  },
  loading: {
    type: Boolean,
    default: false,
  },
  searchingUsers: {
    type: Boolean,
    default: false,
  },
  userResults: {
    type: Array,
    default: () => [],
  },
  userSearchError: {
    type: String,
    default: '',
  },
  selectedUser: {
    type: Object,
    default: null,
  },
  fixedUser: {
    type: Object,
    default: null,
  },
  planOptions: {
    type: Array,
    default: () => [],
  },
  datePickerFormat: {
    type: String,
    required: true,
  },
  searchUsers: {
    type: Function,
    default: () => {},
  },
})

const emit = defineEmits(['close', 'confirm', 'update:userId', 'update:planId', 'update:expiresAt', 'update:reason'])

const selectedPlan = computed(() => (
  props.planOptions.find(({ id }) => String(id) === String(props.form.plan_id)) || null
))
const effectiveSelectedUser = computed(() => props.fixedUser || props.selectedUser)
const isReady = computed(() => Boolean((props.fixedUser || props.form.user_id) && props.form.plan_id))
const fixedUserInitials = computed(() => {
  const name = String(props.fixedUser?.display_name || '').trim()
  const parts = name.split(/\s+/).filter(Boolean)
  if (parts.length) return parts.slice(0, 2).map((part) => part.charAt(0)).join('').toUpperCase()
  const email = String(props.fixedUser?.email || props.fixedUser?.user_email || '').trim()
  return email ? email.charAt(0).toUpperCase() : '?'
})
const taskTitle = computed(() => (
  props.fixedUser
    ? `Create a manual grant for ${props.fixedUser.display_name}`
    : 'Create a manual membership grant'
))
const taskDescription = computed(() => (
  props.fixedUser
    ? 'Choose the membership plan and confirm how long this access should remain active.'
    : 'Choose an existing WordPress user and the membership plan they should receive.'
))
const summaryTitle = computed(() => {
  if (!isReady.value) {
    return 'Select a member and plan to preview this grant.'
  }

  return `${effectiveSelectedUser.value?.display_name || 'Selected member'} will receive ${selectedPlan.value?.title || 'the selected plan'}.`
})

function onUserPickerVisibility(visible) {
  if (visible) {
    props.searchUsers('')
  }
}
</script>

<style>
.grant-access-dialog-overlay .el-overlay-dialog {
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
  body.wp-admin .grant-access-dialog-overlay .el-overlay-dialog {
    top: 48px;
    left: 160px;
  }

  body.wp-admin.folded .grant-access-dialog-overlay .el-overlay-dialog {
    left: 36px;
  }
}

@media (min-width: 783px) and (max-width: 960px) {
  body.wp-admin.auto-fold .grant-access-dialog-overlay .el-overlay-dialog {
    left: 36px;
  }
}

.grant-access-dialog {
  width: min(680px, 100%) !important;
  height: auto;
  max-height: none;
  margin: 0 !important;
  display: flex;
  flex-direction: column;
  box-sizing: border-box;
  overflow: hidden;
  border-radius: 12px;
}

.grant-access-dialog .el-dialog__header {
  flex: 0 0 auto;
  margin: 0;
  padding: 20px 24px;
  border-bottom: 1px solid var(--fchub-border-color);
}

.grant-access-dialog .el-dialog__title {
  color: var(--fchub-text-primary);
  font-size: 18px;
  font-weight: 650;
}

.grant-access-dialog .el-dialog__headerbtn {
  top: 12px;
  right: 14px;
  width: 44px;
  height: 44px;
}

.grant-access-dialog .el-dialog__body {
  flex: 1 1 auto;
  min-height: 0;
  padding: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
  scrollbar-gutter: stable;
}

.grant-access-dialog .el-dialog__footer {
  flex: 0 0 auto;
  padding: 0;
  border-top: 1px solid var(--fchub-border-color);
}

.grant-access-shell {
  padding: 24px;
}

.grant-task-header {
  margin-bottom: 20px;
}

.grant-task-eyebrow,
.grant-summary-copy > small {
  color: var(--el-color-primary);
  font-size: 10px;
  font-weight: 800;
  letter-spacing: 0.08em;
}

.grant-task-header h3 {
  margin: 5px 0;
  color: var(--fchub-text-primary);
  font-size: 22px;
  font-weight: 700;
  line-height: 1.25;
}

.grant-task-header p,
.grant-section-heading p,
.grant-field-help {
  color: var(--fchub-text-secondary);
}

.grant-task-header p {
  margin: 0;
  font-size: 14px;
  line-height: 1.55;
}

.grant-search-alert {
  margin-bottom: 16px;
}

.grant-access-form {
  display: grid;
  gap: 12px;
}

.grant-form-section {
  padding: 18px;
  border: 1px solid var(--fchub-border-color);
  border-radius: 10px;
  background: var(--fchub-card-bg);
}

.grant-section-heading {
  display: grid;
  grid-template-columns: 34px minmax(0, 1fr);
  align-items: start;
  gap: 12px;
  margin-bottom: 16px;
}

.grant-section-icon,
.grant-summary-icon {
  display: grid;
  place-items: center;
  color: var(--el-color-primary);
  border: 1px solid color-mix(in srgb, var(--el-color-primary) 24%, var(--fchub-border-color));
  background: color-mix(in srgb, var(--el-color-primary) 7%, var(--fchub-card-bg));
}

.grant-section-icon {
  width: 32px;
  height: 32px;
  border-radius: 9px;
  font-size: 17px;
}

.grant-section-heading h4 {
  margin: 0 0 3px;
  color: var(--fchub-text-primary);
  font-size: 14px;
  font-weight: 700;
  line-height: 1.3;
}

.grant-section-heading p {
  margin: 0;
  font-size: 12px;
  line-height: 1.45;
}

.grant-access-form .el-form-item {
  margin-bottom: 0;
}

.grant-fixed-member {
  display: grid;
  grid-template-columns: 40px minmax(0, 1fr) auto;
  align-items: center;
  gap: 12px;
  padding: 12px;
  border: 1px solid color-mix(in srgb, var(--el-color-primary) 20%, var(--fchub-border-color));
  border-radius: 9px;
  background: color-mix(in srgb, var(--fchub-card-bg) 95%, var(--el-color-primary) 5%);
}

.grant-fixed-avatar {
  display: grid;
  width: 40px;
  height: 40px;
  place-items: center;
  border-radius: 11px;
  color: #fff;
  background: var(--el-color-primary);
  font-size: 13px;
  font-weight: 750;
  letter-spacing: 0.03em;
}

.grant-fixed-copy {
  min-width: 0;
  display: grid;
  gap: 2px;
}

.grant-fixed-copy strong,
.grant-fixed-copy small {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.grant-fixed-copy strong {
  color: var(--fchub-text-primary);
  font-size: 13px;
}

.grant-fixed-copy small {
  color: var(--fchub-text-secondary);
  font-size: 11px;
}

.grant-control {
  width: 100% !important;
}

.grant-field-help {
  width: 100%;
  margin: 6px 0 0;
  font-size: 11px;
  line-height: 1.45;
}

.grant-optional-grid {
  display: grid;
  grid-template-columns: minmax(0, 0.8fr) minmax(0, 1.2fr);
  align-items: start;
  gap: 16px;
}

.grant-access-summary {
  display: grid;
  grid-template-columns: 38px minmax(0, 1fr);
  gap: 12px;
  margin-top: 14px;
  padding: 16px;
  border: 1px solid var(--fchub-border-color);
  border-radius: 10px;
  background: color-mix(in srgb, var(--fchub-card-bg) 94%, var(--el-color-primary) 6%);
}

.grant-access-summary.is-ready {
  border-color: color-mix(in srgb, var(--el-color-success) 36%, var(--fchub-border-color));
  background: color-mix(in srgb, var(--fchub-card-bg) 94%, var(--el-color-success) 6%);
}

.grant-summary-icon {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  font-size: 19px;
}

.grant-access-summary.is-ready .grant-summary-icon {
  color: var(--el-color-success);
  border-color: color-mix(in srgb, var(--el-color-success) 34%, var(--fchub-border-color));
  background: color-mix(in srgb, var(--el-color-success) 9%, var(--fchub-card-bg));
}

.grant-summary-copy > strong {
  display: block;
  margin: 3px 0 10px;
  color: var(--fchub-text-primary);
  font-size: 13px;
  line-height: 1.4;
}

.grant-summary-copy dl {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
  margin: 0;
}

.grant-summary-copy dl > div {
  min-width: 0;
}

.grant-summary-copy dt,
.grant-summary-copy dd {
  margin: 0;
  overflow-wrap: anywhere;
}

.grant-summary-copy dt {
  color: var(--fchub-text-secondary);
  font-size: 10px;
  font-weight: 650;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.grant-summary-copy dd {
  margin-top: 2px;
  color: var(--fchub-text-primary);
  font-size: 12px;
  line-height: 1.35;
}

.grant-access-footer {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 14px 24px;
  box-sizing: border-box;
  background: var(--fchub-card-bg);
}

.grant-footer-hint {
  color: var(--fchub-text-secondary);
  font-size: 12px;
}

.grant-footer-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

.grant-user-popper,
.grant-plan-popper {
  max-width: calc(100vw - 16px);
  box-sizing: border-box;
}

.grant-user-popper .el-select-dropdown,
.grant-user-popper .el-select-dropdown__wrap,
.grant-user-popper .el-select-dropdown__list,
.grant-plan-popper .el-select-dropdown,
.grant-plan-popper .el-select-dropdown__wrap,
.grant-plan-popper .el-select-dropdown__list {
  max-width: 100%;
}

.grant-user-popper .el-select-dropdown__item,
.grant-plan-popper .el-select-dropdown__item {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.grant-user-option {
  min-width: 0;
  display: grid;
  align-content: center;
  line-height: 1.25;
}

.grant-user-option strong,
.grant-user-option small {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.grant-user-option strong {
  color: var(--fchub-text-primary);
  font-size: 12px;
}

.grant-user-option small {
  color: var(--fchub-text-secondary);
  font-size: 10px;
}

.grant-user-popper .el-select-dropdown__item {
  height: 48px;
  padding-block: 5px;
}

@media (max-width: 782px) {
  .grant-access-dialog-overlay .el-overlay-dialog {
    display: block;
    position: absolute;
    inset: 46px 0 0;
    height: calc(100dvh - 46px);
    padding: 0;
    overflow: hidden;
  }

  .grant-access-dialog {
    width: 100% !important;
    max-width: none;
    height: 100%;
    max-height: 100%;
    margin: 0 !important;
    border-radius: 0;
  }

  .grant-access-dialog .el-dialog__header {
    padding: 16px;
  }

  .grant-access-dialog .el-dialog__headerbtn {
    top: 7px;
    right: 6px;
  }

  .grant-access-dialog .el-dialog__body {
    scrollbar-gutter: auto;
  }

  .grant-access-shell {
    padding: 20px 16px 24px;
  }

  .grant-task-header h3 {
    font-size: 20px;
  }

  .grant-form-section {
    padding: 16px;
  }

  .grant-optional-grid,
  .grant-summary-copy dl {
    grid-template-columns: 1fr;
  }

  .grant-access-footer {
    padding: 12px 16px;
  }

  .grant-footer-hint {
    display: none;
  }

  .grant-footer-actions {
    width: 100%;
    justify-content: flex-end;
  }

  .grant-footer-actions .el-button--primary {
    min-width: 120px;
  }
}

@media (max-width: 420px) {
  .grant-footer-actions {
    gap: 6px;
  }

  .grant-footer-actions .el-button {
    margin-left: 0;
    padding-inline: 11px;
  }
}
</style>
