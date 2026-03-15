<template>
  <div class="drip-calendar-page">
    <div class="page-header">
      <div class="page-header-left">
        <a class="back-link" @click.prevent="$router.push('/drip')">
          <el-icon><ArrowLeft /></el-icon>
          Back to Drip
        </a>
        <h2 class="fchub-page-title">Drip Calendar</h2>
      </div>
    </div>

    <!-- Month Navigation -->
    <div class="month-nav">
      <el-button @click="prevMonth" :icon="ArrowLeft" circle />
      <span class="month-label">{{ monthLabel }}</span>
      <el-button @click="nextMonth" :icon="ArrowRight" circle />
      <el-button size="small" @click="goToday" style="margin-left: 12px">Today</el-button>
    </div>

    <!-- Calendar Grid -->
    <div class="calendar-wrapper" v-loading="calendarLoading">
      <div class="calendar-grid">
        <!-- Day of week headers -->
        <div
          v-for="dayName in dayNames"
          :key="dayName"
          class="calendar-header-cell"
        >
          {{ dayName }}
        </div>

        <!-- Day cells -->
        <div
          v-for="(day, index) in calendarDays"
          :key="index"
          class="calendar-day-cell"
          :class="{
            'other-month': !day.currentMonth,
            'is-today': day.isToday,
            'is-selected': day.dateStr === selectedDate,
            'has-unlocks': day.count > 0,
          }"
          @click="selectDay(day)"
        >
          <span class="day-number">{{ day.day }}</span>
          <span v-if="day.count > 0" class="unlock-count">
            {{ day.count }} unlock{{ day.count !== 1 ? 's' : '' }}
          </span>
        </div>
      </div>
    </div>

    <!-- Day Detail -->
    <el-card
      v-if="selectedDate"
      shadow="never"
      class="day-detail-card"
    >
      <template #header>
        <span>Unlocks for {{ formatSelectedDate }}</span>
      </template>

      <el-table
        v-loading="detailLoading"
        :data="dayUnlocks"
      >
        <el-table-column prop="user_email" label="User" min-width="200" />
        <el-table-column prop="content_title" label="Content Title" min-width="220" />
        <el-table-column prop="plan_title" label="Plan" min-width="160" />
        <el-table-column label="Scheduled Time" width="180">
          <template #default="{ row }">
            {{ formatTime(row.scheduled_at) }}
          </template>
        </el-table-column>
      </el-table>

      <el-empty v-if="!detailLoading && dayUnlocks.length === 0" description="No unlocks scheduled for this day" />
    </el-card>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { ArrowLeft, ArrowRight } from '@element-plus/icons-vue'
import { drip } from '@/api/index.js'
import { formatWpWithPattern, formatWpTime, getWeekdayNames } from '@/utils/wpDate.js'

const calendarLoading = ref(false)
const detailLoading = ref(false)

const currentYear = ref(new Date().getFullYear())
const currentMonth = ref(new Date().getMonth()) // 0-indexed
const selectedDate = ref(null)
const dayUnlocks = ref([])

const calendarData = ref({}) // { 'YYYY-MM-DD': count }

const dayNames = getWeekdayNames()

const monthLabel = computed(() => {
  const date = new Date(currentYear.value, currentMonth.value, 1)
  return formatWpWithPattern(date, 'F Y', '')
})

const formatSelectedDate = computed(() => {
  if (!selectedDate.value) return ''
  return formatWpWithPattern(selectedDate.value, 'l, F j, Y', '')
})

const calendarDays = computed(() => {
  const year = currentYear.value
  const month = currentMonth.value
  const firstDay = new Date(year, month, 1)
  const lastDay = new Date(year, month + 1, 0)
  const startDow = firstDay.getDay()

  const today = new Date()
  const todayStr = formatDateStr(today.getFullYear(), today.getMonth(), today.getDate())

  const days = []

  // Previous month fill
  if (startDow > 0) {
    const prevLastDay = new Date(year, month, 0).getDate()
    for (let i = startDow - 1; i >= 0; i--) {
      const d = prevLastDay - i
      const m = month - 1
      const y = m < 0 ? year - 1 : year
      const realM = m < 0 ? 11 : m
      const dateStr = formatDateStr(y, realM, d)
      days.push({
        day: d,
        dateStr,
        currentMonth: false,
        isToday: dateStr === todayStr,
        count: calendarData.value[dateStr] ?? 0,
      })
    }
  }

  // Current month
  for (let d = 1; d <= lastDay.getDate(); d++) {
    const dateStr = formatDateStr(year, month, d)
    days.push({
      day: d,
      dateStr,
      currentMonth: true,
      isToday: dateStr === todayStr,
      count: calendarData.value[dateStr] ?? 0,
    })
  }

  // Next month fill to complete final row
  const remaining = 7 - (days.length % 7)
  if (remaining < 7) {
    for (let d = 1; d <= remaining; d++) {
      const m = month + 1
      const y = m > 11 ? year + 1 : year
      const realM = m > 11 ? 0 : m
      const dateStr = formatDateStr(y, realM, d)
      days.push({
        day: d,
        dateStr,
        currentMonth: false,
        isToday: dateStr === todayStr,
        count: calendarData.value[dateStr] ?? 0,
      })
    }
  }

  return days
})

function formatDateStr(year, month, day) {
  const m = String(month + 1).padStart(2, '0')
  const d = String(day).padStart(2, '0')
  return `${year}-${m}-${d}`
}

function formatTime(dateStr) {
  return formatWpTime(dateStr)
}

function prevMonth() {
  if (currentMonth.value === 0) {
    currentMonth.value = 11
    currentYear.value--
  } else {
    currentMonth.value--
  }
  selectedDate.value = null
  dayUnlocks.value = []
  fetchCalendar()
}

function nextMonth() {
  if (currentMonth.value === 11) {
    currentMonth.value = 0
    currentYear.value++
  } else {
    currentMonth.value++
  }
  selectedDate.value = null
  dayUnlocks.value = []
  fetchCalendar()
}

function goToday() {
  const today = new Date()
  currentYear.value = today.getFullYear()
  currentMonth.value = today.getMonth()
  selectedDate.value = formatDateStr(today.getFullYear(), today.getMonth(), today.getDate())
  fetchCalendar()
  fetchDayDetail()
}

function selectDay(day) {
  selectedDate.value = day.dateStr
  fetchDayDetail()
}

async function fetchCalendar() {
  calendarLoading.value = true
  try {
    const from = formatDateStr(currentYear.value, currentMonth.value, 1)
    const lastDay = new Date(currentYear.value, currentMonth.value + 1, 0).getDate()
    const to = `${formatDateStr(currentYear.value, currentMonth.value, lastDay)} 23:59:59`
    const res = await drip.calendar({
      from: `${from} 00:00:00`,
      to,
    })
    calendarData.value = res.data ?? res ?? {}
  } catch {
    calendarData.value = {}
  } finally {
    calendarLoading.value = false
  }
}

async function fetchDayDetail() {
  if (!selectedDate.value) return
  detailLoading.value = true
  try {
    const res = await drip.queue({
      date: selectedDate.value,
      per_page: 100,
    })
    dayUnlocks.value = res.data ?? []
  } catch {
    dayUnlocks.value = []
  } finally {
    detailLoading.value = false
  }
}

onMounted(() => {
  fetchCalendar()
})
</script>

<style scoped>
.page-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 20px;
}

.page-header-left {
  display: flex;
  align-items: center;
  gap: 8px;
}

.back-link {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 13px;
  color: var(--fchub-text-secondary);
  text-decoration: none;
  cursor: pointer;
}

.back-link:hover {
  color: var(--el-color-primary);
}

.month-nav {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 20px;
}

.month-label {
  font-size: 18px;
  font-weight: 600;
  color: var(--fchub-text-primary);
  min-width: 180px;
  text-align: center;
}

.calendar-wrapper {
  margin-bottom: 20px;
}

.calendar-grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  border: 1px solid var(--fchub-border-color);
  border-radius: var(--fchub-radius-card);
  overflow: hidden;
}

.calendar-header-cell {
  padding: 10px 8px;
  text-align: center;
  font-weight: 600;
  font-size: 13px;
  color: var(--fchub-text-secondary);
  background: var(--el-fill-color-light, #f5f7fa);
  border-bottom: 1px solid var(--fchub-border-color);
}

.calendar-day-cell {
  min-height: 80px;
  padding: 8px;
  border-bottom: 1px solid var(--fchub-border-color);
  border-right: 1px solid var(--fchub-border-color);
  cursor: pointer;
  transition: background-color 0.15s;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.calendar-day-cell:hover {
  background-color: var(--el-fill-color-light, #f5f7fa);
}

.calendar-day-cell:nth-child(7n) {
  border-right: none;
}

.calendar-day-cell.other-month {
  color: var(--el-text-color-placeholder, #c0c4cc);
  background: var(--el-fill-color-lighter, #fafafa);
}

.calendar-day-cell.is-today .day-number {
  background: var(--fchub-stat-blue);
  color: #fff;
  border-radius: 50%;
  width: 26px;
  height: 26px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.calendar-day-cell.is-selected {
  background-color: rgba(77, 110, 245, 0.06);
}

.calendar-day-cell.has-unlocks {
  background-color: rgba(103, 194, 58, 0.08);
}

.calendar-day-cell.has-unlocks.is-selected {
  background-color: rgba(77, 110, 245, 0.1);
}

.day-number {
  font-size: 14px;
  font-weight: 500;
}

.unlock-count {
  font-size: 11px;
  color: var(--el-color-success);
  font-weight: 500;
}

.day-detail-card {
  margin-bottom: 20px;
}
</style>
