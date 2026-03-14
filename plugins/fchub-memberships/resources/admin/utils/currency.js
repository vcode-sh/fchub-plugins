const config = window.fchubMembershipsAdmin?.currency || {}
const symbol = config.symbol || '$'
const position = config.position || 'before'
const decimalSep = config.decimal_sep || '.'
const thousandSep = config.thousand_sep || ','

export function formatCurrency(amount) {
  if (amount == null || isNaN(amount)) return `${symbol}0.00`

  const num = Number(amount)
  const [intPart, decPart = '00'] = Math.abs(num).toFixed(2).split('.')
  const formattedInt = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, thousandSep)
  const formatted = `${formattedInt}${decimalSep}${decPart}`
  const signed = num < 0 ? `-${formatted}` : formatted

  if (position === 'after') return `${signed} ${symbol}`
  return `${symbol}${signed}`
}

export function currencySymbol() {
  return symbol
}

export function currencyTickFormatter(value) {
  if (position === 'after') return `${value} ${symbol}`
  return `${symbol}${value}`
}
