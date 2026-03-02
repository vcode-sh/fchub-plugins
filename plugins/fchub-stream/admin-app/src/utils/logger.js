/**
 * Logger utility that removes console.log in production builds
 * Uses Vite's import.meta.env.MODE to detect production
 */

const isDevelopment = import.meta.env.MODE === 'development' || import.meta.env.DEV

export const logger = {
  log: (...args) => {
    // Only log in development
    if (isDevelopment) {
      console.log(...args)
    }
  },
  error: (...args) => {
    // Always log errors, even in production
    console.error(...args)
  },
  warn: (...args) => {
    // Only log warnings in development
    if (isDevelopment) {
      console.warn(...args)
    }
  },
  info: (...args) => {
    // Only log info in development
    if (isDevelopment) {
      console.info(...args)
    }
  },
  debug: (...args) => {
    // Only log debug in development
    if (isDevelopment) {
      console.debug(...args)
    }
  },
}

