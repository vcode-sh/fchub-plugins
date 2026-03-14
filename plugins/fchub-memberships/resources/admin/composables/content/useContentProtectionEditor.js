import { ref } from 'vue'
import { ElMessage } from 'element-plus'

export function useContentProtectionEditor({ contentApi, fetchContent }) {
  const editDrawerVisible = ref(false)
  const editSaving = ref(false)
  const editForm = ref(null)

  function openEditDrawer(row) {
    editForm.value = {
      id: row.id,
      resource_title: row.resource_title,
      resource_type: row.resource_type,
      resource_type_label: row.resource_type_label,
      resource_type_group: row.resource_type_group,
      plan_ids: [...(row.plan_ids || [])],
      show_teaser: row.show_teaser || 'no',
      restriction_message: row.restriction_message || '',
      redirect_url: row.redirect_url || '',
    }
    editDrawerVisible.value = true
  }

  async function saveEdit() {
    if (!editForm.value) return
    editSaving.value = true
    try {
      await contentApi.update(editForm.value.id, {
        plan_ids: editForm.value.plan_ids,
        show_teaser: editForm.value.show_teaser,
        restriction_message: editForm.value.restriction_message,
        redirect_url: editForm.value.redirect_url,
      })
      ElMessage.success('Protection rule updated')
      editDrawerVisible.value = false
      await fetchContent()
    } catch (err) {
      ElMessage.error(err.message || 'Failed to update rule')
    } finally {
      editSaving.value = false
    }
  }

  return {
    editDrawerVisible,
    editSaving,
    editForm,
    openEditDrawer,
    saveEdit,
  }
}
