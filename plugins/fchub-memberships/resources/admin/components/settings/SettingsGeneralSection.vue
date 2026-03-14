<template>
  <div class="fchub-settings-section">
    <div class="fchub-settings-section-title">General Settings</div>

    <div class="fchub-setting-row">
      <div class="fchub-setting-label">
        <h4>Restriction Mode</h4>
        <p>How to handle access for non-members trying to view protected content.</p>
      </div>
      <div class="fchub-setting-control">
        <el-select v-model="form.restriction_mode" style="width: 100%">
          <el-option label="Replace Content with Message" value="content_replace" />
          <el-option label="Redirect to URL" value="redirect" />
          <el-option label="Show 403 Forbidden" value="403" />
        </el-select>
      </div>
    </div>

    <div class="fchub-setting-row">
      <div class="fchub-setting-label">
        <h4>Default Restriction Message</h4>
        <p>Message shown when content is restricted. Supports basic HTML.</p>
      </div>
      <div class="fchub-setting-control">
        <el-input v-model="form.default_restriction_message" type="textarea" :rows="3" placeholder="This content is restricted to members only." />
      </div>
    </div>

    <div class="fchub-setting-row">
      <div class="fchub-setting-label">
        <h4>Paused Membership Message</h4>
        <p>Message shown when a member's access is paused. Supports basic HTML.</p>
      </div>
      <div class="fchub-setting-control">
        <el-input
          v-model="form.restriction_message_paused"
          type="textarea"
          :rows="2"
          placeholder="Your membership is currently paused. Resume your membership to access this content."
        />
      </div>
    </div>

    <div class="fchub-setting-row" v-if="form.restriction_mode === 'redirect'">
      <div class="fchub-setting-label">
        <h4>Redirect URL</h4>
        <p>Non-members will be redirected to this URL when they try to access protected content.</p>
      </div>
      <div class="fchub-setting-control">
        <el-input v-model="form.redirect_url" placeholder="https://example.com/membership" />
      </div>
    </div>
  </div>

  <div class="fchub-settings-section">
    <div class="fchub-settings-section-title">Membership Rules</div>

    <div class="fchub-setting-row">
      <div class="fchub-setting-label">
        <h4>Membership Mode</h4>
        <p>Controls whether users can hold multiple membership plans simultaneously.</p>
      </div>
      <div class="fchub-setting-control">
        <el-select v-model="form.membership_mode" style="width: 100%">
          <el-option label="Allow Stacking (Multiple Plans)" value="stack" />
          <el-option label="Exclusive (One Plan Only)" value="exclusive" />
          <el-option label="Upgrade Only (Level-Based)" value="upgrade_only" />
        </el-select>
        <div class="setting-description" style="margin-top: 8px; font-size: 12px; color: var(--fchub-text-secondary); line-height: 1.5">
          <template v-if="form.membership_mode === 'stack'">
            Users can hold multiple plans at once. Access from all plans is cumulative.
          </template>
          <template v-else-if="form.membership_mode === 'exclusive'">
            Users can only have one active plan. Purchasing a new plan automatically revokes the previous one.
          </template>
          <template v-else-if="form.membership_mode === 'upgrade_only'">
            Users can only move to equal or higher-level plans. Lower-level plans are automatically revoked on upgrade.
          </template>
        </div>
        <el-alert
          v-if="form.membership_mode === 'upgrade_only'"
          type="info"
          :closable="false"
          show-icon
          style="margin-top: 8px"
        >
          Plans are compared by their Level field. Make sure all plans have appropriate levels configured in the Plan Editor.
        </el-alert>
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  form: { type: Object, required: true },
})
</script>
