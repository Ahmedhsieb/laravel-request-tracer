import { afterEach, describe, expect, it, vi } from 'vitest'
import { copyText } from './clipboard'

function setClipboard(value: unknown, secure: boolean): void {
  Object.defineProperty(navigator, 'clipboard', { value, configurable: true })
  Object.defineProperty(window, 'isSecureContext', { value: secure, configurable: true })
}

afterEach(() => {
  setClipboard(undefined, false)
  vi.restoreAllMocks()
})

describe('copyText', () => {
  it('uses the clipboard API on a secure page', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined)
    setClipboard({ writeText }, true)

    expect(await copyText('php artisan request-tracer:listen')).toBe(true)
    expect(writeText).toHaveBeenCalledWith('php artisan request-tracer:listen')
  })

  it('falls back to the copy command where there is no clipboard API (a plain http address)', async () => {
    setClipboard(undefined, false)
    const exec = vi.fn(() => true)
    Object.defineProperty(document, 'execCommand', { value: exec, configurable: true })

    expect(await copyText('hello')).toBe(true)
    expect(exec).toHaveBeenCalledWith('copy')
    expect(document.querySelector('textarea')).toBeNull()            // the helper field is removed again
  })

  it('falls back when the API refuses', async () => {
    setClipboard({ writeText: vi.fn().mockRejectedValue(new Error('denied')) }, true)
    const exec = vi.fn(() => true)
    Object.defineProperty(document, 'execCommand', { value: exec, configurable: true })

    expect(await copyText('x')).toBe(true)
  })

  it('says so when nothing works', async () => {
    setClipboard(undefined, false)
    Object.defineProperty(document, 'execCommand', { value: vi.fn(() => false), configurable: true })

    expect(await copyText('x')).toBe(false)
  })
})
