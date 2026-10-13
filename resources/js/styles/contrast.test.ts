import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

const css = readFileSync(join(process.cwd(), 'resources', 'js', 'styles', 'app.css'), 'utf8')

/** The declarations of one theme block (`:root {` is light, `.dark {` is dark). */
function block(selector: string): string {
  const start = css.indexOf(`\n${selector} {`)
  if (start === -1) throw new Error(`theme block ${selector} not found`)
  return css.slice(start, css.indexOf('\n}', start))
}

function token(theme: string, name: string): string {
  const match = new RegExp(`--${name}:\\s*(#[0-9a-fA-F]{6})(?![0-9a-fA-F])`).exec(block(theme))
  if (match === null) throw new Error(`token --${name} not found as a hex colour in ${theme}`)
  return match[1] as string
}

function luminance(hex: string): number {
  const channel = (i: number): number => {
    const c = parseInt(hex.slice(1 + i * 2, 3 + i * 2), 16) / 255
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
  }
  return 0.2126 * channel(0) + 0.7152 * channel(1) + 0.0722 * channel(2)
}

function contrast(a: string, b: string): number {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x) as [number, number]
  return (hi + 0.05) / (lo + 0.05)
}

const themes = [':root', '.dark']
// the panels are translucent glass over `bg`; text sits on one of these three
const backgrounds = ['bg', 'surface', 'raised']
const texts = ['fg', 'muted', 'faint', 'accent-ink', 'ok', 'warn', 'bad', 'info']
const types = ['request', 'command', 'middleware', 'controller', 'validation', 'service', 'model', 'query', 'http', 'event', 'job', 'cache', 'log', 'exception']

describe('colour contrast (WCAG AA, 4.5:1 for text), light and dark', () => {
  for (const theme of themes) {
    for (const bg of backgrounds) {
      for (const fg of [...texts, ...types.map((t) => `t-${t}`)]) {
        it(`${theme}: --${fg} on --${bg}`, () => {
          expect(contrast(token(theme, fg), token(theme, bg))).toBeGreaterThanOrEqual(4.5)
        })
      }
    }
  }

  it('the text of the primary button is readable on both ends of its gradient', () => {
    for (const theme of themes) {
      for (const end of ['accent', 'accent-2']) {
        expect(contrast('#04141c', token(theme, end))).toBeGreaterThanOrEqual(4.5)
      }
    }
  })
})

describe('accessibility rules of the stylesheet', () => {
  it('has a visible focus ring', () => {
    expect(css).toMatch(/:focus-visible\s*{\s*outline:\s*2px solid var\(--accent\)/)
  })

  it('shortens every animation and transition under prefers-reduced-motion', () => {
    const reduce = css.slice(css.indexOf('@media (prefers-reduced-motion: reduce)'))
    expect(reduce).toContain('animation-duration: 0.01ms !important')
    expect(reduce).toContain('transition-duration: 0.01ms !important')
  })

  it('defines both themes', () => {
    expect(css).toContain('color-scheme: light;')
    expect(css).toContain('color-scheme: dark;')
  })
})
