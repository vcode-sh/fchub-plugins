/**
 * Logger utility for portal-app
 * 
 * Checks both Vite's import.meta.env.MODE and WordPress WP_DEBUG setting
 * Only logs in development mode or when WP_DEBUG is enabled
 */

function isDebugEnabled() {
  // Check Vite environment (development mode)
  const isViteDev = import.meta.env.MODE === 'development' || import.meta.env.DEV
  
  // Check WordPress WP_DEBUG setting from portal vars
  const settings = window.fchubStreamSettings || 
                   window.fcom_portal_general?.fchubStreamSettings ||
                   window.fluentComAdmin?.fchubStreamSettings ||
                   {}
  const isWpDebug = settings.debug === true || settings.debug === '1'
  
  return isViteDev || isWpDebug
}

const isDevelopment = isDebugEnabled()

export const logger = {
  log: (...args) => {
    // Only log in development or when WP_DEBUG is enabled
    if (isDevelopment) {
      console.log(...args)
    }
  },
  error: (...args) => {
    // Always log errors, even in production
    console.error(...args)
  },
  warn: (...args) => {
    // Only log warnings in development or when WP_DEBUG is enabled
    if (isDevelopment) {
      console.warn(...args)
    }
  },
  info: (...args) => {
    // Only log info in development or when WP_DEBUG is enabled
    if (isDevelopment) {
      console.info(...args)
    }
  },
  debug: (...args) => {
    // Only log debug in development or when WP_DEBUG is enabled
    if (isDevelopment) {
      console.debug(...args)
    }
  },
}

