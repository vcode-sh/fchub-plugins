<template>
  <div class="email-rich-editor" :class="{ 'is-focused': editor?.isFocused }">
    <div class="email-rich-toolbar" role="toolbar" aria-label="Text formatting">
      <button type="button" :class="{ 'is-active': editor?.isActive('bold') }" aria-label="Bold" @click="editor?.chain().focus().toggleBold().run()">
        <Bold :size="16" />
      </button>
      <button type="button" :class="{ 'is-active': editor?.isActive('italic') }" aria-label="Italic" @click="editor?.chain().focus().toggleItalic().run()">
        <Italic :size="16" />
      </button>
      <button type="button" :class="{ 'is-active': editor?.isActive('underline') }" aria-label="Underline" @click="editor?.chain().focus().toggleUnderline().run()">
        <UnderlineIcon :size="16" />
      </button>
      <button type="button" :class="{ 'is-active': editor?.isActive('bulletList') }" aria-label="Bulleted list" @click="editor?.chain().focus().toggleBulletList().run()">
        <ListIcon :size="16" />
      </button>
      <button type="button" :class="{ 'is-active': editor?.isActive('blockquote') }" aria-label="Block quote" @click="editor?.chain().focus().toggleBlockquote().run()">
        <Quote :size="16" />
      </button>
      <el-popover placement="bottom-start" :width="280" trigger="click">
        <template #reference>
          <button type="button" :class="{ 'is-active': editor?.isActive('link') }" aria-label="Add or edit link">
            <LinkIcon :size="16" />
          </button>
        </template>
        <div class="email-link-popover">
          <label :for="`email-link-${editorId}`">Link destination</label>
          <el-input :id="`email-link-${editorId}`" v-model="linkUrl" placeholder="https://example.com" @keyup.enter="applyLink" />
          <div>
            <el-button size="small" @click="removeLink">Remove</el-button>
            <el-button size="small" type="primary" @click="applyLink">Apply</el-button>
          </div>
        </div>
      </el-popover>
      <div class="email-toolbar-spacer" />
      <el-dropdown trigger="click" max-height="300" @command="insertVariable">
        <button type="button" class="email-variable-trigger">
          <el-icon><Plus /></el-icon>
          <span>Personalise</span>
        </button>
        <template #dropdown>
          <el-dropdown-menu>
            <el-dropdown-item v-for="variable in variableOptions" :key="variable.value" :command="variable.value">
              <span>{{ variable.label }}</span>
              <code>{{ variable.value }}</code>
            </el-dropdown-item>
          </el-dropdown-menu>
        </template>
      </el-dropdown>
    </div>
    <EditorContent :editor="editor" class="email-rich-content" />
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { EditorContent, useEditor } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import LinkExtension from '@tiptap/extension-link'
import Underline from '@tiptap/extension-underline'
import Placeholder from '@tiptap/extension-placeholder'
import { Plus } from '@element-plus/icons-vue'
import { Bold, Italic, Link as LinkIcon, List as ListIcon, Quote, Underline as UnderlineIcon } from '@lucide/vue'

const props = defineProps({
  modelValue: { type: String, default: '' },
  variables: { type: Object, default: () => ({}) },
})
const emit = defineEmits(['update:modelValue'])
const linkUrl = ref('')
const editorId = Math.random().toString(16).slice(2)

const variableOptions = computed(() => Object.entries(props.variables).map(([value, config]) => ({
  value,
  label: config.label,
})))

const editor = useEditor({
  content: props.modelValue || '<p></p>',
  extensions: [
    StarterKit.configure({ heading: false, link: false, underline: false }),
    Underline,
    LinkExtension.configure({ openOnClick: false, autolink: true, defaultProtocol: 'https' }),
    Placeholder.configure({ placeholder: 'Write the email copy members will receive…' }),
  ],
  editorProps: {
    attributes: {
      'aria-label': 'Email content',
    },
  },
  onUpdate: ({ editor: currentEditor }) => emit('update:modelValue', currentEditor.getHTML()),
  onSelectionUpdate: ({ editor: currentEditor }) => {
    linkUrl.value = currentEditor.getAttributes('link').href ?? ''
  },
})

watch(() => props.modelValue, (value) => {
  if (!editor.value || editor.value.getHTML() === value) return
  editor.value.commands.setContent(value || '<p></p>', { emitUpdate: false })
})

function insertVariable(variable) {
  editor.value?.chain().focus().insertContent(variable).run()
}

function applyLink() {
  const value = linkUrl.value.trim()
  if (!value) return removeLink()
  editor.value?.chain().focus().extendMarkRange('link').setLink({ href: value }).run()
}

function removeLink() {
  editor.value?.chain().focus().extendMarkRange('link').unsetLink().run()
  linkUrl.value = ''
}

onBeforeUnmount(() => editor.value?.destroy())
</script>

<style scoped>
.email-rich-editor {
  overflow: hidden;
  border: 1px solid var(--fchub-border-color);
  border-radius: 9px;
  background: #fff;
  transition: border-color .16s ease, box-shadow .16s ease;
}

.email-rich-editor.is-focused {
  border-color: var(--el-color-primary);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--el-color-primary) 10%, transparent);
}

.email-rich-toolbar {
  display: flex;
  align-items: center;
  gap: 3px;
  padding: 7px;
  border-bottom: 1px solid var(--fchub-border-color);
  background: #f8fafc;
}

.email-rich-toolbar button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 32px;
  height: 32px;
  padding: 0 8px;
  border: 0;
  border-radius: 6px;
  background: transparent;
  color: var(--fchub-text-secondary);
  cursor: pointer;
}

.email-rich-toolbar button:hover,
.email-rich-toolbar button.is-active {
  background: #eaf3ff;
  color: var(--el-color-primary);
}

.email-toolbar-spacer { flex: 1; }
.email-variable-trigger { gap: 5px; width: auto !important; font-size: 11px; font-weight: 650; }

.email-rich-content :deep(.tiptap) {
  min-height: 132px;
  padding: 14px 16px;
  color: var(--fchub-text-primary);
  font-size: 13px;
  line-height: 1.65;
  outline: none;
}

.email-rich-content :deep(.tiptap p) { margin: 0 0 12px; }
.email-rich-content :deep(.tiptap p:last-child) { margin-bottom: 0; }
.email-rich-content :deep(.tiptap ul),
.email-rich-content :deep(.tiptap ol) { margin: 0 0 12px; padding-left: 22px; }
.email-rich-content :deep(.tiptap blockquote) { margin: 12px 0; padding-left: 14px; border-left: 3px solid #cbd5e1; color: var(--fchub-text-secondary); }
.email-rich-content :deep(.tiptap a) { color: var(--el-color-primary); text-decoration: underline; }
.email-rich-content :deep(.is-editor-empty:first-child::before) { float: left; height: 0; color: #94a3b8; content: attr(data-placeholder); pointer-events: none; }

.email-link-popover label { display: block; margin-bottom: 6px; font-size: 12px; font-weight: 650; }
.email-link-popover > div:last-child { display: flex; justify-content: flex-end; gap: 6px; margin-top: 10px; }

@media (max-width: 782px) {
  .email-rich-toolbar { flex-wrap: wrap; }
  .email-toolbar-spacer { display: none; }
  .email-variable-trigger { margin-left: auto; }
}
</style>
