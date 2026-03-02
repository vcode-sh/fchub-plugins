import { ref } from 'vue'

const toasts = ref([])
let nextId = 0

export function useNotifications() {
  const addToast = (message, type = 'info', description = null, duration = 5000) => {
    const id = nextId++
    const toast = {
      id,
      message,
      type, // 'success', 'error', 'warning', 'info'
      description,
    }

    toasts.value.push(toast)

    if (duration > 0) {
      setTimeout(() => {
        removeToast(id)
      }, duration)
    }

    return id
  }

  const removeToast = (id) => {
    const index = toasts.value.findIndex(t => t.id === id)
    if (index > -1) {
      toasts.value.splice(index, 1)
    }
  }

  const success = (message, description = null, duration = 4000) => {
    return addToast(message, 'success', description, duration)
  }

  const error = (message, description = null, duration = 7000) => {
    return addToast(message, 'error', description, duration)
  }

  const warning = (message, description = null, duration = 5000) => {
    return addToast(message, 'warning', description, duration)
  }

  const info = (message, description = null, duration = 4000) => {
    return addToast(message, 'info', description, duration)
  }

  return {
    toasts,
    addToast,
    removeToast,
    success,
    error,
    warning,
    info,
  }
}
