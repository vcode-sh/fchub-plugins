<template>
  <div class="fchub-settings-section">
    <div class="fchub-settings-section-title">Notifications</div>

    <div class="fchub-setting-row">
      <div class="fchub-setting-label">
        <h4>Email on Access Granted</h4>
        <p>Send an email when a user is granted membership access.</p>
      </div>
      <div class="fchub-setting-control"><el-switch v-model="form.email_access_granted" /></div>
    </div>

    <div class="fchub-setting-row">
      <div class="fchub-setting-label">
        <h4>Email on Access Expiring</h4>
        <p>Notify members before their access expires.</p>
      </div>
      <div class="fchub-setting-control">
        <div class="control-row">
          <el-switch v-model="form.email_access_expiring" />
          <div v-if="form.email_access_expiring" class="inline-number">
            <span class="inline-label">Warn</span>
            <el-input-number v-model="form.email_expiring_days_before" :min="1" :max="90" size="small" />
            <span class="inline-label">days before</span>
          </div>
        </div>
      </div>
    </div>

    <div class="fchub-setting-row">
      <div class="fchub-setting-label">
        <h4>Email on Access Revoked</h4>
        <p>Send an email when a user's membership access is revoked.</p>
      </div>
      <div class="fchub-setting-control"><el-switch v-model="form.email_access_revoked" /></div>
    </div>

    <div class="fchub-setting-row">
      <div class="fchub-setting-label">
        <h4>Email on Drip Content Unlocked</h4>
        <p>Notify members when new drip content becomes available to them.</p>
      </div>
      <div class="fchub-setting-control"><el-switch v-model="form.email_drip_unlocked" /></div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  form: { type: Object, required: true },
})
</script>
