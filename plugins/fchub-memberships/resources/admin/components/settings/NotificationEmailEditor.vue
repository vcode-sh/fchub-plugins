<template>
  <template v-if="editing && draft && !editingBrand">
    <div class="notification-editor-header">
      <button type="button" class="notification-editor-back" @click="$emit('cancel')">
        <el-icon><ArrowLeft /></el-icon>
        All notifications
      </button>
      <div class="notification-editor-title">
        <span>{{ editing.groupLabel }}</span>
        <h2>{{ editing.label }}</h2>
      </div>
      <div class="notification-editor-actions">
        <el-select v-model="draft.delivery" size="small" aria-label="Delivery owner">
          <el-option
            v-for="option in availableDeliveryOptions"
            :key="option.value"
            :label="option.label"
            :value="option.value"
          />
        </el-select>
        <el-tooltip content="Restore default message" placement="bottom">
          <el-button circle aria-label="Restore default message" @click="$emit('reset')">
            <el-icon><RefreshLeft /></el-icon>
          </el-button>
        </el-tooltip>
        <el-button type="primary" :loading="savingEmail" @click="$emit('save')">
          <el-icon><Check /></el-icon>
          Save email
        </el-button>
      </div>
    </div>

    <div class="notification-envelope-fields">
      <label>
        <span>Subject</span>
        <div class="notification-field-with-action">
          <el-input v-model="draft.template.subject" maxlength="160" show-word-limit />
          <VariableMenu
            :variables="editing.variables"
            @select="$emit('insert-variable', 'subject', $event)"
          />
        </div>
      </label>
      <label>
        <span>Pre-header</span>
        <div class="notification-field-with-action">
          <el-input
            v-model="draft.template.preheader"
            maxlength="180"
            show-word-limit
            placeholder="Inbox preview text"
          />
          <VariableMenu
            :variables="editing.variables"
            @select="$emit('insert-variable', 'preheader', $event)"
          />
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
      <div>
        <strong>
          {{ useGlobalTemplate ? 'Using global brand template' : 'Custom brand override' }}
        </strong>
        <span>
          {{ useGlobalTemplate
            ? 'Header, footer, colours, and spacing stay in sync with the shared template.'
            : 'Only this email uses the custom shell below.' }}
        </span>
      </div>
      <el-switch
        :model-value="useGlobalTemplate"
        inline-prompt
        active-text="Global"
        inactive-text="Custom"
        @update:model-value="$emit('update:use-global-template', $event)"
      />
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
          <el-tooltip
            v-for="blockType in blockTypes"
            :key="blockType.type"
            :content="`Add ${blockType.label.toLowerCase()}`"
            placement="top"
          >
            <el-button
              circle
              size="small"
              :aria-label="`Add ${blockType.label.toLowerCase()}`"
              @click="$emit('append-block', blockType.type)"
            >
              <el-icon><component :is="blockType.icon" /></el-icon>
            </el-button>
          </el-tooltip>
        </div>

        <div class="notification-block-list">
          <article
            v-for="(block, index) in draft.template.blocks"
            :key="block.id"
            class="notification-block"
          >
            <header class="notification-block-header">
              <span>{{ blockLabel(block.type) }}</span>
              <div role="group" :aria-label="`${blockLabel(block.type)} block actions`">
                <el-button
                  text
                  size="small"
                  :disabled="index === 0"
                  aria-label="Move block up"
                  @click="$emit('reorder-block', index, -1)"
                >
                  <el-icon><Top /></el-icon>
                </el-button>
                <el-button
                  text
                  size="small"
                  :disabled="index === draft.template.blocks.length - 1"
                  aria-label="Move block down"
                  @click="$emit('reorder-block', index, 1)"
                >
                  <el-icon><Bottom /></el-icon>
                </el-button>
                <el-button
                  text
                  size="small"
                  type="danger"
                  :disabled="draft.template.blocks.length === 1"
                  aria-label="Delete block"
                  @click="$emit('delete-block', index)"
                >
                  <el-icon><Delete /></el-icon>
                </el-button>
              </div>
            </header>

            <EmailRichTextEditor
              v-if="block.type === 'rich_text'"
              v-model="block.content"
              :variables="editing.variables"
            />

            <div
              v-else-if="block.type === 'heading'"
              class="block-field-grid block-field-grid--heading"
            >
              <el-input v-model="block.content" placeholder="Heading" />
              <el-select v-model="block.align" aria-label="Heading alignment">
                <el-option label="Left" value="left" />
                <el-option label="Centre" value="center" />
                <el-option label="Right" value="right" />
              </el-select>
            </div>

            <div v-else-if="block.type === 'button'" class="block-field-grid">
              <label><span>Button label</span><el-input v-model="block.label" /></label>
              <label>
                <span>Destination</span>
                <el-input v-model="block.url" placeholder="https:// or {account_url}" />
              </label>
              <label>
                <span>Alignment</span>
                <el-select v-model="block.align">
                  <el-option label="Left" value="left" />
                  <el-option label="Centre" value="center" />
                  <el-option label="Right" value="right" />
                </el-select>
              </label>
            </div>

            <div v-else-if="block.type === 'image'" class="block-field-grid">
              <label>
                <span>Image</span>
                <div class="media-field">
                  <el-input
                    v-model="block.url"
                    placeholder="Choose from Media Library or paste a URL"
                  />
                  <el-tooltip content="Choose from Media Library" placement="top">
                    <el-button
                      circle
                      aria-label="Choose image from Media Library"
                      @click="$emit('choose-block-image', block)"
                    >
                      <el-icon><Picture /></el-icon>
                    </el-button>
                  </el-tooltip>
                </div>
              </label>
              <label>
                <span>Alternative text</span>
                <el-input v-model="block.alt" placeholder="Describe the image" />
              </label>
              <label>
                <span>Optional link</span>
                <el-input v-model="block.link_url" placeholder="https://" />
              </label>
            </div>

            <div v-else-if="block.type === 'dynamic'" class="dynamic-block-field">
              <el-icon><DataAnalysis /></el-icon>
              <div>
                <strong>Membership content</strong>
                <span>Rendered from the selected member at send time.</span>
              </div>
              <el-select v-model="block.variable" filterable placeholder="Choose dynamic content">
                <el-option
                  v-for="variable in richVariables"
                  :key="variable.value"
                  :label="variable.label"
                  :value="variable.value"
                />
              </el-select>
            </div>

            <div v-else-if="block.type === 'divider'" class="divider-block-preview">
              <span />
            </div>

            <div v-else-if="block.type === 'spacer'" class="spacer-block-field">
              <span>Vertical space</span>
              <el-slider
                v-model="block.height"
                :min="8"
                :max="80"
                :step="4"
                show-input
              />
            </div>
          </article>
        </div>

        <el-collapse v-if="!useGlobalTemplate" class="notification-brand-panel">
          <el-collapse-item name="brand">
            <template #title>
              <span class="brand-panel-title">
                <el-icon><Brush /></el-icon>
                Brand override
                <small>Header, footer, canvas, and typography for this email only</small>
              </span>
            </template>
            <EmailBrandTemplateEditor
              :model-value="draftTheme"
              compact
              :variables="globalVariables"
              title="Custom shell for this email"
              description="These settings override the shared template only for this notification. Switch back to Global at any time to resume inheritance."
              aria-label="Per-email brand template override"
              @update:model-value="$emit('update:draft-theme', $event)"
              @choose-logo="$emit('choose-logo')"
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
        @update:device="$emit('update:device', $event)"
        @send-test="$emit('send-test', $event)"
      />
    </div>
  </template>
</template>

<script setup>
import { defineComponent, h } from 'vue'
import { ElButton, ElDropdown, ElDropdownItem, ElDropdownMenu } from 'element-plus'
import {
  ArrowLeft,
  Bottom,
  Brush,
  Check,
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
} from '@element-plus/icons-vue'
import EmailBrandTemplateEditor from './EmailBrandTemplateEditor.vue'
import EmailPreviewPanel from './EmailPreviewPanel.vue'
import EmailRichTextEditor from './EmailRichTextEditor.vue'

const blockTypes = [
  { type: 'rich_text', label: 'Text', icon: EditPen },
  { type: 'heading', label: 'Heading', icon: Message },
  { type: 'button', label: 'Button', icon: Promotion },
  { type: 'image', label: 'Image', icon: Picture },
  { type: 'divider', label: 'Divider', icon: Minus },
  { type: 'spacer', label: 'Space', icon: Bottom },
  { type: 'dynamic', label: 'Member data', icon: DataAnalysis },
]

const VariableMenu = defineComponent({
  props: { variables: { type: Object, default: () => ({}) } },
  emits: ['select'],
  setup(menuProps, { emit }) {
    return () => h(ElDropdown, {
      trigger: 'click',
      maxHeight: 320,
      onCommand: (value) => emit('select', value),
    }, {
      default: () => h(ElButton, { size: 'small' }, {
        default: () => [h(Plus), ' Variable'],
      }),
      dropdown: () => h(ElDropdownMenu, null, {
        default: () => Object.entries(menuProps.variables).map(([value, config]) => (
          h(ElDropdownItem, { command: value }, {
            default: () => h('span', { class: 'field-variable-option' }, [
              h('strong', config.label),
              h('code', value),
            ]),
          })
        )),
      }),
    })
  },
})

defineProps({
  availableDeliveryOptions: { type: Array, required: true },
  draft: { type: Object, default: null },
  draftTheme: { type: Object, default: null },
  editing: { type: Object, default: null },
  editingBrand: { type: Boolean, default: false },
  globalVariables: { type: Object, required: true },
  previewDevice: { type: String, required: true },
  previewError: { type: String, default: '' },
  previewHtml: { type: String, default: '' },
  previewing: { type: Boolean, default: false },
  previewSubject: { type: String, default: '' },
  richVariables: { type: Array, required: true },
  savingEmail: { type: Boolean, default: false },
  testing: { type: Boolean, default: false },
  useGlobalTemplate: { type: Boolean, default: true },
})

defineEmits([
  'append-block',
  'cancel',
  'choose-block-image',
  'choose-logo',
  'delete-block',
  'insert-variable',
  'reorder-block',
  'reset',
  'save',
  'send-test',
  'update:device',
  'update:draft-theme',
  'update:use-global-template',
])

function blockLabel(type) {
  return blockTypes.find((block) => block.type === type)?.label ?? 'Content'
}
</script>

<style scoped src="./NotificationEmailEditor.css"></style>
