<template>
  <div class="cartshift-page-header">
    <div v-if="guided" class="cartshift-brand">
      <span class="cartshift-brand-mark dashicons dashicons-randomize" aria-hidden="true"></span>
      <div>
        <div class="cartshift-brand-line"><h1>{{ title }}</h1><span v-if="version" class="cartshift-version">v{{ version }}</span></div>
        <p v-if="subtitle">{{ subtitle }}</p>
      </div>
    </div>
    <h1 v-else>{{ title }} <span v-if="version" class="cartshift-version">v{{ version }}</span></h1>
    <div class="cartshift-theme-switcher">
      <button
        class="cartshift-theme-btn"
        :title="'Theme: ' + theme.themeMode.value"
        @click.stop="showDropdown = !showDropdown"
      >
        <span class="dashicons" :class="themeIcon(theme.themeMode.value)" aria-hidden="true"></span>
      </button>
      <div v-if="showDropdown" class="cartshift-theme-dropdown">
        <button
          v-for="opt in themeOptions"
          :key="opt.key"
          class="cartshift-theme-option"
          :class="{ active: theme.themeMode.value === opt.key }"
          @click="selectTheme(opt.key)"
        >
          <span class="dashicons" :class="themeIcon(opt.key)" aria-hidden="true"></span>
          {{ opt.label }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, inject, onMounted, onBeforeUnmount } from 'vue';

defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
  guided: { type: Boolean, default: false },
});

const config = inject('config', {});
const theme = inject('theme');
const version = config.version || '';
const showDropdown = ref(false);

const themeOptions = [
  { key: 'light', label: 'Light' },
  { key: 'dark', label: 'Dark' },
  { key: 'system', label: 'System' },
];

function themeIcon(mode) {
  return { light: 'dashicons-lightbulb', dark: 'dashicons-star-filled', system: 'dashicons-desktop' }[mode];
}

function selectTheme(mode) {
  theme.changeTheme(mode);
  showDropdown.value = false;
}

function closeDropdown() {
  showDropdown.value = false;
}

onMounted(() => {
  document.addEventListener('click', closeDropdown);
});

onBeforeUnmount(() => {
  document.removeEventListener('click', closeDropdown);
});
</script>
