<template>
  <div class="fchub-settings-section">
    <div class="fchub-settings-section-title">FluentCRM Integration</div>

    <div class="fchub-setting-row">
      <div class="fchub-setting-label">
        <h4>Enable FluentCRM Sync</h4>
        <p>Automatically sync membership events (grant, revoke, pause, resume, expire) to FluentCRM tags, lists, and custom fields.</p>
      </div>
      <div class="fchub-setting-control"><el-switch v-model="form.fluentcrm_enabled" /></div>
    </div>

    <template v-if="form.fluentcrm_enabled">
      <div class="fchub-setting-row">
        <div class="fchub-setting-label">
          <h4>Tag Prefix</h4>
          <p>Prefix for auto-created tags. Tags will be named like "member:plan-slug".</p>
        </div>
        <div class="fchub-setting-control"><el-input v-model="form.fluentcrm_tag_prefix" placeholder="member:" /></div>
      </div>

      <div class="fchub-setting-row">
        <div class="fchub-setting-label">
          <h4>Default List</h4>
          <p>Add active members to this FluentCRM list automatically.</p>
        </div>
        <div class="fchub-setting-control">
          <el-select
            v-model="form.fluentcrm_default_list"
            style="width: 100%"
            placeholder="Select a list..."
            clearable
            filterable
            remote
            :remote-method="searchFluentcrmLists"
            :loading="loadingLists"
          >
            <el-option v-for="list in fluentcrmLists" :key="list.id" :label="list.label" :value="list.id" />
          </el-select>
        </div>
      </div>

      <div class="fchub-setting-row">
        <div class="fchub-setting-label">
          <h4>Auto-Create Tags</h4>
          <p>Automatically create FluentCRM tags from plan names when they don't exist.</p>
        </div>
        <div class="fchub-setting-control"><el-switch v-model="form.fluentcrm_auto_create_tags" /></div>
      </div>
    </template>
  </div>

  <div class="fchub-settings-section">
    <div class="fchub-settings-section-title">FluentCommunity Integration</div>

    <div class="fchub-setting-row">
      <div class="fchub-setting-label">
        <h4>Enable FluentCommunity Sync</h4>
        <p>Sync membership status to FluentCommunity spaces and badges when grants are created, revoked, or expire.</p>
      </div>
      <div class="fchub-setting-control"><el-switch v-model="form.fc_enabled" /></div>
    </div>

    <template v-if="form.fc_enabled">
      <div class="fchub-setting-row">
        <div class="fchub-setting-label">
          <h4>Plan to Space Mapping</h4>
          <p>Map membership plans to FluentCommunity spaces. Members will be added to the mapped space when granted access.</p>
        </div>
        <div class="fchub-setting-control">
          <div v-if="planOptions.length === 0" class="fchub-empty-note">No membership plans found. Create a plan first.</div>
          <div v-for="plan in planOptions" :key="'space-' + plan.id" class="mapping-row">
            <span class="mapping-plan-name">{{ plan.title }}</span>
            <el-select
              v-model="form.fc_space_mappings[plan.id]"
              style="width: 220px"
              placeholder="Select space..."
              clearable
              filterable
              remote
              :remote-method="searchFcSpaces"
              :loading="loadingSpaces"
            >
              <el-option v-for="space in fcSpaces" :key="space.id" :label="space.label" :value="space.id" />
            </el-select>
          </div>
        </div>
      </div>

      <div class="fchub-setting-row">
        <div class="fchub-setting-label">
          <h4>Plan to Badge Mapping</h4>
          <p>Assign a FluentCommunity badge when a member is granted a plan.</p>
        </div>
        <div class="fchub-setting-control">
          <div v-if="planOptions.length === 0" class="fchub-empty-note">No membership plans found. Create a plan first.</div>
          <div v-for="plan in planOptions" :key="'badge-' + plan.id" class="mapping-row">
            <span class="mapping-plan-name">{{ plan.title }}</span>
            <el-select
              v-model="form.fc_badge_mappings[plan.id]"
              style="width: 220px"
              placeholder="Select badge..."
              clearable
              filterable
              remote
              :remote-method="searchFcBadges"
              :loading="loadingBadges"
            >
              <el-option v-for="badge in fcBadges" :key="badge.id" :label="badge.label" :value="badge.id" />
            </el-select>
          </div>
        </div>
      </div>

      <div class="fchub-setting-row">
        <div class="fchub-setting-label">
          <h4>Remove Badge on Revoke</h4>
          <p>Remove the assigned badge when a member's access is revoked or expires.</p>
        </div>
        <div class="fchub-setting-control"><el-switch v-model="form.fc_remove_badge_on_revoke" /></div>
      </div>
    </template>
  </div>
</template>

<script setup>
defineProps({
  form: { type: Object, required: true },
  planOptions: { type: Array, default: () => [] },
  loadingLists: Boolean,
  fluentcrmLists: { type: Array, default: () => [] },
  loadingSpaces: Boolean,
  fcSpaces: { type: Array, default: () => [] },
  loadingBadges: Boolean,
  fcBadges: { type: Array, default: () => [] },
  searchFluentcrmLists: { type: Function, required: true },
  searchFcSpaces: { type: Function, required: true },
  searchFcBadges: { type: Function, required: true },
})
</script>
