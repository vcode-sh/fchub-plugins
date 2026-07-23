<template>
  <div class="fchub-settings-section">
    <div class="fchub-settings-section-title">FluentCRM Integration</div>

    <div class="fchub-setting-row">
      <div class="fchub-setting-label">
        <h4>Enable FluentCRM Sync</h4>
        <p>Automatically sync membership events (grant, revoke, pause, resume, expire) to FluentCRM tags, lists, and custom fields.</p>
      </div>
      <div class="fchub-setting-control"><el-switch v-model="form.fluentcrm_enabled" aria-label="Enable FluentCRM sync" /></div>
    </div>

    <template v-if="form.fluentcrm_enabled">
      <div class="fchub-setting-row">
        <div class="fchub-setting-label">
          <h4>Tag Prefix</h4>
          <p>Prefix for auto-created tags. Tags will be named like "member:plan-slug".</p>
        </div>
        <div class="fchub-setting-control"><el-input v-model="form.fluentcrm_tag_prefix" aria-label="FluentCRM tag prefix" placeholder="member:" /></div>
      </div>

      <div class="fchub-setting-row">
        <div class="fchub-setting-label">
          <h4>Default List</h4>
          <p>Add active members to this FluentCRM list automatically.</p>
        </div>
        <div class="fchub-setting-control">
          <el-select
            v-model="form.fluentcrm_default_list"
            aria-label="FluentCRM default list"
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
        <div class="fchub-setting-control"><el-switch v-model="form.fluentcrm_auto_create_tags" aria-label="Auto-create FluentCRM tags" /></div>
      </div>
    </template>
  </div>

  <div class="fchub-settings-section">
    <div class="fchub-settings-section-title">FluentCommunity Integration</div>

    <div class="fchub-setting-row">
      <div class="fchub-setting-label">
        <h4>Enable FluentCommunity Sync</h4>
        <p>Sync membership status to FluentCommunity spaces when grants are created, revoked, or expire.</p>
      </div>
      <div class="fchub-setting-control"><el-switch v-model="form.fc_enabled" aria-label="Enable FluentCommunity sync" /></div>
    </div>

    <template v-if="form.fc_enabled">
      <section class="community-mapping-workspace" aria-labelledby="community-mapping-title">
        <header class="community-mapping-header">
          <div>
            <span>Plan mappings</span>
            <h4 id="community-mapping-title">Connect plans to your community</h4>
            <p>Choose the space each membership plan should grant. A mapping can be left empty.</p>
          </div>
          <strong>{{ configuredMappingCount }} of {{ planOptions.length }} mappings configured</strong>
        </header>

        <div v-if="planOptionsError || spaceSearchError" class="community-mapping-error" role="alert">
          <span v-if="planOptionsError">{{ planOptionsError }}</span>
          <span v-if="spaceSearchError">{{ spaceSearchError }}</span>
          <el-button v-if="planOptionsError" size="small" plain @click="reloadPlanOptions()">Retry plans</el-button>
        </div>

        <div v-if="planOptions.length === 0 && !planOptionsError" class="community-mapping-empty">
          <strong>No membership plans yet</strong>
          <span>Create a plan first, then return here to connect it to FluentCommunity.</span>
        </div>

        <div v-if="planOptions.length > 0" class="community-mapping-grid" role="table" aria-label="FluentCommunity plan mappings">
          <div class="community-mapping-grid-head" role="row">
            <span role="columnheader">Membership plan</span>
            <span role="columnheader">Community space</span>
          </div>

          <article v-for="(plan, rowIndex) in planOptions" :key="plan.value ?? plan.id" class="community-mapping-row" role="row">
            <div class="community-plan-cell" role="rowheader">
              <div>
                <strong :id="`community-plan-${rowIndex}`">{{ plan.label }}</strong>
                <span v-if="plan.status && plan.status !== 'active'" class="community-plan-status">
                  {{ plan.status === 'invalid' ? 'Cleanup required' : (plan.status === 'inactive' ? 'Inactive' : 'Unavailable') }}
                </span>
              </div>
              <span class="community-mapping-state" :class="`is-${mappingStatus(plan.id).tone}`">
                {{ mappingStatus(plan.id).label }}
              </span>
            </div>

            <div class="community-mapping-field" role="cell">
              <label :id="`community-space-label-${rowIndex}`" :for="`community-space-${rowIndex}`">Community space</label>
              <el-select
                :id="`community-space-${rowIndex}`"
                v-model="form.fc_space_mappings[plan.id]"
                :aria-label="`${plan.label}: Community space`"
                :aria-labelledby="`community-plan-${rowIndex} community-space-label-${rowIndex}`"
                placeholder="No space selected"
                no-data-text="No spaces available"
                no-match-text="No matching spaces"
                loading-text="Loading spaces…"
                clearable
                filterable
                remote
                remote-show-suffix
                size="large"
                :debounce="300"
                :remote-method="searchFcSpaces"
                :loading="loadingSpaces"
                @visible-change="handleSpaceVisibility"
              >
                <el-option v-for="space in fcSpaces" :key="space.id" :label="space.label" :value="space.id" />
              </el-select>
              <span
                v-if="isMissingOption(form.fc_space_mappings[plan.id], fcSpaces, loadingSpaces, spaceSearchError)"
                class="community-mapping-field-warning"
              >Selected space is unavailable. Clear it or choose another.</span>
            </div>

          </article>
        </div>
      </section>
    </template>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  form: { type: Object, required: true },
  planOptions: { type: Array, default: () => [] },
  planOptionsError: { type: String, default: '' },
  loadingLists: Boolean,
  fluentcrmLists: { type: Array, default: () => [] },
  loadingSpaces: Boolean,
  fcSpaces: { type: Array, default: () => [] },
  spaceSearchError: { type: String, default: '' },
  searchFluentcrmLists: { type: Function, required: true },
  searchFcSpaces: { type: Function, required: true },
  reloadPlanOptions: { type: Function, required: true },
})

const configuredMappingCount = computed(() => Object.values(props.form.fc_space_mappings ?? {}).filter(Boolean).length)

function mappingStatus(planId) {
  return props.form.fc_space_mappings?.[planId]
    ? { label: 'Mapped', tone: 'complete' }
    : { label: 'Not mapped', tone: 'empty' }
}

function handleSpaceVisibility(visible) {
  if (visible) props.searchFcSpaces('')
}

function isMissingOption(value, options, loading, error) {
  if (!value || loading || error) return false
  return !options.some((option) => String(option.id) === String(value))
}
</script>

<style scoped>
.community-mapping-workspace { margin: 4px 0 20px; overflow: hidden; border: 1px solid var(--fchub-border-color); border-radius: 12px; background: var(--fchub-card-bg); }
.community-mapping-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 24px; padding: 18px 20px; border-bottom: 1px solid var(--fchub-border-color); background: color-mix(in srgb, var(--fchub-page-bg) 46%, var(--fchub-card-bg)); }
.community-mapping-header > div { min-width: 0; }
.community-mapping-header > div > span { display: block; margin-bottom: 4px; color: var(--el-color-primary); font-size: 10px; font-weight: 750; letter-spacing: .08em; text-transform: uppercase; }
.community-mapping-header h4 { margin: 0; color: var(--fchub-text-primary); font-size: 15px; font-weight: 700; line-height: 1.35; }
.community-mapping-header p { max-width: 650px; margin: 5px 0 0; color: var(--fchub-text-secondary); font-size: 12px; line-height: 1.5; }
.community-mapping-header > strong { flex: 0 0 auto; padding: 6px 9px; border: 1px solid var(--fchub-border-color); border-radius: 999px; color: var(--fchub-text-secondary); background: var(--fchub-card-bg); font-size: 11px; font-weight: 650; white-space: nowrap; }
.community-mapping-error { display: grid; justify-items: start; gap: 7px; padding: 10px 20px; border-bottom: 1px solid color-mix(in srgb, var(--el-color-danger) 28%, var(--fchub-border-color)); color: var(--el-color-danger); background: color-mix(in srgb, var(--el-color-danger) 7%, var(--fchub-card-bg)); font-size: 12px; }
.community-mapping-empty { display: grid; gap: 4px; padding: 24px 20px; text-align: center; }
.community-mapping-empty strong { color: var(--fchub-text-primary); font-size: 13px; }
.community-mapping-empty span { color: var(--fchub-text-secondary); font-size: 12px; }
.community-mapping-grid-head, .community-mapping-row { display: grid; grid-template-columns: minmax(170px, .85fr) minmax(180px, 1fr); gap: 16px; align-items: center; }
.community-mapping-grid-head { min-height: 38px; padding: 0 20px; border-bottom: 1px solid var(--fchub-border-color); color: var(--fchub-text-secondary); background: color-mix(in srgb, var(--fchub-page-bg) 28%, var(--fchub-card-bg)); font-size: 10px; font-weight: 700; letter-spacing: .045em; text-transform: uppercase; }
.community-mapping-row { min-height: 78px; padding: 13px 20px; border-bottom: 1px solid var(--fchub-border-color); }
.community-mapping-row:last-child { border-bottom: 0; }
.community-mapping-row:focus-within { background: color-mix(in srgb, var(--el-color-primary) 4%, var(--fchub-card-bg)); box-shadow: inset 3px 0 0 var(--el-color-primary); }
.community-plan-cell { display: grid; align-content: center; justify-items: start; gap: 7px; min-width: 0; }
.community-plan-cell > div { min-width: 0; }
.community-plan-cell strong { display: block; color: var(--fchub-text-primary); font-size: 13px; font-weight: 680; line-height: 1.35; overflow-wrap: anywhere; white-space: normal; }
.community-plan-status { display: inline-block; margin-top: 5px; padding: 2px 6px; border-radius: 999px; color: var(--fchub-text-secondary); background: var(--fchub-page-bg); font-size: 10px; font-weight: 650; }
.community-mapping-state { padding: 3px 7px; border-radius: 999px; font-size: 10px; font-weight: 650; white-space: nowrap; }
.community-mapping-state.is-complete { color: #19733f; background: color-mix(in srgb, var(--el-color-success) 14%, var(--fchub-card-bg)); }
.community-mapping-state.is-empty { color: var(--fchub-text-secondary); background: var(--fchub-page-bg); }
.community-mapping-field { min-width: 0; }
.community-mapping-field label { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); clip-path: inset(50%); white-space: nowrap; }
.community-mapping-field :deep(.el-select) { width: 100%; }
.community-mapping-field :deep(.el-select__wrapper) { min-height: 40px; }
.community-mapping-field-warning { display: block; margin-top: 5px; color: var(--el-color-danger); font-size: 10px; line-height: 1.35; }
@media (max-width: 1180px) {
  .community-mapping-grid-head { display: none; }
  .community-mapping-row { grid-template-columns: 1fr; gap: 12px; }
  .community-plan-cell { grid-column: 1 / -1; }
  .community-mapping-field label { position: static; display: block; width: auto; height: auto; margin-bottom: 6px; overflow: visible; clip: auto; clip-path: none; color: var(--fchub-text-secondary); font-size: 10px; font-weight: 700; letter-spacing: .035em; text-transform: uppercase; white-space: normal; }
}

@media (max-width: 782px) {
  .community-mapping-header { align-items: stretch; flex-direction: column; gap: 12px; padding: 16px; }
  .community-mapping-header > strong { align-self: flex-start; }
  .community-mapping-row { grid-template-columns: 1fr; min-height: 0; padding: 16px; }
  .community-plan-cell { grid-column: auto; }
}
</style>
