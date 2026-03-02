<template>
  <Teleport to="body">
    <div class="fixed bottom-4 right-4 z-50 space-y-1.5 max-w-xs">
      <TransitionGroup name="toast">
        <div
          v-for="toast in toasts"
          :key="toast.id"
          class="flex items-start gap-2 p-2.5 rounded-md shadow-md border"
          :class="toastClass(toast.type)"
        >
          <!-- Icon -->
          <component
            :is="toastIcon(toast.type)"
            class="w-4 h-4 flex-shrink-0"
            :class="toastIconClass(toast.type)"
          />

          <!-- Content -->
          <div class="flex-1 min-w-0">
            <p class="text-xs font-medium leading-tight m-0" :class="toastTextClass(toast.type)">
              {{ toast.message }}
            </p>
            <p v-if="toast.description" class="text-[10px] mt-0.5 leading-tight m-0" :class="toastDescClass(toast.type)">
              {{ toast.description }}
            </p>
          </div>

          <!-- Close button -->
          <button
            @click="removeToast(toast.id)"
            class="flex-shrink-0 hover:opacity-70 transition-opacity p-0.5"
            :class="toastTextClass(toast.type)"
          >
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
          </button>
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>

<script setup>
import { CheckCircleIcon, XCircleIcon, InformationCircleIcon, ExclamationTriangleIcon } from '@heroicons/vue/24/solid'

defineProps({
  toasts: {
    type: Array,
    required: true,
  },
})

const emit = defineEmits(['remove'])

const removeToast = (id) => {
  emit('remove', id)
}

const toastClass = (type) => {
  const classes = {
    success: 'bg-green-50 border-green-200',
    error: 'bg-red-50 border-red-200',
    warning: 'bg-yellow-50 border-yellow-200',
    info: 'bg-blue-50 border-blue-200',
  }
  return classes[type] || classes.info
}

const toastIcon = (type) => {
  const icons = {
    success: CheckCircleIcon,
    error: XCircleIcon,
    warning: ExclamationTriangleIcon,
    info: InformationCircleIcon,
  }
  return icons[type] || icons.info
}

const toastIconClass = (type) => {
  const classes = {
    success: 'text-green-600',
    error: 'text-red-600',
    warning: 'text-yellow-600',
    info: 'text-blue-600',
  }
  return classes[type] || classes.info
}

const toastTextClass = (type) => {
  const classes = {
    success: 'text-green-900',
    error: 'text-red-900',
    warning: 'text-yellow-900',
    info: 'text-blue-900',
  }
  return classes[type] || classes.info
}

const toastDescClass = (type) => {
  const classes = {
    success: 'text-green-700',
    error: 'text-red-700',
    warning: 'text-yellow-700',
    info: 'text-blue-700',
  }
  return classes[type] || classes.info
}
</script>

<style scoped>
.toast-enter-active,
.toast-leave-active {
  transition: all 0.3s ease;
}

.toast-enter-from {
  opacity: 0;
  transform: translateX(100%);
}

.toast-leave-to {
  opacity: 0;
  transform: translateX(100%);
}
</style>
