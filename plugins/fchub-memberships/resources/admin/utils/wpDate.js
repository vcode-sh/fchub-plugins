const adminConfig = window.fchubMembershipsAdmin || {}

function resolveLocale(locale) {
  const normalized = String(locale || '').replace('_', '-').trim()
  if (!normalized) {
    return 'en-US'
  }

  try {
    new Intl.DateTimeFormat(normalized)
    return normalized
  } catch {
    return 'en-US'
  }
}

const wpLocale = resolveLocale(adminConfig.locale)
const wpDateFormat = adminConfig.date_format || 'Y-m-d'
const wpTimeFormat = adminConfig.time_format || 'H:i'
const weekdayShortFormatter = new Intl.DateTimeFormat(wpLocale, { weekday: 'short' })
const weekdayLongFormatter = new Intl.DateTimeFormat(wpLocale, { weekday: 'long' })
const monthShortFormatter = new Intl.DateTimeFormat(wpLocale, { month: 'short' })
const monthLongFormatter = new Intl.DateTimeFormat(wpLocale, { month: 'long' })

const dayjsTokenMap = {
  d: 'DD',
  D: 'ddd',
  j: 'D',
  l: 'dddd',
  F: 'MMMM',
  m: 'MM',
  M: 'MMM',
  n: 'M',
  Y: 'YYYY',
  y: 'YY',
  H: 'HH',
  G: 'H',
  h: 'hh',
  g: 'h',
  i: 'mm',
  s: 'ss',
  a: 'a',
  A: 'A',
}

function escapeDayjsLiteral(char) {
  return `[${char}]`
}

function parseDateInput(value) {
  if (!value) {
    return null
  }

  if (value instanceof Date) {
    return Number.isNaN(value.getTime()) ? null : value
  }

  if (typeof value === 'number') {
    const parsed = new Date(value)
    return Number.isNaN(parsed.getTime()) ? null : parsed
  }

  if (typeof value !== 'string') {
    return null
  }

  const input = value.trim()
  if (!input) {
    return null
  }

  if (/^\d{4}-\d{2}-\d{2}$/.test(input)) {
    const parsed = new Date(`${input}T00:00:00`)
    return Number.isNaN(parsed.getTime()) ? null : parsed
  }

  if (/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}(:\d{2})?$/.test(input)) {
    const parsed = new Date(input.replace(' ', 'T'))
    return Number.isNaN(parsed.getTime()) ? null : parsed
  }

  if (/^\d+$/.test(input)) {
    const num = Number(input)
    const parsed = new Date(input.length === 10 ? num * 1000 : num)
    return Number.isNaN(parsed.getTime()) ? null : parsed
  }

  const parsed = new Date(input)
  return Number.isNaN(parsed.getTime()) ? null : parsed
}

function pad(value, length = 2) {
  return String(value).padStart(length, '0')
}

function getDayOfYear(date) {
  const start = new Date(date.getFullYear(), 0, 1)
  const diff = date - start
  return Math.floor(diff / 86400000)
}

function getIsoDay(date) {
  const day = date.getDay()
  return day === 0 ? 7 : day
}

function getIsoWeek(date) {
  const temp = new Date(date.getTime())
  temp.setHours(0, 0, 0, 0)
  temp.setDate(temp.getDate() + 3 - getIsoDay(temp))

  const week1 = new Date(temp.getFullYear(), 0, 4)
  return 1 + Math.round((temp - week1) / 604800000)
}

function getIsoWeekYear(date) {
  const temp = new Date(date.getTime())
  temp.setDate(temp.getDate() + 3 - getIsoDay(temp))
  return temp.getFullYear()
}

function isLeapYear(year) {
  return (year % 4 === 0 && year % 100 !== 0) || year % 400 === 0
}

function getOrdinalSuffix(day) {
  if (day % 10 === 1 && day % 100 !== 11) return 'st'
  if (day % 10 === 2 && day % 100 !== 12) return 'nd'
  if (day % 10 === 3 && day % 100 !== 13) return 'rd'
  return 'th'
}

function weekdayShort(date) {
  return weekdayShortFormatter.format(date)
}

function weekdayLong(date) {
  return weekdayLongFormatter.format(date)
}

function monthShort(date) {
  return monthShortFormatter.format(date)
}

function monthLong(date) {
  return monthLongFormatter.format(date)
}

const tokenFormatters = {
  d: (date) => pad(date.getDate()),
  D: (date) => weekdayShort(date),
  j: (date) => String(date.getDate()),
  l: (date) => weekdayLong(date),
  N: (date) => String(getIsoDay(date)),
  S: (date) => getOrdinalSuffix(date.getDate()),
  w: (date) => String(date.getDay()),
  z: (date) => String(getDayOfYear(date)),
  W: (date) => pad(getIsoWeek(date)),
  F: (date) => monthLong(date),
  m: (date) => pad(date.getMonth() + 1),
  M: (date) => monthShort(date),
  n: (date) => String(date.getMonth() + 1),
  t: (date) => String(new Date(date.getFullYear(), date.getMonth() + 1, 0).getDate()),
  L: (date) => (isLeapYear(date.getFullYear()) ? '1' : '0'),
  o: (date) => String(getIsoWeekYear(date)),
  Y: (date) => String(date.getFullYear()),
  y: (date) => pad(date.getFullYear() % 100),
  a: (date) => (date.getHours() < 12 ? 'am' : 'pm'),
  A: (date) => (date.getHours() < 12 ? 'AM' : 'PM'),
  g: (date) => String((date.getHours() % 12) || 12),
  G: (date) => String(date.getHours()),
  h: (date) => pad((date.getHours() % 12) || 12),
  H: (date) => pad(date.getHours()),
  i: (date) => pad(date.getMinutes()),
  s: (date) => pad(date.getSeconds()),
  U: (date) => String(Math.floor(date.getTime() / 1000)),
}

export function wpToDayjsFormat(pattern) {
  if (!pattern) {
    return 'YYYY-MM-DD'
  }

  let output = ''
  let escaped = false

  for (let i = 0; i < pattern.length; i++) {
    const char = pattern[i]

    if (escaped) {
      output += escapeDayjsLiteral(char)
      escaped = false
      continue
    }

    if (char === '\\') {
      escaped = true
      continue
    }

    // PHP ordinal format is split as "jS" while dayjs uses "Do"
    if ((char === 'j' || char === 'd') && pattern[i + 1] === 'S') {
      output += 'Do'
      i++
      continue
    }

    if (char === 'S') {
      continue
    }

    const mapped = dayjsTokenMap[char]
    if (mapped) {
      output += mapped
      continue
    }

    if (/[A-Za-z]/.test(char)) {
      output += escapeDayjsLiteral(char)
      continue
    }

    output += char
  }

  return output || 'YYYY-MM-DD'
}

export function formatWpWithPattern(value, pattern, fallback = '-') {
  const date = parseDateInput(value)
  if (!date) {
    return fallback
  }

  if (!pattern) {
    return fallback
  }

  let output = ''
  let escaped = false

  for (const char of pattern) {
    if (escaped) {
      output += char
      escaped = false
      continue
    }

    if (char === '\\') {
      escaped = true
      continue
    }

    const formatter = tokenFormatters[char]
    output += formatter ? formatter(date) : char
  }

  return output
}

export function formatWpDate(value, fallback = '-') {
  return formatWpWithPattern(value, wpDateFormat, fallback)
}

export function formatWpTime(value, fallback = '-') {
  return formatWpWithPattern(value, wpTimeFormat, fallback)
}

export function formatWpDateTime(value, fallback = '-') {
  return formatWpWithPattern(value, `${wpDateFormat} ${wpTimeFormat}`, fallback)
}

export function formatReportPeriodLabel(value) {
  if (!value) {
    return ''
  }

  if (/^\d{4}-\d{2}$/.test(value)) {
    return formatWpWithPattern(`${value}-01`, 'M Y', value)
  }

  return formatWpDate(value, value)
}

export function getWeekdayNames() {
  const baseSunday = new Date(Date.UTC(2026, 0, 4))
  return Array.from({ length: 7 }, (_, index) => {
    const day = new Date(baseSunday)
    day.setUTCDate(baseSunday.getUTCDate() + index)
    return weekdayShort(day)
  })
}

export const wpDatePickerFormat = wpToDayjsFormat(wpDateFormat)
export const wpDateTimePickerFormat = wpToDayjsFormat(`${wpDateFormat} ${wpTimeFormat}`)
