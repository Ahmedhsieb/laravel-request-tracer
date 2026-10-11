import { describe, expect, it } from 'vitest'
import { absoluteTime, formatBytes, formatMs, formatUs, parseDate, percent, plural, relativeTime, shortClass, shortFile } from './format'

describe('formatMs', () => {
  it.each([
    [0, '0 ms'], [0.4, '0.4 ms'], [1.234, '1.23 ms'], [12.34, '12.3 ms'], [184.4, '184 ms'], [999.6, '1000 ms'],
    [1000, '1 s'], [1240, '1.24 s'], [61_000, '1 min 1 s'], [Number.NaN, '–'],
  ])('%s -> %s', (input, expected) => {
    expect(formatMs(input)).toBe(expected)
  })

  it('formats microseconds', () => {
    expect(formatUs(1500)).toBe('1.5 ms')
  })
})

describe('formatBytes', () => {
  it.each([[512, '512 B'], [2048, '2 KB'], [5.5 * 1024 * 1024, '5.5 MB'], [3 * 1024 ** 3, '3 GB'], [-2048, '-2 KB']])('%s', (n, text) => {
    expect(formatBytes(n)).toBe(text)
  })
})

describe('relativeTime', () => {
  const now = new Date(2026, 9, 10, 12, 0, 0)
  const at = (secondsAgo: number): string => new Date(now.getTime() - secondsAgo * 1000).toISOString()

  it.each([[1, 'just now'], [30, '30 s ago'], [180, '3 min ago'], [7200, '2 h ago']])('%i s ago', (s, text) => {
    expect(relativeTime(at(s), now)).toBe(text)
  })

  it('switches to dates after a day or two', () => {
    expect(relativeTime(at(100_000), now)).toMatch(/^yesterday \d\d:\d\d$/)
    expect(relativeTime(at(400_000), now)).toMatch(/^\d{1,2} [A-Z][a-z]{2} \d\d:\d\d$/)
  })

  it('understands the server format and falls back to the raw text', () => {
    expect(parseDate('2026-10-10 11:59:00')).not.toBeNull()
    expect(relativeTime('2026-10-10 11:59:00', now)).toBe('1 min ago')
    expect(relativeTime('not a date', now)).toBe('not a date')
    expect(absoluteTime('2026-10-10 11:59:07')).toBe('2026-10-10 11:59:07')
  })
})

describe('small helpers', () => {
  it('pluralises', () => {
    expect(plural(1, 'problem')).toBe('1 problem')
    expect(plural(3, 'problem')).toBe('3 problems')
    expect(plural(0, 'query', 'queries')).toBe('0 queries')
  })

  it('shortens class names and file paths', () => {
    expect(shortClass('App\\Services\\OrderService')).toBe('OrderService')
    expect(shortClass('OrderService')).toBe('OrderService')
    expect(shortFile('app/Services/OrderService.php', 42)).toBe('app/Services/OrderService.php:42')
    expect(shortFile('/var/www/app/app/Services/OrderService.php', 42)).toBe('…/app/Services/OrderService.php:42')
    expect(shortFile(null, 1)).toBe('')
  })

  it('computes percentages safely', () => {
    expect(percent(25, 100)).toBe(25)
    expect(percent(1, 0)).toBe(0)
  })
})
