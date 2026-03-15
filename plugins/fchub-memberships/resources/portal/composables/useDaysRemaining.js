import { computed, toValue } from 'vue'

export function useDaysRemaining(expiresAt) {
  const daysLeft = computed(() => {
    const val = toValue(expiresAt)
    if (!val) return null
    const diff = new Date(val).getTime() - Date.now()
    return Math.ceil(diff / 86400000)
  })

  const isExpiringSoon = computed(() => {
    if (daysLeft.value === null) return false
    return daysLeft.value >= 0 && daysLeft.value < 7
  })

  const label = computed(() => {
    if (daysLeft.value === null) return ''
    if (daysLeft.value < 0) return 'Expired'
    if (daysLeft.value === 0) return 'Expires today!'
    if (daysLeft.value === 1) return '1 day left'
    return `${daysLeft.value} days left`
  })

  return {
    daysLeft,
    label,
    isExpiringSoon,
  }
}
