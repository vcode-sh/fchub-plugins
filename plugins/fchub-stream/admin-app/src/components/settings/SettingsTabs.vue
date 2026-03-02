<template>
  <div class="bg-white border border-gray-200 rounded-lg shadow-sm">
    <TabGroup :selectedIndex="selectedIndex" @change="handleTabChange">
      <TabList class="flex border-b border-gray-200">
        <Tab
          v-for="tab in tabs"
          :key="tab.id"
          v-slot="{ selected }"
          :class="[
            'px-6 py-3 text-sm font-medium transition-colors focus:outline-none',
            'border-b-2',
            selected
              ? 'text-primary-700'
              : 'border-transparent text-gray-500 hover:text-gray-700 hover:bg-gray-50',
          ]"
          :style="selected ? {
            backgroundColor: 'var(--color-primary-50)',
            color: 'var(--color-primary-700)',
            borderBottomColor: 'var(--color-primary-500)',
          } : {}"
        >
          <div class="flex items-center gap-2">
            <component :is="tab.icon" v-if="tab.icon" class="w-5 h-5" />
            <span>{{ tab.label }}</span>
            <span
              v-if="tab.badge"
              :class="[
                'ml-2 px-2 py-0.5 text-xs rounded-full',
                tab.badge === 'new'
                  ? 'bg-blue-100 text-blue-800'
                  : 'bg-gray-100 text-gray-600',
              ]"
            >
              {{ tab.badge }}
            </span>
          </div>
        </Tab>
      </TabList>

      <TabPanels>
        <TabPanel
          v-for="tab in tabs"
          :key="tab.id"
          v-slot="{ selected }"
          class="p-6"
        >
          <slot :name="`tab-${tab.id}`" :tab="tab" :selected="selected" />
        </TabPanel>
      </TabPanels>
    </TabGroup>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import { TabGroup, TabList, Tab, TabPanels, TabPanel } from '@headlessui/vue'

const props = defineProps({
  tabs: {
    type: Array,
    required: true,
    validator: tabs =>
      tabs.every(
        tab =>
          tab.id &&
          typeof tab.label === 'string' &&
          typeof tab.index === 'number'
      ),
  },
  defaultTab: {
    type: String,
    default: null,
  },
})

const emit = defineEmits(['tab-change'])

const selectedIndex = ref(0)

// Initialize selected tab from defaultTab prop or first tab
watch(
  () => props.defaultTab,
  newDefaultTab => {
    if (newDefaultTab) {
      const tab = props.tabs.find(t => t.id === newDefaultTab)
      if (tab) {
        selectedIndex.value = tab.index
      }
    }
  },
  { immediate: true }
)

function handleTabChange(index) {
  selectedIndex.value = index
  const tab = props.tabs.find(t => t.index === index)
  if (tab) {
    emit('tab-change', tab.id, tab)
  }
}
</script>

