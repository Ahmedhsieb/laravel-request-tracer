import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

const css = readFileSync(join(process.cwd(), 'resources', 'js', 'styles', 'app.css'), 'utf8')

function token(name: string): string {
  const match = new RegExp(`--${name}:\\s*(#[0-9a-fA-F]{6})`).exec(css)
  if (match === null) throw new Error(`token --${name} not found as a hex colour`)
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

const backgrounds = ['bg', 'panel', 'panel-2', 'panel-3']
const foregrounds = ['text', 'muted', 'faint', 'accent', 'orange', 'red', 'green', 'blue', 'purple', 'yellow', 'cyan', 'pink', 'grey', 'focus']

describe('colour contrast (WCAG AA, 4.5:1 for text)', () => {
  for (const bg of backgrounds) {
    for (const fg of foregrounds) {
      it(`--${fg} on --${bg}`, () => {
        expect(contrast(token(fg), token(bg))).toBeGreaterThanOrEqual(4.5)
      })
    }
  }

  it('dark text on the primary button', () => {
    expect(contrast('#0b1d18', token('accent'))).toBeGreaterThanOrEqual(4.5)
  })

  it('the span type colours that are literals are readable too', () => {
    for (const hex of ['#e3b07a', '#7dc4f0', '#b7c0d0']) {
      expect(contrast(hex, token('panel'))).toBeGreaterThanOrEqual(4.5)
    }
  })
})

describe('accessibility rules of the stylesheet', () => {
  it('defines a 44px hit target and uses it for buttons, chips and tabs', () => {
    expect(css).toContain('--hit: 44px;')
    for (const selector of ['.btn {', '.chip {', '.tab {']) {
      const block = css.slice(css.indexOf(selector), css.indexOf('}', css.indexOf(selector)))
      expect(block).toContain('var(--hit)')
    }
  })

  it('has a visible focus ring', () => {
    expect(css).toMatch(/:focus-visible\s*{\s*outline:\s*2px solid var\(--focus\)/)
  })

  it('switches every animation and transition off under prefers-reduced-motion', () => {
    const reduce = css.slice(css.indexOf('@media (prefers-reduced-motion: reduce)'))
    expect(reduce).toContain('animation: none !important')
    expect(reduce).toContain('transition: none !important')
    // animations are only *defined* for users who did not ask for less motion
    const noPreference = css.slice(css.indexOf('@media (prefers-reduced-motion: no-preference)'), css.indexOf('@media (prefers-reduced-motion: reduce)'))
    expect(noPreference).toContain('@keyframes fade-up')
    expect(css.slice(0, css.indexOf('@media (prefers-reduced-motion: no-preference)'))).not.toContain('@keyframes')
  })
})
