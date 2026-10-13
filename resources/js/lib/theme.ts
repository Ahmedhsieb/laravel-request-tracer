import { useColorMode } from '@vueuse/core'

/**
 * Dark by default (a debugging tool is used for long stretches), light on request, remembered in the browser.
 * The `dark` class on <html> is what the stylesheet reads; the Blade view sets it before the first paint.
 */
export function useTheme() {
  const mode = useColorMode<'dark' | 'light'>({
    attribute: 'class',
    modes: { dark: 'dark', light: 'light' },
    initialValue: 'dark',
    storageKey: 'request-tracer:theme',
    disableTransition: true,
  })

  function toggle(): void {
    mode.value = mode.value === 'dark' ? 'light' : 'dark'
  }

  return { mode, toggle }
}
