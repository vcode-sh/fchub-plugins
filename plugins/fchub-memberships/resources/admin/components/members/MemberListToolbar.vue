<template>
  <div>
    <WorkspacePageHeader
      eyebrow="People and access"
      title="Members"
      description="Find an access assignment, open the member profile, and make changes without losing context."
    >
      <template #actions>
        <el-dropdown @command="$emit('utility', $event)">
          <el-button aria-label="Member utilities">
            More
            <el-icon><ArrowDown /></el-icon>
          </el-button>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item command="import">
                <el-icon><Upload /></el-icon>
                Import members
              </el-dropdown-item>
              <el-dropdown-item command="export" :disabled="exporting">
                <el-icon><Download /></el-icon>
                Export current view
              </el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
        <el-button type="primary" @click="$emit('grant')">
          <el-icon><Plus /></el-icon>
          Grant Access
        </el-button>
      </template>
    </WorkspacePageHeader>

    <div class="member-filters" role="search" aria-label="Filter member access">
      <el-input
        :model-value="filters.search"
        aria-label="Search members"
        placeholder="Search name, email or user ID"
        clearable
        :prefix-icon="Search"
        class="member-filters__search"
        @update:model-value="$emit('update:search', $event)"
        @input="$emit('search-input')"
      />
      <el-select
        :model-value="filters.plan_id"
        aria-label="Membership plan"
        placeholder="All plans"
        clearable
        @update:model-value="$emit('update:planId', $event)"
        @change="$emit('filter-change')"
      >
        <el-option v-for="plan in planOptions" :key="plan.id" :label="plan.title" :value="plan.id" />
      </el-select>
      <el-select
        :model-value="filters.status"
        aria-label="Access status"
        placeholder="All statuses"
        clearable
        @update:model-value="$emit('update:status', $event)"
        @change="$emit('filter-change')"
      >
        <el-option label="Active" value="active" />
        <el-option label="Paused" value="paused" />
        <el-option label="Expired" value="expired" />
        <el-option label="Revoked" value="revoked" />
      </el-select>
      <el-select
        :model-value="filters.expires_within"
        aria-label="Expiry window"
        placeholder="Any expiry"
        clearable
        @update:model-value="$emit('update:expiresWithin', $event)"
        @change="$emit('filter-change')"
      >
        <el-option label="Expires today" :value="1" />
        <el-option label="Next 7 days" :value="7" />
        <el-option label="Next 30 days" :value="30" />
      </el-select>
      <el-button v-if="hasFilters" text class="member-filters__clear" @click="$emit('clear-filters')">
        Clear filters
      </el-button>
    </div>
    <p class="member-filters__hint">Each row is one member-plan access assignment.</p>
  </div>
</template>

<script setup>
import { ArrowDown, Download, Plus, Search, Upload } from '@element-plus/icons-vue'
import WorkspacePageHeader from '@/components/workspace/WorkspacePageHeader.vue'

defineProps({
  exporting: { type: Boolean, default: false },
  filters: { type: Object, required: true },
  planOptions: { type: Array, default: () => [] },
  hasFilters: { type: Boolean, default: false },
})

defineEmits([
  'utility',
  'grant',
  'update:search',
  'update:planId',
  'update:status',
  'update:expiresWithin',
  'search-input',
  'filter-change',
  'clear-filters',
])
</script>

<style scoped>
.member-filters {
  display: grid;
  grid-template-columns: minmax(240px, 1fr) repeat(3, minmax(135px, 170px)) auto;
  gap: 8px;
  align-items: center;
}

.member-filters__search { min-width: 0; }
.member-filters__clear { justify-self: end; }

.member-filters__hint {
  margin: 7px 0 14px;
  color: var(--fchub-text-secondary);
  font-size: 11px;
}

@media (max-width: 1050px) {
  .member-filters { grid-template-columns: minmax(220px, 1fr) repeat(2, minmax(135px, 1fr)); }
  .member-filters__search { grid-column: 1 / -1; }
  .member-filters__clear { justify-self: start; }
}

@media (max-width: 782px) {
  .member-filters { grid-template-columns: 1fr 1fr; }
  .member-filters__search { grid-column: 1 / -1; }
}

@media (max-width: 480px) {
  .member-filters { grid-template-columns: 1fr; }
  .member-filters__search { grid-column: auto; }
}
</style>
