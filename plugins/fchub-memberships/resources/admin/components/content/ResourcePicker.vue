<template>
  <el-select
    v-model="selected"
    filterable
    remote
    :clearable="!allowAll"
    :remote-method="search"
    :loading="loading"
    :disabled="disabled"
    :placeholder="`Search ${typeLabel}…`"
    class="resource-picker"
    popper-class="resource-picker-popper"
    @visible-change="onVisibleChange"
  >
    <template #empty>
      <p class="resource-picker-empty">{{ emptyText }}</p>
    </template>

    <el-option v-if="allowAll" :label="ALL_OF_TYPE" value="0" />
    <el-option
      v-for="item in options"
      :key="item.id"
      :label="item.label"
      :value="item.id"
    >
      <span class="resource-picker-title">{{ item.label }}</span>
      <span class="resource-picker-meta">
        {{ item.typeLabel }}<template v-if="item.statusLabel"> · {{ item.statusLabel }}</template>
      </span>
    </el-option>
  </el-select>
</template>

<script setup>
import { computed } from 'vue'
import { content } from '@/api/index.js'
import { useResourceSearch } from '@/composables/content/useResourceSearch.js'

const ALL_OF_TYPE = 'All of this type'

const props = defineProps({
  resourceType: { type: String, required: true },
  typeLabel: { type: String, required: true },
  allowAll: Boolean,
  disabled: Boolean,
})

const model = defineModel({ type: String, default: '' })
const label = defineModel('label', { type: String, default: null })

const { options, loading, emptyText, search, browse } = useResourceSearch({
  contentApi: content,
  resourceType: () => props.resourceType,
  typeLabel: () => props.typeLabel,
  selection: () => ({ id: model.value, label: label.value }),
})

// The id and its title are written together, so a selection can never decay
// into a bare resource id downstream.
const selected = computed({
  get: () => model.value,
  set: (next) => {
    const id = next ?? ''
    model.value = id
    label.value = titleFor(id)
  },
})

function titleFor(id) {
  if (!id) return null
  if (props.allowAll && id === '0') return ALL_OF_TYPE
  return options.value.find((item) => item.id === id)?.label ?? null
}

function onVisibleChange(visible) {
  if (visible) browse()
}
</script>

