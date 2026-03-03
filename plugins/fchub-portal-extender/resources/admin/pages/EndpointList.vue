<template>
  <div>
    <div class="page-header">
      <div>
        <h1 class="page-title">Portal Endpoints</h1>
        <p class="page-subtitle">Manage custom pages in your FluentCart Customer Portal</p>
      </div>
      <router-link to="/endpoints/new">
        <el-button type="primary" :icon="Plus">Add Endpoint</el-button>
      </router-link>
    </div>

    <div v-if="loading" class="loading-state">
      <el-skeleton :rows="5" animated />
    </div>

    <div v-else-if="endpointList.length === 0" class="empty-state">
      <el-icon :size="48" color="#c0c4cc"><Grid /></el-icon>
      <h3>No endpoints yet</h3>
      <p>Create your first custom portal endpoint to add new pages to the Customer Portal sidebar.</p>
      <router-link to="/endpoints/new">
        <el-button type="primary" :icon="Plus">Add Endpoint</el-button>
      </router-link>
    </div>

    <div v-else class="endpoint-table-card">
      <el-table :data="endpointList" style="width: 100%">
        <el-table-column label="Status" width="80" align="center">
          <template #default="{ row }">
            <el-switch
              :model-value="row.status === 'active'"
              @change="(val) => toggleStatus(row, val)"
              size="small"
            />
          </template>
        </el-table-column>

        <el-table-column label="Title" min-width="200">
          <template #default="{ row }">
            <router-link
              :to="`/endpoints/${row.id}/edit`"
              class="endpoint-title-link"
            >
              {{ row.title }}
            </router-link>
          </template>
        </el-table-column>

        <el-table-column label="Slug" min-width="160">
          <template #default="{ row }">
            <code class="slug-badge">/{{ row.slug }}</code>
          </template>
        </el-table-column>

        <el-table-column label="Type" width="120">
          <template #default="{ row }">
            <el-tag :type="typeTag(row.type).type" size="small">
              {{ typeTag(row.type).label }}
            </el-tag>
          </template>
        </el-table-column>

        <el-table-column label="Icon" width="70" align="center">
          <template #default="{ row }">
            <span v-if="row.icon_value" class="icon-preview" v-html="getIconPreview(row)" />
            <span v-else class="icon-preview-empty">—</span>
          </template>
        </el-table-column>

        <el-table-column label="Order" width="110" align="center">
          <template #default="{ row, $index }">
            <div class="order-buttons">
              <el-button
                text
                size="small"
                :icon="Top"
                :disabled="$index === 0"
                @click="moveUp($index)"
              />
              <el-button
                text
                size="small"
                :icon="Bottom"
                :disabled="$index === endpointList.length - 1"
                @click="moveDown($index)"
              />
            </div>
          </template>
        </el-table-column>

        <el-table-column label="" width="60" align="center">
          <template #default="{ row }">
            <el-dropdown trigger="click" @command="(cmd) => handleAction(cmd, row)">
              <el-button text size="small" :icon="MoreFilled" />
              <template #dropdown>
                <el-dropdown-menu>
                  <el-dropdown-item command="edit" :icon="Edit">Edit</el-dropdown-item>
                  <el-dropdown-item command="delete" :icon="Delete" divided>Delete</el-dropdown-item>
                </el-dropdown-menu>
              </template>
            </el-dropdown>
          </template>
        </el-table-column>
      </el-table>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Top, Bottom, MoreFilled, Edit, Delete, Grid } from '@element-plus/icons-vue'
import { endpoints as endpointsApi } from '@/api/index.js'

const router = useRouter()
const endpointList = ref([])
const loading = ref(true)

async function fetchEndpoints() {
  loading.value = true
  try {
    const res = await endpointsApi.list()
    endpointList.value = res.endpoints || []
  } catch (e) {
    ElMessage.error(e.message || 'Failed to load endpoints')
  } finally {
    loading.value = false
  }
}

async function toggleStatus(row, active) {
  try {
    await endpointsApi.update(row.id, { status: active ? 'active' : 'inactive' })
    row.status = active ? 'active' : 'inactive'
    ElMessage.success(active ? 'Endpoint activated' : 'Endpoint deactivated')
  } catch (e) {
    ElMessage.error(e.message || 'Failed to update status')
  }
}

async function moveUp(index) {
  if (index <= 0) return
  const list = [...endpointList.value]
  ;[list[index - 1], list[index]] = [list[index], list[index - 1]]
  endpointList.value = list
  await saveOrder()
}

async function moveDown(index) {
  if (index >= endpointList.value.length - 1) return
  const list = [...endpointList.value]
  ;[list[index], list[index + 1]] = [list[index + 1], list[index]]
  endpointList.value = list
  await saveOrder()
}

async function saveOrder() {
  try {
    const ids = endpointList.value.map(ep => ep.id)
    await endpointsApi.reorder(ids)
  } catch (e) {
    ElMessage.error(e.message || 'Failed to save order')
    await fetchEndpoints()
  }
}

function handleAction(command, row) {
  if (command === 'edit') {
    router.push(`/endpoints/${row.id}/edit`)
  } else if (command === 'delete') {
    confirmDelete(row)
  }
}

async function confirmDelete(row) {
  try {
    await ElMessageBox.confirm(
      `Delete "${row.title}"? This will remove it from the Customer Portal.`,
      'Delete Endpoint',
      { confirmButtonText: 'Delete', cancelButtonText: 'Cancel', type: 'warning' }
    )
    await endpointsApi.remove(row.id)
    endpointList.value = endpointList.value.filter(ep => ep.id !== row.id)
    ElMessage.success('Endpoint deleted')
  } catch (e) {
    if (e !== 'cancel') {
      ElMessage.error(e.message || 'Failed to delete endpoint')
    }
  }
}

function typeTag(type) {
  const map = {
    page_id: { label: 'Page', type: '' },
    shortcode: { label: 'Shortcode', type: 'warning' },
    html: { label: 'HTML', type: 'success' },
    iframe: { label: 'Iframe', type: 'info' },
    redirect: { label: 'Redirect', type: 'danger' },
    custom_post: { label: 'Post', type: '' },
  }
  return map[type] || { label: type, type: 'info' }
}

function getIconPreview(row) {
  if (!row.icon_value) return ''
  if (row.icon_type === 'svg') return row.icon_value
  if (row.icon_type === 'dashicon') return `<span class="dashicons ${row.icon_value}" style="font-size:18px;width:18px;height:18px;"></span>`
  if (row.icon_type === 'url') return `<img src="${row.icon_value}" style="width:18px;height:18px;object-fit:contain;" alt="" />`
  return ''
}

onMounted(fetchEndpoints)
</script>

<style scoped>
.page-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 24px;
}

.page-title {
  font-size: 20px;
  font-weight: 700;
  color: var(--fchub-text-primary);
  margin: 0 0 4px 0;
}

.page-subtitle {
  font-size: 13px;
  color: var(--fchub-text-secondary);
  margin: 0;
}

.endpoint-table-card {
  background: var(--fchub-card-bg);
  border-radius: var(--fchub-radius-card);
  overflow: hidden;
}

.endpoint-title-link {
  color: var(--fchub-text-primary);
  font-weight: 500;
  text-decoration: none;
}

.endpoint-title-link:hover {
  color: var(--el-color-primary);
}

.slug-badge {
  font-size: 12px;
  padding: 2px 8px;
  background: var(--fchub-page-bg);
  border-radius: 4px;
  color: var(--fchub-text-secondary);
}

body.dark .slug-badge {
  background: #2a2e37;
}

.icon-preview {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: var(--fchub-text-secondary);
}

.icon-preview :deep(svg) {
  width: 18px;
  height: 18px;
}

.icon-preview-empty {
  color: var(--fchub-text-secondary);
}

.order-buttons {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0;
}

.empty-state {
  text-align: center;
  padding: 80px 24px;
  background: var(--fchub-card-bg);
  border-radius: var(--fchub-radius-card);
}

.empty-state h3 {
  font-size: 16px;
  font-weight: 600;
  color: var(--fchub-text-primary);
  margin: 16px 0 8px;
}

.empty-state p {
  font-size: 14px;
  color: var(--fchub-text-secondary);
  margin: 0 0 20px;
  max-width: 400px;
  margin-left: auto;
  margin-right: auto;
}

.loading-state {
  background: var(--fchub-card-bg);
  border-radius: var(--fchub-radius-card);
  padding: 24px;
}
</style>
