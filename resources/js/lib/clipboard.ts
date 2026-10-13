/**
 * Copy text to the clipboard. `navigator.clipboard` only exists on a secure page (https or localhost): a dashboard opened on
 * `http://app.test` or a LAN address has none, so the fallback selects a hidden field and uses the old `copy` command, which works there.
 */
export async function copyText(text: string): Promise<boolean> {
  try {
    if (typeof navigator !== 'undefined' && navigator.clipboard !== undefined && window.isSecureContext) {
      await navigator.clipboard.writeText(text)

      return true
    }
  } catch {
    // refused (no permission, or the page is not focused): try the fallback
  }

  return legacyCopy(text)
}

function legacyCopy(text: string): boolean {
  if (typeof document === 'undefined') return false
  const field = document.createElement('textarea')
  field.value = text
  field.setAttribute('readonly', '')
  field.setAttribute('aria-hidden', 'true')
  field.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;opacity:0;pointer-events:none'
  const previous = document.activeElement instanceof HTMLElement ? document.activeElement : null
  // a modal dialog traps focus, so the field goes inside the open one, otherwise it could not be focused
  const host = document.querySelector('[role="dialog"][data-state="open"]') ?? document.body
  host.appendChild(field)
  try {
    field.focus({ preventScroll: true })
    field.select()
    field.setSelectionRange(0, text.length)

    return document.execCommand('copy')
  } catch {
    return false
  } finally {
    field.remove()
    previous?.focus({ preventScroll: true })
  }
}
