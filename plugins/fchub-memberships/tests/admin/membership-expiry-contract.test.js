import { describe, expect, it } from 'vitest'
import { toExpiryTimestamp } from '@/utils/wpDate.js'

/**
 * Every membership form picks a day; the REST layer accepts only `Y-m-d H:i:s`.
 * These cases mirror `MembershipRestArguments::isoMysqlDate()`, which rebuilds
 * the value with `createFromFormat('!Y-m-d H:i:s')` and demands an exact match.
 * The PHP side of this contract is pinned in MembershipExpiryContractTest.
 */
const ACCEPTED_BY_SERVER = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/

describe('expiry values the membership API will accept', () => {
  it('converts what the date picker emits into what the server parses', () => {
    expect(toExpiryTimestamp('2027-01-01')).toMatch(ACCEPTED_BY_SERVER)
  })

  it('gives the member the whole of the day that was picked', () => {
    expect(toExpiryTimestamp('2027-01-01')).toBe('2027-01-01 23:59:59')
  })

  it('leaves a value that already carries seconds untouched', () => {
    expect(toExpiryTimestamp('2026-12-01 08:30:00')).toBe('2026-12-01 08:30:00')
  })

  it('completes a value that stops at minutes', () => {
    expect(toExpiryTimestamp('2026-12-01 08:30')).toBe('2026-12-01 08:30:00')
  })

  it('normalises the ISO separator a picker may emit', () => {
    expect(toExpiryTimestamp('2026-12-01T08:30:00')).toBe('2026-12-01 08:30:00')
  })

  it('keeps an unset expiry unset rather than inventing a date', () => {
    expect(toExpiryTimestamp('')).toBe('')
    expect(toExpiryTimestamp(null)).toBe('')
    expect(toExpiryTimestamp(undefined)).toBe('')
  })

  it('produces a server-acceptable value for every leap and month boundary', () => {
    for (const day of ['2028-02-29', '2027-12-31', '2027-01-01', '2027-11-30']) {
      expect(toExpiryTimestamp(day)).toMatch(ACCEPTED_BY_SERVER)
    }
  })
})
