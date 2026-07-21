<template>
  <div class="fchub-app-wrapper">
    <nav
      class="fchub-top-nav"
      :style="{ '--fchub-admin-bar-offset': `${adminBarOffset}px` }"
    >
      <div class="fchub-nav-inner">
        <div class="fchub-nav-left">
          <router-link to="/" class="fchub-brand">
            <el-icon :size="20"><UserFilled /></el-icon>
            <span>Memberships</span>
          </router-link>
          <div class="fchub-nav-links">
            <router-link
              v-for="item in navItems"
              :key="item.to"
              :to="item.to"
              class="fchub-nav-link"
              :class="{ active: isActive(item.to) }"
            >
              {{ item.label }}
            </router-link>
          </div>
          <el-dropdown
            class="fchub-mobile-nav"
            trigger="click"
            placement="bottom-start"
            @command="navigateToSection"
          >
            <button class="fchub-mobile-nav-trigger" type="button" aria-label="Navigate sections">
              <span>{{ currentSection.label }}</span>
              <el-icon><ArrowDown /></el-icon>
            </button>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item
                  v-for="item in workspaceNavItems"
                  :key="item.to"
                  :command="item.to"
                  :class="{ 'is-active': currentSection.to === item.to }"
                >
                  {{ item.label }}
                </el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
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
          <router-link to="/settings" class="fchub-nav-link" :class="{ active: isActive('/settings') }">
            <el-icon :size="16" style="margin-right: 4px"><Setting /></el-icon>
            Settings
          </router-link>
        </div>
      </div>
    </nav>
    <div class="fchub-content-area">
      <router-view :key="route.fullPath" />
    </div>
  </div>
</template>

<script setup>
import { computed, ref, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowDown, Monitor, Moon, Setting, Sunny, UserFilled } from '@element-plus/icons-vue'
import { getWorkspaceSection, WORKSPACE_NAV_ITEMS } from '@/workspace/workspaceUi.js'

const route = useRoute()
const router = useRouter()

const workspaceNavItems = WORKSPACE_NAV_ITEMS
const navItems = WORKSPACE_NAV_ITEMS.filter((item) => item.to !== '/settings')
const currentSection = computed(() => getWorkspaceSection(route.path))

function navigateToSection(path) {
  if (path !== route.path) router.push(path)
}

function isActive(path) {
  if (path === '/') return route.path === '/'
  return route.path.startsWith(path)
}

// ── Theme (syncs with FluentCart's fcart_admin_theme) ──

const STORAGE_KEY = 'fcart_admin_theme'
const FC_THEME_EVENT = 'onFluentCartThemeChange'
const DARK_TARGETS = ['body', '#wpbody-content', '.wp-toolbar', '#wpfooter']

const themeMode = ref('system') // 'light' | 'dark' | 'system'
const adminBarOffset = ref(getVisibleAdminBarOffset())

function getVisibleAdminBarOffset() {
  const adminBar = document.querySelector('#wpadminbar')
  if (!adminBar) return 0

  return Math.max(0, adminBar.getBoundingClientRect().bottom)
}

let layoutFrame

function updateAdminBarOffset() {
  if (layoutFrame) return

  layoutFrame = window.requestAnimationFrame(() => {
    layoutFrame = undefined
    const nextOffset = getVisibleAdminBarOffset()
    if (nextOffset !== adminBarOffset.value) adminBarOffset.value = nextOffset
  })
}

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

  // Persist in FluentCart-compatible format
  if (mode === 'system') {
    localStorage.setItem(STORAGE_KEY, `system:${resolved}`)
  } else {
    localStorage.setItem(STORAGE_KEY, mode)
  }
}

function changeTheme(mode) {
  applyTheme(mode)
  // Dispatch event so FluentCart (if open in another tab) can pick it up
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
  window.addEventListener('scroll', updateAdminBarOffset, { passive: true })
  window.addEventListener('resize', updateAdminBarOffset)
  mediaQuery = window.matchMedia('(prefers-color-scheme: dark)')
  mediaQuery.addEventListener('change', onSystemPrefChange)
  updateAdminBarOffset()
})

onBeforeUnmount(() => {
  window.removeEventListener(FC_THEME_EVENT, onFcThemeChange)
  window.removeEventListener('scroll', updateAdminBarOffset)
  window.removeEventListener('resize', updateAdminBarOffset)
  if (layoutFrame) window.cancelAnimationFrame(layoutFrame)
  if (mediaQuery) mediaQuery.removeEventListener('change', onSystemPrefChange)
})
</script>

<style>
#fchub-memberships-app {
  margin-left: -20px;
  margin-top: -10px;
}

.fchub-app-wrapper {
  min-height: calc(100vh - 32px);
  background: var(--fchub-page-bg);
}

.fchub-top-nav {
  position: fixed;
  top: var(--fchub-admin-bar-offset, 32px);
  left: 160px;
  right: 0;
  z-index: 2000;
  height: var(--fchub-nav-height);
  background: var(--fchub-card-bg);
  box-shadow: var(--fchub-nav-shadow);
}

/* WP collapsed sidebar */
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

.fchub-nav-links {
  display: flex;
  align-items: center;
  gap: 4px;
}

.fchub-mobile-nav {
  display: none;
}

.fchub-nav-link {
  display: inline-flex;
  align-items: center;
  padding: 6px 12px;
  font-size: 14px;
  font-weight: 400;
  color: var(--fchub-text-secondary);
  text-decoration: none;
  border-radius: 8px;
  transition: background-color 0.15s, color 0.15s;
}

.fchub-nav-link:hover {
  color: var(--fchub-text-primary);
  background: #F0F0F1;
}

body.dark .fchub-nav-link:hover,
body.dark .fchub-nav-link.active {
  background: #2a2e37;
}

.fchub-nav-link.active {
  color: var(--fchub-text-primary);
  background: #F0F0F1;
  font-weight: 500;
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

@media (max-width: 782px) {
  #fchub-memberships-app {
    margin-left: -10px;
  }

  .fchub-top-nav,
  .folded .fchub-top-nav {
    left: 0;
    height: 54px;
  }

  .fchub-nav-inner {
    padding: 0 16px;
  }

  .fchub-nav-left {
    min-width: 0;
    gap: 0;
  }

  .fchub-brand {
    font-size: 14px;
  }

  .fchub-nav-links,
  .fchub-nav-right .fchub-nav-link {
    display: none;
  }

  .fchub-brand span {
    display: none;
  }

  .fchub-mobile-nav {
    display: inline-flex;
    margin-left: 10px;
  }

  .fchub-mobile-nav-trigger {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    max-width: 180px;
    height: 34px;
    padding: 0 10px;
    border: 1px solid var(--fchub-border-color);
    border-radius: 9px;
    background: var(--fchub-page-bg);
    color: var(--fchub-text-primary);
    font: inherit;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
  }

  .fchub-content-area {
    padding: 16px;
    padding-top: 116px;
  }
}
</style>
