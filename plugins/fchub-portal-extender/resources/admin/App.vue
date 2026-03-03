<template>
  <div class="fchub-app-wrapper">
    <nav class="fchub-top-nav">
      <div class="fchub-nav-inner">
        <div class="fchub-nav-left">
          <router-link to="/" class="fchub-brand">
            <el-icon :size="20"><Grid /></el-icon>
            <span>Portal Extender</span>
          </router-link>
        </div>
        <div class="fchub-nav-right">
          <el-dropdown trigger="click" @command="changeTheme" placement="bottom-end">
            <button class="fchub-theme-btn" :title="'Theme: ' + themeMode">
              <el-icon :size="16">
                <Sunny v-if="themeMode === 'light'" />
                <Moon v-else-if="themeMode === 'dark'" />
                <Monitor v-else />
              </el-icon>
            </button>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item command="light" :class="{ 'is-active': themeMode === 'light' }">
                  <el-icon><Sunny /></el-icon> Light
                </el-dropdown-item>
                <el-dropdown-item command="dark" :class="{ 'is-active': themeMode === 'dark' }">
                  <el-icon><Moon /></el-icon> Dark
                </el-dropdown-item>
                <el-dropdown-item command="system" :class="{ 'is-active': themeMode === 'system' }">
                  <el-icon><Monitor /></el-icon> System
                </el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </div>
      </div>
    </nav>
    <div class="fchub-content-area">
      <router-view :key="route.fullPath" />
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue'
import { useRoute } from 'vue-router'

const route = useRoute()

// ── Theme (syncs with FluentCart's fcart_admin_theme) ──

const STORAGE_KEY = 'fcart_admin_theme'
const FC_THEME_EVENT = 'onFluentCartThemeChange'
const DARK_TARGETS = ['body', '#wpbody-content', '.wp-toolbar', '#wpfooter']

const themeMode = ref('system')

function getSystemTheme() {
  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
}

function readSavedMode() {
  const raw = localStorage.getItem(STORAGE_KEY)
  if (!raw) return 'system'
  if (raw === 'light' || raw === 'dark') return raw
  if (raw.startsWith('system')) return 'system'
  return 'system'
}

function applyDark(isDark) {
  DARK_TARGETS.forEach(sel => {
    const el = sel === 'body' ? document.body : document.querySelector(sel)
    if (el) el.classList.toggle('dark', isDark)
  })
}

function applyTheme(mode) {
  themeMode.value = mode
  const resolved = mode === 'system' ? getSystemTheme() : mode
  applyDark(resolved === 'dark')

  if (mode === 'system') {
    localStorage.setItem(STORAGE_KEY, `system:${resolved}`)
  } else {
    localStorage.setItem(STORAGE_KEY, mode)
  }
}

function changeTheme(mode) {
  applyTheme(mode)
  window.dispatchEvent(new CustomEvent(FC_THEME_EVENT, { detail: { theme: mode === 'system' ? getSystemTheme() : mode } }))
}

function onFcThemeChange() {
  themeMode.value = readSavedMode()
  const resolved = themeMode.value === 'system' ? getSystemTheme() : themeMode.value
  applyDark(resolved === 'dark')
}

function onSystemPrefChange() {
  if (themeMode.value === 'system') {
    applyTheme('system')
  }
}

let mediaQuery

onMounted(() => {
  themeMode.value = readSavedMode()
  applyTheme(themeMode.value)

  window.addEventListener(FC_THEME_EVENT, onFcThemeChange)
  mediaQuery = window.matchMedia('(prefers-color-scheme: dark)')
  mediaQuery.addEventListener('change', onSystemPrefChange)
})

onBeforeUnmount(() => {
  window.removeEventListener(FC_THEME_EVENT, onFcThemeChange)
  if (mediaQuery) mediaQuery.removeEventListener('change', onSystemPrefChange)
})
</script>

<style>
#fchub-portal-extender-app {
  margin-left: -20px;
  margin-top: -10px;
}

.fchub-app-wrapper {
  min-height: calc(100vh - 32px);
  background: var(--fchub-page-bg);
}

.fchub-top-nav {
  position: fixed;
  top: 32px;
  left: 160px;
  right: 0;
  z-index: 2000;
  height: var(--fchub-nav-height);
  background: var(--fchub-card-bg);
  box-shadow: var(--fchub-nav-shadow);
}

.folded .fchub-top-nav {
  left: 36px;
}

.fchub-nav-inner {
  max-width: 1260px;
  margin: 0 auto;
  padding: 0 24px;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.fchub-nav-left {
  display: flex;
  align-items: center;
  gap: 24px;
}

.fchub-brand {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 15px;
  font-weight: 600;
  color: var(--fchub-text-primary);
  text-decoration: none;
}

.fchub-brand:hover {
  color: var(--fchub-text-primary);
}

.fchub-nav-right {
  display: flex;
  align-items: center;
  gap: 4px;
}

.fchub-theme-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border: none;
  border-radius: 8px;
  background: transparent;
  color: var(--fchub-text-secondary);
  cursor: pointer;
  transition: background-color 0.15s, color 0.15s;
}

.fchub-theme-btn:hover {
  color: var(--fchub-text-primary);
  background: #F0F0F1;
}

body.dark .fchub-theme-btn:hover {
  background: #2a2e37;
}

.fchub-content-area {
  max-width: 1260px;
  margin: 0 auto;
  padding: 24px;
  padding-top: calc(var(--fchub-nav-height) + 24px);
}
</style>
