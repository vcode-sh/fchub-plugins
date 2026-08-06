<template>
  <div v-loading="loading">
    <div class="tab-header-row">
      <p class="tab-description">Members who have access through this plan.</p>
      <router-link :to="membersLink">
        <el-button size="small" text type="primary">View All →</el-button>
      </router-link>
    </div>
    <div v-if="error" class="load-error">
      <el-alert
        title="Plan members could not be loaded."
        :description="error"
        type="error"
        :closable="false"
        show-icon
      />
      <el-button aria-label="Retry plan members" @click="$emit('retry')">Retry</el-button>
    </div>
    <el-table v-else-if="members.length > 0" :data="members" stripe>
      <el-table-column prop="user_email" label="Member" min-width="200" />
      <el-table-column prop="status" label="Status" width="100">
        <template #default="{ row }">
          <el-tag :type="row.status === 'active' ? 'success' : row.status === 'expired' ? 'info' : 'danger'" size="small">
            {{ row.status }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column label="Granted" width="160">
        <template #default="{ row }">
          {{ formatDate(row.created_at) }}
        </template>
      </el-table-column>
      <el-table-column prop="expires_at" label="Expires" width="160">
        <template #default="{ row }">
          {{ row.expires_at ? formatDate(row.expires_at) : 'Never' }}
        </template>
      </el-table-column>
    </el-table>
    <el-empty v-else-if="!loading" description="No members have been granted this plan yet." :image-size="60" />
    <el-pagination
      v-if="!error && total > perPage"
      :current-page="page"
      :page-size="perPage"
      :total="total"
      layout="prev, pager, next"
      style="margin-top: 16px; justify-content: flex-end"
      @current-change="$emit('page-change', $event)"
    />
  </div>
</template>

<script setup>
defineProps({
  loading: {
    type: Boolean,
    default: false,
  },
  members: {
    type: Array,
    default: () => [],
  },
  error: {
    type: String,
    default: '',
  },
  total: {
    type: Number,
    default: 0,
  },
  page: {
    type: Number,
    default: 1,
  },
  perPage: {
    type: Number,
    default: 10,
  },
  formatDate: {
    type: Function,
    required: true,
  },
  membersLink: {
    type: String,
    required: true,
  },
})

defineEmits(['page-change', 'retry'])
</script>

<style scoped>
.tab-description {
  font-size: 13px;
  color: var(--fchub-text-secondary);
  margin: 0 0 16px 0;
}

.tab-header-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
}

.tab-header-row .tab-description {
  margin-bottom: 0;
}

.load-error {
  display: grid;
  justify-items: start;
  gap: 12px;
}
</style>
