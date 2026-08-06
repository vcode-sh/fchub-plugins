<template>
  <div class="fchub-settings-section notification-studio">
    <NotificationBrandEditor
      :draft-theme="studio.draftTheme.value"
      :editing-brand="studio.editingBrand.value"
      :global-variables="studio.globalVariables.value"
      :preview-device="studio.previewDevice.value"
      :preview-error="studio.previewError.value"
      :preview-html="studio.previewHtml.value"
      :previewing="studio.previewing.value"
      :preview-subject="studio.previewSubject.value"
      :saving-brand="studio.savingBrand.value"
      @cancel="studio.cancelBrandEditor"
      @choose-logo="studio.openMediaLibrary('logo_url')"
      @save="studio.saveBrandEditor"
      @update:device="studio.previewDevice.value = $event"
      @update:draft-theme="studio.draftTheme.value = $event"
    />
    <NotificationEmailEditor
      :available-delivery-options="studio.availableDeliveryOptions.value"
      :draft="studio.draft.value"
      :draft-theme="studio.draftTheme.value"
      :editing="studio.editing.value"
      :editing-brand="studio.editingBrand.value"
      :global-variables="studio.globalVariables.value"
      :preview-device="studio.previewDevice.value"
      :preview-error="studio.previewError.value"
      :preview-html="studio.previewHtml.value"
      :previewing="studio.previewing.value"
      :preview-subject="studio.previewSubject.value"
      :rich-variables="studio.richVariables.value"
      :saving-email="studio.savingEmail.value"
      :testing="studio.testing.value"
      :use-global-template="studio.useGlobalTemplate.value"
      @append-block="studio.appendBlock"
      @cancel="studio.cancelEditor"
      @choose-block-image="studio.openMediaLibraryForBlock"
      @choose-logo="studio.openMediaLibrary('logo_url')"
      @delete-block="studio.deleteBlock"
      @insert-variable="studio.insertFieldVariable"
      @reorder-block="studio.reorderBlock"
      @reset="studio.resetDraft"
      @save="studio.applyEditor"
      @send-test="studio.sendTest"
      @update:device="studio.previewDevice.value = $event"
      @update:draft-theme="studio.draftTheme.value = $event"
      @update:use-global-template="studio.useGlobalTemplate.value = $event"
    />
    <NotificationStudioCatalog
      :active-count="studio.activeCount.value"
      :available-delivery-options="studio.availableDeliveryOptions.value"
      :brand-template="studio.brandTemplate.value"
      :catalog-ready="studio.catalogReady.value"
      :current-delivery="studio.currentDelivery"
      :editing="studio.editing.value"
      :editing-brand="studio.editingBrand.value"
      :fluentcrm-available="studio.fluentcrmAvailable.value"
      :load-error="studio.loadError.value"
      :loading="studio.loading.value"
      :notification-groups="studio.notificationGroups.value"
      :notifications="studio.notifications.value"
      :template-for="studio.templateFor"
      @edit="studio.openEditor"
      @open-brand="studio.openBrandEditor"
      @retry="studio.loadCatalog"
      @set-delivery="studio.setDelivery"
    />
  </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { settings } from '@/api/index.js'
import { useNotificationStudio } from '@/composables/settings/useNotificationStudio.js'
import NotificationBrandEditor from './NotificationBrandEditor.vue'
import NotificationEmailEditor from './NotificationEmailEditor.vue'
import NotificationStudioCatalog from './NotificationStudioCatalog.vue'

const props = defineProps({
  form: { type: Object, required: true },
  standalone: { type: Boolean, default: false },
})

const studio = useNotificationStudio({
  form: props.form,
  standalone: props.standalone,
  api: settings,
  messages: ElMessage,
})

onMounted(studio.loadCatalog)
onBeforeUnmount(studio.dispose)
</script>

<style scoped>
.notification-studio {
  padding-bottom: 26px;
}

@media (max-width: 782px) {
  .notification-studio {
    padding-bottom: 18px;
  }
}
</style>
