import { ref, onMounted, onBeforeUnmount } from 'vue';

const STORAGE_KEY = 'fcart_admin_theme';
const FC_THEME_EVENT = 'onFluentCartThemeChange';
const DARK_TARGETS = ['body', '#wpbody-content', '.wp-toolbar', '#wpfooter'];

export function useTheme() {
  const themeMode = ref('system');

  function getSystemTheme() {
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  function readSavedMode() {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return 'system';
    if (raw === 'light' || raw === 'dark') return raw;
    if (raw.startsWith('system')) return 'system';
    return 'system';
  }

  function applyDark(isDark) {
    DARK_TARGETS.forEach((sel) => {
      const el = sel === 'body' ? document.body : document.querySelector(sel);
      if (el) el.classList.toggle('dark', isDark);
    });
  }

  function applyTheme(mode) {
    themeMode.value = mode;
    const resolved = mode === 'system' ? getSystemTheme() : mode;
    applyDark(resolved === 'dark');

    if (mode === 'system') {
      localStorage.setItem(STORAGE_KEY, `system:${resolved}`);
    } else {
      localStorage.setItem(STORAGE_KEY, mode);
    }
  }

  function changeTheme(mode) {
    applyTheme(mode);
    window.dispatchEvent(
      new CustomEvent(FC_THEME_EVENT, {
        detail: { theme: mode === 'system' ? getSystemTheme() : mode },
      })
    );
  }

  function onFcThemeChange() {
    themeMode.value = readSavedMode();
    const resolved = themeMode.value === 'system' ? getSystemTheme() : themeMode.value;
    applyDark(resolved === 'dark');
  }

  function onSystemPrefChange() {
    if (themeMode.value === 'system') {
      applyTheme('system');
    }
  }

  let mediaQuery;

  onMounted(() => {
    themeMode.value = readSavedMode();
    applyTheme(themeMode.value);

    window.addEventListener(FC_THEME_EVENT, onFcThemeChange);
    mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
    mediaQuery.addEventListener('change', onSystemPrefChange);
  });

  onBeforeUnmount(() => {
    window.removeEventListener(FC_THEME_EVENT, onFcThemeChange);
    if (mediaQuery) mediaQuery.removeEventListener('change', onSystemPrefChange);
  });

  return { themeMode, changeTheme };
}
