<template>
  <section class="plan-management" aria-labelledby="plan-management-heading">
    <div class="plan-management-heading">
      <h2 id="plan-management-heading">{{ isNew ? 'Preview scheduled access' : 'Manage existing plan' }}</h2>
      <p>
        {{ isNew
          ? 'Check when scheduled content becomes available.'
          : 'Related tools stay available without cluttering the creation flow.' }}
      </p>
    </div>
    <el-tabs v-model="activeTab">
      <el-tab-pane v-if="dripPreviewRules.length > 0" label="Drip Preview" name="drip">
        <el-timeline>
          <el-timeline-item
            v-for="(item, index) in dripPreviewRules"
            :key="index"
            :timestamp="item.label"
            placement="top"
            :type="item.type"
          >
            <strong>{{ item.resourceLabel }}</strong>
          </el-timeline-item>
        </el-timeline>
      </el-tab-pane>
      <el-tab-pane v-if="!isNew" label="Linked Products" name="products" :lazy="true">
        <PlanLinkedProductsTab
          :loading="productsLoading"
          :products="linkedProducts"
          :error="linkedProductsError"
          @link="$emit('show-link')"
          @retry="$emit('retry-products')"
          @unlink="$emit('unlink', $event)"
        />
      </el-tab-pane>
      <el-tab-pane v-if="!isNew" label="Members" name="members" :lazy="true">
        <PlanMembersTab
          :loading="membersLoading"
          :members="members"
          :error="membersError"
          :total="membersTotal"
          :page="membersPage"
          :per-page="membersPerPage"
          :format-date="formatDate"
          :members-link="membersLink"
          @page-change="$emit('members-page-change', $event)"
          @retry="$emit('retry-members')"
        />
      </el-tab-pane>
    </el-tabs>
  </section>
</template>

<script setup>
import PlanLinkedProductsTab from '@/components/plans/PlanLinkedProductsTab.vue'
import PlanMembersTab from '@/components/plans/PlanMembersTab.vue'

defineProps({
  isNew: { type: Boolean, required: true },
  dripPreviewRules: { type: Array, required: true },
  productsLoading: { type: Boolean, required: true },
  linkedProducts: { type: Array, required: true },
  linkedProductsError: { type: String, default: '' },
  membersLoading: { type: Boolean, required: true },
  members: { type: Array, required: true },
  membersError: { type: String, default: '' },
  membersTotal: { type: Number, required: true },
  membersPage: { type: Number, required: true },
  membersPerPage: { type: Number, required: true },
  formatDate: { type: Function, required: true },
  membersLink: { type: String, required: true },
})

defineEmits([
  'show-link',
  'retry-products',
  'unlink',
  'members-page-change',
  'retry-members',
])

const activeTab = defineModel('activeTab', {
  type: String,
  required: true,
})
</script>

<style scoped>
.plan-management {
  margin: 24px 0 0 210px;
  padding: 22px;
  border: 1px solid var(--fchub-border-color);
  border-radius: 12px;
  background: var(--fchub-card-bg);
}

.plan-management-heading h2 {
  margin: 0;
  color: var(--fchub-text-primary);
  font-size: 16px;
}

.plan-management-heading p {
  margin: 4px 0 14px;
  color: var(--fchub-text-secondary);
  font-size: 12px;
}

@media (max-width: 1180px) {
  .plan-management {
    margin-left: 196px;
  }
}

@media (max-width: 960px) {
  .plan-management {
    margin-left: 186px;
  }
}

@media (max-width: 782px) {
  .plan-management {
    margin-left: 0;
    padding: 16px;
  }
}
</style>
