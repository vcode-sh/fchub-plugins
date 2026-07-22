<template>
  <section class="email-brand-editor" :class="{ 'is-compact': compact }" :aria-label="ariaLabel">
    <div class="email-brand-editor__intro">
      <el-icon><Brush /></el-icon>
      <div>
        <strong>{{ title }}</strong>
        <span>{{ description }}</span>
      </div>
    </div>

    <div class="email-brand-editor__grid">
      <label>
        <span>Header</span>
        <el-select :model-value="value('header_style')" @update:model-value="update('header_style', $event)">
          <el-option label="Logo or site name" value="brand" />
          <el-option label="Logo only" value="logo" />
          <el-option label="Text only" value="text" />
          <el-option label="No header" value="none" />
        </el-select>
      </label>
      <label>
        <span>Header alignment</span>
        <el-select :model-value="value('header_alignment')" @update:model-value="update('header_alignment', $event)">
          <el-option label="Left" value="left" />
          <el-option label="Centre" value="center" />
          <el-option label="Right" value="right" />
        </el-select>
      </label>
      <label class="email-brand-editor__wide">
        <span>Logo</span>
        <div class="email-brand-editor__media">
          <el-input :model-value="value('logo_url')" placeholder="Choose from Media Library or paste a URL" @update:model-value="update('logo_url', $event)" />
          <el-button @click="$emit('choose-logo')"><el-icon><Picture /></el-icon>Choose</el-button>
        </div>
      </label>
      <label><span>Logo width</span><el-slider :model-value="value('logo_width')" :min="60" :max="240" :step="4" show-input @update:model-value="update('logo_width', $event)" /></label>
      <label><span>Header text fallback</span><el-input :model-value="value('header_text')" placeholder="Uses the site name when empty" @update:model-value="update('header_text', $event)" /></label>
      <label><span>Header colour</span><div class="email-brand-editor__colour"><el-color-picker :model-value="value('header_background')" @update:model-value="update('header_background', $event)" /><code>{{ value('header_background') }}</code></div></label>
      <label><span>Page background</span><div class="email-brand-editor__colour"><el-color-picker :model-value="value('background_color')" @update:model-value="update('background_color', $event)" /><code>{{ value('background_color') }}</code></div></label>
      <label><span>Content background</span><div class="email-brand-editor__colour"><el-color-picker :model-value="value('panel_color')" @update:model-value="update('panel_color', $event)" /><code>{{ value('panel_color') }}</code></div></label>
      <label><span>Text colour</span><div class="email-brand-editor__colour"><el-color-picker :model-value="value('content_color')" @update:model-value="update('content_color', $event)" /><code>{{ value('content_color') }}</code></div></label>
      <label><span>Action colour</span><div class="email-brand-editor__colour"><el-color-picker :model-value="value('primary_color')" @update:model-value="update('primary_color', $event)" /><code>{{ value('primary_color') }}</code></div></label>
      <label>
        <span>Font</span>
        <el-select :model-value="value('font_family')" @update:model-value="update('font_family', $event)">
          <el-option label="System" value="system" />
          <el-option label="Arial" value="arial" />
          <el-option label="Georgia" value="georgia" />
        </el-select>
      </label>
      <label><span>Content width</span><el-slider :model-value="value('content_width')" :min="480" :max="680" :step="20" show-input @update:model-value="update('content_width', $event)" /></label>
      <label><span>Content padding</span><el-slider :model-value="value('content_padding')" :min="20" :max="56" :step="4" show-input @update:model-value="update('content_padding', $event)" /></label>
      <label><span>Corner radius</span><el-slider :model-value="value('border_radius')" :min="0" :max="24" :step="2" show-input @update:model-value="update('border_radius', $event)" /></label>
      <label><span>Footer background</span><div class="email-brand-editor__colour"><el-color-picker :model-value="value('footer_background')" @update:model-value="update('footer_background', $event)" /><code>{{ value('footer_background') }}</code></div></label>
      <label><span>Footer text colour</span><div class="email-brand-editor__colour"><el-color-picker :model-value="value('footer_color')" @update:model-value="update('footer_color', $event)" /><code>{{ value('footer_color') }}</code></div></label>
      <label class="email-brand-editor__wide"><span>Footer content</span><EmailRichTextEditor :model-value="value('footer_html')" :variables="variables" @update:model-value="update('footer_html', $event)" /></label>
    </div>
  </section>
</template>

<script setup>
import { Brush, Picture } from '@element-plus/icons-vue'
import EmailRichTextEditor from './EmailRichTextEditor.vue'

const props = defineProps({
  modelValue: { type: Object, required: true },
  variables: { type: Array, default: () => [] },
  compact: { type: Boolean, default: false },
  title: { type: String, default: 'Default shell for every built-in email' },
  description: { type: String, default: 'Single emails inherit this header, canvas, content frame, and footer unless you explicitly override them.' },
  ariaLabel: { type: String, default: 'Email brand template' },
})

const emit = defineEmits(['update:modelValue', 'choose-logo'])

function value(key) {
  return props.modelValue?.[key]
}

function update(key, nextValue) {
  emit('update:modelValue', { ...props.modelValue, [key]: nextValue })
}
</script>

<style scoped>
.email-brand-editor { padding: 16px; border: 1px solid var(--fchub-border-color); border-radius: 14px; background: #fff; }
.email-brand-editor.is-compact { padding: 12px; border: 0; border-radius: 0; background: transparent; }
.email-brand-editor__intro { display: flex; gap: 12px; align-items: flex-start; padding: 14px; margin-bottom: 18px; border-radius: 12px; color: var(--fchub-primary); background: var(--fchub-primary-light); }
.email-brand-editor__intro .el-icon { margin-top: 2px; font-size: 18px; }
.email-brand-editor__intro div { display: grid; gap: 3px; }
.email-brand-editor__intro strong { color: var(--fchub-text-primary); }
.email-brand-editor__intro span { color: var(--fchub-text-secondary); font-size: 12px; line-height: 1.45; }
.email-brand-editor__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
.email-brand-editor__grid label { display: grid; gap: 7px; min-width: 0; }
.email-brand-editor__grid label > span { color: var(--fchub-text-secondary); font-size: 11px; font-weight: 700; letter-spacing: .035em; text-transform: uppercase; }
.email-brand-editor__wide { grid-column: 1 / -1; }
.email-brand-editor__media { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 8px; }
.email-brand-editor__colour { display: flex; align-items: center; gap: 8px; }
.email-brand-editor__colour code { color: var(--fchub-text-secondary); font-size: 12px; }

@media (max-width: 782px) {
  .email-brand-editor { padding: 12px; }
  .email-brand-editor__grid { grid-template-columns: 1fr; }
  .email-brand-editor__wide { grid-column: auto; }
  .email-brand-editor__media { grid-template-columns: 1fr; }
}
</style>
