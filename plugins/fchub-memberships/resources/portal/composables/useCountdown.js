import { ref, computed, watch, onUnmounted, toValue } from 'vue'

export function useCountdown(targetDate) {
  const now = ref(Date.now())
  let timer = null

  function startTimer() {
    stopTimer()
    timer = setInterval(() => {
      now.value = Date.now()
    }, 1000)
  }

  function stopTimer() {
    if (timer) {
      clearInterval(timer)
      timer = null
    }
  }

  const target = computed(() => {
    const val = toValue(targetDate)
    if (!val) return 0
    return new Date(val).getTime()
  })

  const diff = computed(() => Math.max(0, target.value - now.value))
  const isExpired = computed(() => diff.value <= 0)

  const days = computed(() => Math.floor(diff.value / 86400000))
  const hours = computed(() => Math.floor((diff.value % 86400000) / 3600000))
  const minutes = computed(() => Math.floor((diff.value % 3600000) / 60000))
  const seconds = computed(() => Math.floor((diff.value % 60000) / 1000))

  const label = computed(() => {
    if (isExpired.value) return 'Available now'
    if (days.value > 0) return `${days.value}d ${hours.value}h`
    if (hours.value > 0) return `${hours.value}h ${minutes.value}m`
    if (minutes.value > 0) return `${minutes.value}m ${seconds.value}s`
    return `${seconds.value}s`
  })

  watch(
    target,
    (val) => {
      if (val > Date.now()) {
        startTimer()
      } else {
        stopTimer()
      }
    },
    { immediate: true },
  )

  onUnmounted(stopTimer)

  return {
    days,
    hours,
    minutes,
    seconds,
    isExpired,
    label,
  }
}
