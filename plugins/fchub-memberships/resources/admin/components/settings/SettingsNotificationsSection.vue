<template>
  <div class="fchub-settings-section notification-settings">
    <div class="fchub-settings-section-title">Member email preferences</div>

    <div class="notification-preferences" aria-label="Notification preferences">
      <article class="notification-preference" role="group" aria-labelledby="notification-access-granted">
        <div class="notification-preference-header">
          <div class="notification-preference-copy">
            <h3 id="notification-access-granted">Access granted</h3>
            <p>{{ form.email_access_granted ? 'Members receive an email as soon as access is granted.' : 'No email is sent when access is granted.' }}</p>
          </div>
          <el-switch v-model="form.email_access_granted" aria-label="Email on access granted" />
        </div>
      </article>

      <article class="notification-preference" role="group" aria-labelledby="notification-access-expiry">
        <div class="notification-preference-header">
          <div class="notification-preference-copy">
            <h3 id="notification-access-expiry">Access expiring</h3>
            <p>{{ form.email_access_expiring ? 'Members receive a reminder before their access expires.' : 'No reminder is sent before access expires.' }}</p>
          </div>
          <el-switch v-model="form.email_access_expiring" aria-label="Email on access expiring" />
        </div>

        <Transition name="preference-detail">
          <div v-if="form.email_access_expiring" class="notification-timing">
            <div class="notification-timing-copy">
              <label for="expiry-warning-days">Reminder timing</label>
              <p>Choose how early members should receive the warning.</p>
            </div>
            <div class="notification-timing-field">
              <el-input-number
                id="expiry-warning-days"
                v-model="form.email_expiring_days_before"
                aria-label="Days before access expires"
                :min="1"
                :max="90"
                controls-position="right"
              />
              <span>days before access expires</span>
            </div>
            <p class="notification-timing-preview" role="status">
              Members receive this email {{ form.email_expiring_days_before }} {{ form.email_expiring_days_before === 1 ? 'day' : 'days' }} before access expires.
            </p>
          </div>
        </Transition>
      </article>

      <article class="notification-preference" role="group" aria-labelledby="notification-access-revoked">
        <div class="notification-preference-header">
          <div class="notification-preference-copy">
            <h3 id="notification-access-revoked">Access revoked</h3>
            <p>{{ form.email_access_revoked ? 'Members receive an email when their access is revoked.' : 'No email is sent when access is revoked.' }}</p>
          </div>
          <el-switch v-model="form.email_access_revoked" aria-label="Email on access revoked" />
        </div>
      </article>

      <article class="notification-preference" role="group" aria-labelledby="notification-drip-unlocked">
        <div class="notification-preference-header">
          <div class="notification-preference-copy">
            <h3 id="notification-drip-unlocked">Drip content unlocked</h3>
            <p>{{ form.email_drip_unlocked ? 'Members receive an email when new drip content becomes available.' : 'No email is sent when drip content unlocks.' }}</p>
          </div>
          <el-switch v-model="form.email_drip_unlocked" aria-label="Email on drip content unlocked" />
        </div>
      </article>
    </div>
  </div>
</template>

<script setup>
defineProps({
  form: { type: Object, required: true },
})
</script>

<style scoped>
.notification-settings { padding-bottom: 26px; }

.notification-preferences {
  display: grid;
  gap: 12px;
}

.notification-preference {
  overflow: hidden;
  border: 1px solid var(--fchub-border-color);
  border-radius: 12px;
  background: var(--fchub-card-bg);
  transition: border-color .18s ease, box-shadow .18s ease;
}

.notification-preference:focus-within {
  border-color: color-mix(in srgb, var(--el-color-primary) 38%, var(--fchub-border-color));
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--el-color-primary) 7%, transparent);
}

.notification-preference-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 24px;
  min-height: 72px;
  padding: 16px 18px;
}

.notification-preference-copy {
  min-width: 0;
}

.notification-preference-copy h3 {
  margin: 0;
  color: var(--fchub-text-primary);
  font-size: 14px;
  font-weight: 650;
  line-height: 1.35;
}

.notification-preference-copy p {
  margin: 4px 0 0;
  color: var(--fchub-text-secondary);
  font-size: 12px;
  line-height: 1.5;
}

.notification-preference-header .el-switch {
  flex: 0 0 auto;
}

.notification-timing {
  display: grid;
  grid-template-columns: minmax(180px, .8fr) minmax(240px, 1.2fr);
  gap: 10px 24px;
  margin: 0 12px 12px;
  padding: 14px 16px;
  border-top: 1px solid color-mix(in srgb, var(--el-color-primary) 22%, var(--fchub-border-color));
  border-radius: 9px;
  background: color-mix(in srgb, var(--el-color-primary) 5%, var(--fchub-page-bg));
}

.notification-timing-copy label {
  display: block;
  color: var(--fchub-text-primary);
  font-size: 12px;
  font-weight: 650;
}

.notification-timing-copy p {
  margin: 4px 0 0;
  color: var(--fchub-text-secondary);
  font-size: 11px;
  line-height: 1.45;
}

.notification-timing-field {
  display: grid;
  grid-template-columns: 112px max-content;
  align-items: center;
  justify-content: end;
  gap: 10px;
  min-width: 0;
}

.notification-timing-field .el-input-number {
  width: 112px;
}

.notification-timing-field > span {
  color: var(--fchub-text-primary);
  font-size: 12px;
  line-height: 1.35;
  white-space: nowrap;
}

.notification-timing-preview {
  grid-column: 2;
  margin: 0;
  color: var(--el-color-primary);
  font-size: 11px;
  line-height: 1.4;
  text-align: right;
}

.preference-detail-enter-active,
.preference-detail-leave-active {
  transition: opacity .16s ease, transform .16s ease;
}

.preference-detail-enter-from,
.preference-detail-leave-to {
  opacity: 0;
  transform: translateY(-4px);
}

@media (max-width: 782px) {
  .notification-settings { padding-bottom: 18px; }
  .notification-preferences { gap: 10px; }
  .notification-preference-header { min-height: 66px; padding: 14px; }
  .notification-timing { grid-template-columns: 1fr; gap: 12px; margin: 0 8px 8px; padding: 13px; }
  .notification-timing-field { grid-template-columns: 1fr; justify-content: stretch; gap: 6px; }
  .notification-timing-field .el-input-number { width: 100%; }
  .notification-timing-preview { grid-column: 1; text-align: left; }
}
</style>
