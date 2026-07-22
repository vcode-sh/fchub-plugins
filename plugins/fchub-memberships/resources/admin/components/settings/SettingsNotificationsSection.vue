<template>
  <div class="fchub-settings-section notification-studio">
    <template v-if="!standalone">
      <div class="notification-studio-summary">
        <div class="notification-studio-summary-icon"><el-icon><Message /></el-icon></div>
        <div>
          <div class="fchub-settings-section-title">Email Studio</div>
          <p>Design all eight membership emails, manage the shared brand template, preview the exact output, and send tests.</p>
        </div>
        <el-button type="primary" @click="router.push('/settings/email-studio')">
          Open Email Studio
          <el-icon><ArrowRight /></el-icon>
        </el-button>
      </div>
      <div class="notification-studio-summary-grid">
        <article><el-icon><Brush /></el-icon><div><strong>One brand template</strong><span>Header, footer, colours, spacing, type, and canvas shared by default.</span></div></article>
        <article><el-icon><EditPen /></el-icon><div><strong>Per-email control</strong><span>Override the shared template only when a message genuinely needs it.</span></div></article>
        <article><el-icon><View /></el-icon><div><strong>Delivery preview</strong><span>Server-rendered desktop and mobile previews plus test delivery.</span></div></article>
      </div>
    </template>

    <template v-else>
    <template v-if="editingBrand">
      <div class="notification-editor-header">
        <button type="button" class="notification-editor-back" @click="cancelBrandEditor">
          <el-icon><ArrowLeft /></el-icon>
          All notifications
        </button>
        <div class="notification-editor-title">
          <span>Shared design</span>
          <h2>Brand template</h2>
        </div>
        <div class="notification-editor-actions">
          <el-button :loading="savingBrand" type="primary" @click="saveBrandEditor"><el-icon><Check /></el-icon>Save template</el-button>
        </div>
      </div>

      <div class="notification-editor-workspace notification-editor-workspace--brand">
        <EmailBrandTemplateEditor
          v-model="draftTheme"
          class="notification-composer"
          :variables="globalVariables"
          aria-label="Global email brand template"
          @choose-logo="openMediaLibrary('logo_url')"
        />

        <EmailPreviewPanel
          :html="previewHtml"
          :subject="previewSubject"
          :error="previewError"
          :loading="previewing"
          :device="previewDevice"
          hide-test
          @update:device="previewDevice = $event"
        />
      </div>
    </template>

    <template v-else-if="!editing">
      <div class="notification-studio-intro">
        <div>
          <div class="fchub-settings-section-title">Member email studio</div>
          <p>Control every transactional message, its delivery owner, content, and brand styling.</p>
        </div>
        <el-tag v-if="catalogReady" effect="plain" round>{{ activeCount }} of {{ notifications.length }} active</el-tag>
      </div>

      <el-alert
        v-if="loadError"
        type="error"
        :title="loadError"
        show-icon
        :closable="false"
      >
        <template #default>
          <el-button size="small" @click="loadCatalog">Try again</el-button>
        </template>
      </el-alert>

      <el-skeleton v-else-if="loading" :rows="6" animated />

      <template v-else>
        <div v-if="fluentcrmAvailable" class="notification-advanced-option">
          <el-icon><Connection /></el-icon>
          <div>
            <strong>Advanced automation is available</strong>
            <span>FluentCRM can own selected events when you need sequences or conditions. Built-in email remains the default.</span>
          </div>
        </div>

        <button type="button" class="brand-template-card" @click="openBrandEditor">
          <span class="brand-template-card-icon"><el-icon><Brush /></el-icon></span>
          <span><strong>Global brand template</strong><small>Header, footer, canvas, typography, spacing, and colour defaults.</small></span>
          <span class="brand-template-swatches"><i :style="{ background: brandTemplate.header_background }" /><i :style="{ background: brandTemplate.primary_color }" /><i :style="{ background: brandTemplate.background_color }" /></span>
          <el-icon><ArrowRight /></el-icon>
        </button>

        <section v-for="group in notificationGroups" :key="group.key" class="notification-group">
          <header>
            <h3>{{ group.label }}</h3>
            <span>{{ group.items.length }} {{ group.items.length === 1 ? 'message' : 'messages' }}</span>
          </header>
          <div class="notification-card-grid">
            <article v-for="notification in group.items" :key="notification.key" class="notification-card">
              <div class="notification-card-main">
                <div class="notification-card-icon"><el-icon><Message /></el-icon></div>
                <div class="notification-card-copy">
                  <div class="notification-card-title-row">
                    <h4>{{ notification.label }}</h4>
                    <span class="delivery-dot" :class="`is-${currentDelivery(notification)}`" />
                  </div>
                  <p>{{ notification.description }}</p>
                  <strong class="notification-subject-preview">{{ templateFor(notification).subject }}</strong>
                </div>
              </div>
              <div class="notification-card-actions">
                <el-select
                  :model-value="currentDelivery(notification)"
                  size="small"
                  :aria-label="`${notification.label} delivery`"
                  @change="setDelivery(notification, $event)"
                >
                  <el-option v-for="option in availableDeliveryOptions" :key="option.value" :label="option.label" :value="option.value" />
                </el-select>
                <el-tooltip content="Edit email" placement="top">
                  <el-button size="small" circle :aria-label="`Edit ${notification.label}`" @click="openEditor(notification)"><el-icon><EditPen /></el-icon></el-button>
                </el-tooltip>
              </div>
            </article>
          </div>
        </section>
      </template>
    </template>

    <template v-else>
      <div class="notification-editor-header">
        <button type="button" class="notification-editor-back" @click="cancelEditor">
          <el-icon><ArrowLeft /></el-icon>
          All notifications
        </button>
        <div class="notification-editor-title">
          <span>{{ editing.groupLabel }}</span>
          <h2>{{ editing.label }}</h2>
        </div>
        <div class="notification-editor-actions">
          <el-select v-model="draft.delivery" size="small" aria-label="Delivery owner">
            <el-option v-for="option in availableDeliveryOptions" :key="option.value" :label="option.label" :value="option.value" />
          </el-select>
          <el-tooltip content="Restore default message" placement="bottom"><el-button circle aria-label="Restore default message" @click="resetDraft"><el-icon><RefreshLeft /></el-icon></el-button></el-tooltip>
          <el-button type="primary" :loading="savingEmail" @click="applyEditor"><el-icon><Check /></el-icon>Save email</el-button>
        </div>
      </div>

      <div class="notification-envelope-fields">
        <label>
          <span>Subject</span>
          <div class="notification-field-with-action">
            <el-input v-model="draft.template.subject" maxlength="160" show-word-limit />
            <VariableMenu :variables="editing.variables" @select="insertFieldVariable('subject', $event)" />
          </div>
        </label>
        <label>
          <span>Pre-header</span>
          <div class="notification-field-with-action">
            <el-input v-model="draft.template.preheader" maxlength="180" show-word-limit placeholder="Inbox preview text" />
            <VariableMenu :variables="editing.variables" @select="insertFieldVariable('preheader', $event)" />
          </div>
        </label>
      </div>

      <el-alert
        v-if="draft.delivery === 'fluentcrm'"
        class="notification-delivery-note"
        type="info"
        title="FluentCRM owns this delivery"
        description="FCHub will not send its built-in message for this event. Keep this draft as a fallback and make sure a FluentCRM automation is active."
        show-icon
        :closable="false"
      />

      <div class="template-inheritance-card" :class="{ 'is-overridden': !useGlobalTemplate }">
        <div class="template-inheritance-icon"><el-icon><Brush /></el-icon></div>
        <div><strong>{{ useGlobalTemplate ? 'Using global brand template' : 'Custom brand override' }}</strong><span>{{ useGlobalTemplate ? 'Header, footer, colours, and spacing stay in sync with the shared template.' : 'Only this email uses the custom shell below.' }}</span></div>
        <el-switch v-model="useGlobalTemplate" inline-prompt active-text="Global" inactive-text="Custom" />
      </div>
      <el-alert
        v-if="draft.delivery === 'off'"
        class="notification-delivery-note"
        type="warning"
        title="Delivery is turned off"
        description="The design stays saved, but members will not receive this notification until delivery is enabled again."
        show-icon
        :closable="false"
      />

      <div class="notification-editor-workspace">
        <section class="notification-composer" aria-label="Email content editor">
          <div class="notification-block-palette" aria-label="Add content block">
            <span>Add block</span>
            <el-tooltip v-for="blockType in blockTypes" :key="blockType.type" :content="`Add ${blockType.label.toLowerCase()}`" placement="top">
              <el-button circle size="small" :aria-label="`Add ${blockType.label.toLowerCase()}`" @click="appendBlock(blockType.type)"><el-icon><component :is="blockType.icon" /></el-icon></el-button>
            </el-tooltip>
          </div>

          <div class="notification-block-list">
            <article v-for="(block, index) in draft.template.blocks" :key="block.id" class="notification-block">
              <header class="notification-block-header">
                <span>{{ blockLabel(block.type) }}</span>
                <div role="group" :aria-label="`${blockLabel(block.type)} block actions`">
                  <el-button text size="small" :disabled="index === 0" aria-label="Move block up" @click="reorderBlock(index, -1)"><el-icon><Top /></el-icon></el-button>
                  <el-button text size="small" :disabled="index === draft.template.blocks.length - 1" aria-label="Move block down" @click="reorderBlock(index, 1)"><el-icon><Bottom /></el-icon></el-button>
                  <el-button text size="small" type="danger" :disabled="draft.template.blocks.length === 1" aria-label="Delete block" @click="deleteBlock(index)"><el-icon><Delete /></el-icon></el-button>
                </div>
              </header>

              <EmailRichTextEditor
                v-if="block.type === 'rich_text'"
                v-model="block.content"
                :variables="editing.variables"
              />

              <div v-else-if="block.type === 'heading'" class="block-field-grid block-field-grid--heading">
                <el-input v-model="block.content" placeholder="Heading" />
                <el-select v-model="block.align" aria-label="Heading alignment">
                  <el-option label="Left" value="left" /><el-option label="Centre" value="center" /><el-option label="Right" value="right" />
                </el-select>
              </div>

              <div v-else-if="block.type === 'button'" class="block-field-grid">
                <label><span>Button label</span><el-input v-model="block.label" /></label>
                <label><span>Destination</span><el-input v-model="block.url" placeholder="https:// or {account_url}" /></label>
                <label><span>Alignment</span><el-select v-model="block.align"><el-option label="Left" value="left" /><el-option label="Centre" value="center" /><el-option label="Right" value="right" /></el-select></label>
              </div>

              <div v-else-if="block.type === 'image'" class="block-field-grid">
                <label><span>Image</span><div class="media-field"><el-input v-model="block.url" placeholder="Choose from Media Library or paste a URL" /><el-tooltip content="Choose from Media Library" placement="top"><el-button circle aria-label="Choose image from Media Library" @click="openMediaLibraryForBlock(block)"><el-icon><Picture /></el-icon></el-button></el-tooltip></div></label>
                <label><span>Alternative text</span><el-input v-model="block.alt" placeholder="Describe the image" /></label>
                <label><span>Optional link</span><el-input v-model="block.link_url" placeholder="https://" /></label>
              </div>

              <div v-else-if="block.type === 'dynamic'" class="dynamic-block-field">
                <el-icon><DataAnalysis /></el-icon>
                <div><strong>Membership content</strong><span>Rendered from the selected member at send time.</span></div>
                <el-select v-model="block.variable" filterable placeholder="Choose dynamic content">
                  <el-option v-for="variable in richVariables" :key="variable.value" :label="variable.label" :value="variable.value" />
                </el-select>
              </div>

              <div v-else-if="block.type === 'divider'" class="divider-block-preview"><span /></div>

              <div v-else-if="block.type === 'spacer'" class="spacer-block-field">
                <span>Vertical space</span>
                <el-slider v-model="block.height" :min="8" :max="80" :step="4" show-input />
              </div>
            </article>
          </div>

          <el-collapse v-if="!useGlobalTemplate" class="notification-brand-panel">
            <el-collapse-item name="brand">
              <template #title>
                <span class="brand-panel-title"><el-icon><Brush /></el-icon>Brand override <small>Header, footer, canvas, and typography for this email only</small></span>
              </template>
              <EmailBrandTemplateEditor
                v-model="draftTheme"
                compact
                :variables="globalVariables"
                title="Custom shell for this email"
                description="These settings override the shared template only for this notification. Switch back to Global at any time to resume inheritance."
                aria-label="Per-email brand template override"
                @choose-logo="openMediaLibrary('logo_url')"
              />
            </el-collapse-item>
          </el-collapse>
        </section>

        <EmailPreviewPanel
          :html="previewHtml"
          :subject="previewSubject || draft.template.subject"
          :error="previewError"
          :loading="previewing"
          :testing="testing"
          :device="previewDevice"
          @update:device="previewDevice = $event"
          @send-test="sendTest"
        />
      </div>
    </template>
    </template>
  </div>
</template>

<script setup>
import { computed, defineComponent, h, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ElButton, ElDropdown, ElDropdownItem, ElDropdownMenu, ElMessage } from 'element-plus'
import {
  ArrowLeft,
  ArrowRight,
  Bottom,
  Brush,
  Check,
  Connection,
  DataAnalysis,
  Delete,
  EditPen,
  Message,
  Minus,
  Picture,
  Plus,
  Promotion,
  RefreshLeft,
  Top,
  View,
} from '@element-plus/icons-vue'
import { settings } from '@/api/index.js'
import EmailPreviewPanel from './EmailPreviewPanel.vue'
import EmailBrandTemplateEditor from './EmailBrandTemplateEditor.vue'
import EmailRichTextEditor from './EmailRichTextEditor.vue'
import {
  addBlock,
  deliveryOptions,
  groupNotifications,
  moveBlock,
  newBlock,
} from './notificationStudioUi.js'

const props = defineProps({
  form: { type: Object, required: true },
  standalone: { type: Boolean, default: false },
})
const router = useRouter()

const VariableMenu = defineComponent({
  props: { variables: { type: Object, default: () => ({}) } },
  emits: ['select'],
  setup(menuProps, { emit }) {
    return () => h(ElDropdown, { trigger: 'click', maxHeight: 320, onCommand: (value) => emit('select', value) }, {
      default: () => h(ElButton, { size: 'small' }, { default: () => [h(Plus), ' Variable'] }),
      dropdown: () => h(ElDropdownMenu, null, {
        default: () => Object.entries(menuProps.variables).map(([value, config]) => h(ElDropdownItem, { command: value }, {
          default: () => h('span', { class: 'field-variable-option' }, [h('strong', config.label), h('code', value)]),
        })),
      }),
    })
  },
})

const loading = ref(false)
const loadError = ref('')
const notifications = ref([])
const fluentcrmAvailable = ref(false)
const catalogReady = ref(false)
const catalogTheme = ref(null)
const brandTemplate = ref({})
const editing = ref(null)
const editingBrand = ref(false)
const draft = ref(null)
const draftTheme = ref(null)
const useGlobalTemplate = ref(true)
const previewHtml = ref('')
const previewSubject = ref('')
const previewError = ref('')
const previewing = ref(false)
const previewDevice = ref('desktop')
const testing = ref(false)
const savingBrand = ref(false)
const savingEmail = ref(false)
let previewTimer = null

const blockTypes = [
  { type: 'rich_text', label: 'Text', icon: EditPen },
  { type: 'heading', label: 'Heading', icon: Message },
  { type: 'button', label: 'Button', icon: Promotion },
  { type: 'image', label: 'Image', icon: Picture },
  { type: 'divider', label: 'Divider', icon: Minus },
  { type: 'spacer', label: 'Space', icon: Bottom },
  { type: 'dynamic', label: 'Member data', icon: DataAnalysis },
]

const notificationGroups = computed(() => groupNotifications(notifications.value))
const availableDeliveryOptions = computed(() => deliveryOptions(fluentcrmAvailable.value))
const activeCount = computed(() => notifications.value.filter((notification) => currentDelivery(notification) !== 'off').length)
const richVariables = computed(() => Object.entries(editing.value?.variables ?? {})
  .filter(([, config]) => config.type === 'rich')
  .map(([value, config]) => ({ value, label: config.label })))
const globalVariables = computed(() => ({
  '{site_name}': { label: 'Site name', type: 'text' },
}))

function clone(value) {
  return JSON.parse(JSON.stringify(value))
}

function currentDelivery(notification) {
  const saved = props.form.email_delivery?.[notification.key]
  if (saved === 'fluentcrm' && !fluentcrmAvailable.value) return 'built_in'
  if (saved) return saved
  return props.form[notification.setting_key] === false ? 'off' : notification.delivery
}

function templateFor(notification) {
  const saved = props.form.email_templates?.[notification.key]
  return saved && typeof saved === 'object' ? saved : notification.template
}

async function setDelivery(notification, delivery) {
  const previous = currentDelivery(notification)
  props.form.email_delivery = { ...(props.form.email_delivery ?? {}), [notification.key]: delivery }
  props.form[notification.setting_key] = delivery === 'built_in'
  if (!props.standalone) return

  try {
    await settings.saveEmail(notification.key, {
      key: notification.key,
      delivery,
      template: templateFor(notification),
      theme_override: notification.theme_override ?? null,
    })
    notification.delivery = delivery
    ElMessage.success(`${notification.label} delivery updated.`)
  } catch (error) {
    props.form.email_delivery = { ...(props.form.email_delivery ?? {}), [notification.key]: previous }
    props.form[notification.setting_key] = previous === 'built_in'
    ElMessage.error(error.message || 'Delivery could not be updated.')
  }
}

async function loadCatalog() {
  loading.value = true
  loadError.value = ''
  try {
    const response = await settings.emailNotifications()
    const data = response.data ?? response
    notifications.value = data.notifications ?? []
    catalogTheme.value = data.theme ?? null
    brandTemplate.value = clone(data.brand_template ?? data.theme ?? {})
    fluentcrmAvailable.value = Boolean(data.fluentcrm_available)
    if (props.standalone) {
      props.form.email_theme = clone(brandTemplate.value)
      props.form.email_templates = {}
      props.form.email_delivery = {}
      notifications.value.forEach((notification) => {
        props.form.email_templates[notification.key] = clone(notification.template)
        props.form.email_delivery[notification.key] = notification.delivery
        props.form[notification.setting_key] = notification.delivery === 'built_in'
      })
    }
    catalogReady.value = true
  } catch (error) {
    loadError.value = error.message || 'Email notifications could not be loaded.'
  } finally {
    loading.value = false
  }
}

function openEditor(notification) {
  const group = notificationGroups.value.find(({ key }) => key === notification.group)
  editing.value = { ...notification, groupLabel: group?.label ?? 'Notification' }
  draft.value = {
    key: notification.key,
    delivery: currentDelivery(notification),
    template: clone(templateFor(notification)),
  }
  useGlobalTemplate.value = !notification.theme_override
  draftTheme.value = clone(notification.theme_override ?? brandTemplate.value ?? catalogTheme.value ?? {})
  previewDevice.value = 'desktop'
  schedulePreview(true)
}

function cancelEditor() {
  editing.value = null
  draft.value = null
  draftTheme.value = null
  previewHtml.value = ''
  previewError.value = ''
}

async function applyEditor() {
  savingEmail.value = true
  try {
    const payload = {
      key: editing.value.key,
      delivery: draft.value.delivery,
      template: draft.value.template,
      theme_override: useGlobalTemplate.value ? null : draftTheme.value,
    }
    await settings.saveEmail(editing.value.key, payload)
    props.form.email_templates = { ...(props.form.email_templates ?? {}), [editing.value.key]: clone(draft.value.template) }
    setDeliveryLocal(editing.value, draft.value.delivery)
    const notification = notifications.value.find(({ key }) => key === editing.value.key)
    if (notification) {
      notification.template = clone(draft.value.template)
      notification.delivery = draft.value.delivery
      notification.theme_override = useGlobalTemplate.value ? null : clone(draftTheme.value)
    }
    cancelEditor()
    ElMessage.success('Email saved and ready for delivery.')
  } catch (error) {
    ElMessage.error(error.message || 'The email could not be saved.')
  } finally {
    savingEmail.value = false
  }
}

function setDeliveryLocal(notification, delivery) {
  props.form.email_delivery = { ...(props.form.email_delivery ?? {}), [notification.key]: delivery }
  props.form[notification.setting_key] = delivery === 'built_in'
}

function resetDraft() {
  draft.value.template = clone(editing.value.default_template ?? editing.value.template)
  ElMessage.info('Default content restored in this draft.')
}

function appendBlock(type) {
  draft.value.template.blocks = addBlock(draft.value.template.blocks, newBlock(type))
}

function reorderBlock(index, direction) {
  draft.value.template.blocks = moveBlock(draft.value.template.blocks, index, direction)
}

function deleteBlock(index) {
  if (draft.value.template.blocks.length === 1) return
  draft.value.template.blocks.splice(index, 1)
}

function blockLabel(type) {
  return blockTypes.find((block) => block.type === type)?.label ?? 'Content'
}

function insertFieldVariable(field, variable) {
  draft.value.template[field] = `${draft.value.template[field] ?? ''}${variable}`
}

function schedulePreview(immediate = false) {
  clearTimeout(previewTimer)
  previewTimer = setTimeout(renderPreview, immediate ? 0 : 350)
}

async function renderPreview() {
  if (!draft.value && !editingBrand.value) return
  previewing.value = true
  previewError.value = ''
  try {
    const previewNotification = editingBrand.value ? notifications.value[0] : editing.value
    const response = await settings.previewEmail({
      key: previewNotification?.key ?? 'access_granted',
      template: editingBrand.value ? previewNotification?.template : draft.value.template,
      theme: editingBrand.value ? draftTheme.value : brandTemplate.value,
      theme_override: editingBrand.value || useGlobalTemplate.value ? null : draftTheme.value,
    })
    const data = response.data ?? response
    previewHtml.value = data.html ?? ''
    previewSubject.value = data.subject ?? ''
  } catch (error) {
    previewError.value = error.message || 'The delivery preview could not be rendered.'
  } finally {
    previewing.value = false
  }
}

async function sendTest(address) {
  const testAddress = String(address ?? '').trim()
  if (!testAddress) {
    ElMessage.warning('Enter an email address for the test.')
    return
  }
  testing.value = true
  try {
    await settings.testEmail({
      key: draft.value.key,
      template: draft.value.template,
      theme: brandTemplate.value,
      theme_override: useGlobalTemplate.value ? null : draftTheme.value,
      to: testAddress,
    })
    ElMessage.success(`Test email sent to ${testAddress}.`)
  } catch (error) {
    ElMessage.error(error.message || 'The test email could not be sent.')
  } finally {
    testing.value = false
  }
}

function openBrandEditor() {
  editingBrand.value = true
  draftTheme.value = clone(brandTemplate.value)
  previewDevice.value = 'desktop'
  schedulePreview(true)
}

function cancelBrandEditor() {
  editingBrand.value = false
  draftTheme.value = null
  previewHtml.value = ''
  previewError.value = ''
}

async function saveBrandEditor() {
  savingBrand.value = true
  try {
    const response = await settings.saveEmailBrandTemplate({ theme: draftTheme.value })
    const data = response.data ?? response
    brandTemplate.value = clone(data.brand_template ?? draftTheme.value)
    props.form.email_theme = clone(brandTemplate.value)
    cancelBrandEditor()
    ElMessage.success('Global email brand template saved.')
  } catch (error) {
    ElMessage.error(error.message || 'The brand template could not be saved.')
  } finally {
    savingBrand.value = false
  }
}

function openMediaLibrary(field) {
  if (!window.wp?.media) {
    ElMessage.error('The WordPress Media Library is unavailable. Paste an image URL instead.')
    return
  }

  const frame = window.wp.media({
    title: 'Choose email image',
    button: { text: 'Use this image' },
    library: { type: 'image' },
    multiple: false,
  })
  frame.on('select', () => {
    const attachment = frame.state().get('selection').first()?.toJSON()
    if (attachment?.url) draftTheme.value[field] = attachment.url
  })
  frame.open()
}

function openMediaLibraryForBlock(block) {
  if (!window.wp?.media) {
    ElMessage.error('The WordPress Media Library is unavailable. Paste an image URL instead.')
    return
  }

  const frame = window.wp.media({
    title: 'Choose email image',
    button: { text: 'Use this image' },
    library: { type: 'image' },
    multiple: false,
  })
  frame.on('select', () => {
    const attachment = frame.state().get('selection').first()?.toJSON()
    if (attachment?.url) {
      block.url = attachment.url
      if (!block.alt && attachment.alt) block.alt = attachment.alt
    }
  })
  frame.open()
}

watch([draft, draftTheme, useGlobalTemplate], () => {
  if (editing.value || editingBrand.value) schedulePreview()
}, { deep: true })

onMounted(loadCatalog)
onBeforeUnmount(() => clearTimeout(previewTimer))
</script>

<style scoped>
.notification-studio { padding-bottom: 26px; }
.notification-studio-intro { display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; margin-bottom: 18px; }
.notification-studio-intro p { margin: 5px 0 0; color: var(--fchub-text-secondary); font-size: 12px; line-height: 1.5; }
.notification-advanced-option { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 20px; padding: 13px 15px; border: 1px solid #ddd6fe; border-radius: 10px; background: #faf8ff; color: #5b21b6; }
.notification-advanced-option > .el-icon { flex: 0 0 auto; margin-top: 2px; font-size: 18px; }
.notification-advanced-option div { display: grid; gap: 3px; }
.notification-advanced-option strong { color: #4c1d95; font-size: 12px; }
.notification-advanced-option span { color: #6d5a8f; font-size: 11px; line-height: 1.45; }
.brand-template-card { display: grid; grid-template-columns: 42px minmax(0, 1fr) auto 18px; align-items: center; gap: 12px; width: 100%; margin: 0 0 22px; padding: 14px 16px; border: 1px solid #bfd8ff; border-radius: 12px; background: linear-gradient(135deg, #f8fbff, #f2f7ff); color: var(--fchub-text-primary); text-align: left; cursor: pointer; transition: border-color .16s ease, box-shadow .16s ease, transform .16s ease; }
.brand-template-card:hover { border-color: var(--el-color-primary); box-shadow: 0 9px 24px rgba(37, 99, 235, .09); transform: translateY(-1px); }
.brand-template-card-icon { display: grid; width: 42px; height: 42px; place-items: center; border-radius: 11px; background: #fff; color: var(--el-color-primary); box-shadow: 0 3px 10px rgba(37, 99, 235, .1); font-size: 18px; }
.brand-template-card > span:nth-child(2) { display: grid; gap: 4px; }
.brand-template-card strong { font-size: 12px; }
.brand-template-card small { color: var(--fchub-text-secondary); font-size: 10.5px; line-height: 1.4; }
.brand-template-swatches { display: flex; gap: 5px; }
.brand-template-swatches i { display: block; width: 22px; height: 22px; border: 2px solid #fff; border-radius: 50%; box-shadow: 0 0 0 1px rgba(15, 23, 42, .12); }
.notification-group + .notification-group { margin-top: 22px; }
.notification-group > header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 9px; }
.notification-group > header h3 { margin: 0; color: var(--fchub-text-primary); font-size: 13px; font-weight: 700; }
.notification-group > header span { color: var(--fchub-text-tertiary); font-size: 10px; text-transform: uppercase; letter-spacing: .05em; }
.notification-card-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
.notification-card { display: flex; flex-direction: column; justify-content: space-between; min-width: 0; padding: 15px; border: 1px solid var(--fchub-border-color); border-radius: 11px; background: var(--fchub-card-bg); transition: border-color .16s ease, box-shadow .16s ease, transform .16s ease; }
.notification-card:hover { border-color: color-mix(in srgb, var(--el-color-primary) 28%, var(--fchub-border-color)); box-shadow: 0 8px 22px rgba(15, 23, 42, .055); transform: translateY(-1px); }
.notification-card-main { display: flex; align-items: flex-start; gap: 11px; min-width: 0; }
.notification-card-icon { display: grid; flex: 0 0 auto; width: 34px; height: 34px; place-items: center; border-radius: 9px; background: #eef6ff; color: var(--el-color-primary); }
.notification-card-copy { min-width: 0; }
.notification-card-title-row { display: flex; align-items: center; gap: 7px; }
.notification-card h4 { margin: 0; color: var(--fchub-text-primary); font-size: 12px; font-weight: 700; }
.notification-card p { min-height: 32px; margin: 4px 0 8px; color: var(--fchub-text-secondary); font-size: 10.5px; line-height: 1.45; }
.notification-subject-preview { display: block; overflow: hidden; color: var(--fchub-text-secondary); font-size: 10.5px; font-weight: 550; text-overflow: ellipsis; white-space: nowrap; }
.delivery-dot { width: 7px; height: 7px; border-radius: 50%; background: #94a3b8; }
.delivery-dot.is-built_in { background: #22c55e; }
.delivery-dot.is-fluentcrm { background: #8b5cf6; }
.notification-card-actions { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 8px; margin-top: 14px; }
.notification-card-actions .el-select { width: 100%; }

.notification-editor-header { display: grid; grid-template-columns: minmax(120px, 1fr) minmax(180px, auto) minmax(260px, 1fr); align-items: center; gap: 16px; margin: -4px 0 18px; padding-bottom: 16px; border-bottom: 1px solid var(--fchub-border-color); }
.notification-editor-back { display: inline-flex; align-items: center; justify-self: start; gap: 5px; padding: 0; border: 0; background: transparent; color: var(--el-color-primary); font-size: 11px; font-weight: 650; cursor: pointer; }
.notification-editor-title { text-align: center; }
.notification-editor-title span { color: var(--el-color-primary); font-size: 9px; font-weight: 750; text-transform: uppercase; letter-spacing: .08em; }
.notification-editor-title h2 { margin: 3px 0 0; color: var(--fchub-text-primary); font-size: 18px; line-height: 1.2; }
.notification-editor-actions { display: flex; justify-content: flex-end; gap: 7px; }
.notification-editor-actions .el-select { width: 150px; }
.notification-envelope-fields { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 16px; }
.notification-envelope-fields label > span,
.block-field-grid label > span,
.brand-field-grid label > span { display: block; margin-bottom: 6px; color: var(--fchub-text-primary); font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
.notification-field-with-action { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 6px; }
.notification-delivery-note { margin: -3px 0 16px; }
.template-inheritance-card { display: grid; grid-template-columns: 38px minmax(0, 1fr) auto; align-items: center; gap: 11px; margin: 0 0 16px; padding: 12px 14px; border: 1px solid #cfe1ff; border-radius: 11px; background: #f7faff; }
.template-inheritance-card.is-overridden { border-color: #fed7aa; background: #fffaf5; }
.template-inheritance-icon { display: grid; width: 38px; height: 38px; place-items: center; border-radius: 10px; background: #e8f2ff; color: var(--el-color-primary); }
.template-inheritance-card > div:nth-child(2) { display: grid; gap: 3px; }
.template-inheritance-card strong { font-size: 11px; }
.template-inheritance-card span { color: var(--fchub-text-secondary); font-size: 10px; line-height: 1.4; }
.notification-editor-workspace { display: grid; grid-template-columns: minmax(0, 1.08fr) minmax(330px, .92fr); gap: 16px; align-items: start; }
.notification-editor-workspace--brand { grid-template-columns: minmax(0, 1fr) minmax(390px, .82fr); }
.notification-composer,
.notification-preview-panel { min-width: 0; }
.notification-block-palette { display: flex; flex-wrap: wrap; align-items: center; gap: 5px; margin-bottom: 10px; padding: 8px; border: 1px solid var(--fchub-border-color); border-radius: 10px; background: #f8fafc; }
.notification-block-palette > span { margin: 0 5px; color: var(--fchub-text-tertiary); font-size: 9px; font-weight: 750; text-transform: uppercase; letter-spacing: .06em; }
.notification-block-list { display: grid; gap: 9px; }
.notification-block { padding: 11px; border: 1px solid var(--fchub-border-color); border-radius: 11px; background: #fff; }
.notification-block:focus-within { border-color: color-mix(in srgb, var(--el-color-primary) 38%, var(--fchub-border-color)); box-shadow: 0 0 0 3px color-mix(in srgb, var(--el-color-primary) 7%, transparent); }
.notification-block-header { display: flex; align-items: center; justify-content: space-between; min-height: 24px; margin-bottom: 8px; }
.notification-block-header > span { color: var(--fchub-text-secondary); font-size: 9px; font-weight: 750; text-transform: uppercase; letter-spacing: .06em; }
.notification-block-header > div { display: flex; gap: 1px; }
.block-field-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
.block-field-grid--heading { grid-template-columns: minmax(0, 1fr) 120px; }
.block-field-grid label:last-child:nth-child(3) { grid-column: 1 / -1; }
.dynamic-block-field { display: grid; grid-template-columns: 34px minmax(0, 1fr) minmax(150px, .7fr); align-items: center; gap: 10px; padding: 10px; border-radius: 8px; background: #f5f8ff; }
.dynamic-block-field > .el-icon { justify-self: center; color: var(--el-color-primary); font-size: 18px; }
.dynamic-block-field > div { display: grid; gap: 2px; }
.dynamic-block-field strong { font-size: 11px; }
.dynamic-block-field span { color: var(--fchub-text-secondary); font-size: 10px; line-height: 1.4; }
.divider-block-preview { padding: 18px 4px; }
.divider-block-preview span { display: block; height: 1px; background: #dbe2ea; }
.spacer-block-field { display: grid; grid-template-columns: 100px minmax(0, 1fr); align-items: center; gap: 16px; padding: 5px 3px; color: var(--fchub-text-secondary); font-size: 11px; }
.notification-brand-panel { margin-top: 12px; border: 1px solid var(--fchub-border-color); border-radius: 11px; }
.notification-brand-panel :deep(.el-collapse-item__header) { height: 48px; padding: 0 14px; border: 0; border-radius: 11px; }
.notification-brand-panel :deep(.el-collapse-item__wrap) { border: 0; }
.notification-brand-panel :deep(.el-collapse-item__content) { padding: 0 14px 14px; }
.brand-panel-title { display: flex; align-items: center; gap: 7px; color: var(--fchub-text-primary); font-size: 11px; font-weight: 700; }
.brand-panel-title small { color: var(--fchub-text-tertiary); font-size: 10px; font-weight: 500; }
.brand-field-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; padding-top: 12px; border-top: 1px solid var(--fchub-border-color); }
.brand-width-field { grid-column: 1 / -1; }
.brand-template-editor { padding: 16px; border: 1px solid var(--fchub-border-color); border-radius: 14px; background: #fff; }
.brand-editor-intro { display: flex; align-items: flex-start; gap: 11px; margin-bottom: 16px; padding: 13px; border-radius: 10px; background: #f4f8ff; }
.brand-editor-intro > .el-icon { flex: 0 0 auto; margin-top: 2px; color: var(--el-color-primary); font-size: 18px; }
.brand-editor-intro > div { display: grid; gap: 3px; }
.brand-editor-intro strong { font-size: 12px; }
.brand-editor-intro span { color: var(--fchub-text-secondary); font-size: 10.5px; line-height: 1.45; }
.brand-field-grid--studio { padding: 0; border: 0; }
.brand-field-wide { grid-column: 1 / -1; }
.media-field { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 7px; }
.colour-field { display: flex; align-items: center; min-height: 32px; gap: 8px; }
.colour-field code { color: var(--fchub-text-secondary); font-size: 10px; }

:global(.field-variable-option) { display: grid; min-width: 210px; gap: 2px; }
:global(.field-variable-option strong) { font-size: 11px; }
:global(.field-variable-option code) { color: #64748b; font-size: 9px; }

@media (max-width: 1180px) {
  .notification-card-grid { grid-template-columns: 1fr; }
  .notification-editor-workspace { grid-template-columns: 1fr; }
}

@media (max-width: 782px) {
  .notification-studio { padding-bottom: 18px; }
  .notification-card-actions { grid-template-columns: 1fr; }
  .notification-editor-header { grid-template-columns: 1fr auto; gap: 10px; }
  .notification-editor-title { grid-column: 1 / -1; grid-row: 1; text-align: left; }
  .notification-editor-back { grid-column: 1; grid-row: 2; }
  .notification-editor-actions { grid-column: 2; grid-row: 2; flex-wrap: wrap; }
  .notification-editor-actions .el-select { width: 100%; }
  .notification-envelope-fields { grid-template-columns: 1fr; }
  .notification-block-palette { overflow-x: auto; flex-wrap: nowrap; }
  .notification-block-palette > span,
  .notification-block-palette .el-button { flex: 0 0 auto; }
  .block-field-grid,
  .block-field-grid--heading,
  .brand-field-grid { grid-template-columns: 1fr; }
  .block-field-grid label:last-child:nth-child(3),
  .brand-width-field { grid-column: 1; }
  .dynamic-block-field { grid-template-columns: 30px minmax(0, 1fr); }
  .dynamic-block-field .el-select { grid-column: 1 / -1; }
  .brand-field-wide { grid-column: 1; }
  .brand-template-card { grid-template-columns: 40px minmax(0, 1fr) 18px; }
  .brand-template-swatches { display: none; }
}

@media (max-width: 480px) {
  .notification-card-main { gap: 8px; }
  .notification-card { padding: 12px; }
  .notification-editor-actions .el-button { flex: 1; margin: 0; }
  .notification-field-with-action { grid-template-columns: 1fr; }
}
</style>
