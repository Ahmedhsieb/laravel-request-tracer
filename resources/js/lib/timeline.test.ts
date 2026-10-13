import { describe, expect, it } from 'vitest'
import { bar, niceStep, position, rangeFromBrush, sameRange, ticks } from './timeline'

const range = { from: 0, to: 400_000 }

describe('position and bar', () => {
  it('places a moment in percent of the range', () => {
    expect(position(100_000, range)).toBe(25)
    expect(position(0, { from: 100_000, to: 300_000 })).toBe(-50)
  })

  it('draws a step as a bar, clipped to the lane', () => {
    expect(bar(100_000, 100_000, range)).toEqual({ left: 25, width: 25, visible: true, cutLeft: false, cutRight: false })
    expect(bar(300_000, 200_000, range)).toMatchObject({ left: 75, width: 25, cutRight: true })
    expect(bar(0, 500_000, { from: 100_000, to: 300_000 })).toMatchObject({ left: 0, width: 100, cutLeft: true, cutRight: true })
  })

  it('keeps a very quick step visible, and hides one outside the range', () => {
    expect(bar(10_000, 1, range).width).toBeGreaterThanOrEqual(0.35)
    expect(bar(500_000, 1_000, range).visible).toBe(false)
    expect(bar(0, 1_000, { from: 100_000, to: 300_000 }).visible).toBe(false)
  })
})

describe('ticks', () => {
  it('chooses a round step', () => {
    expect(niceStep(400_000, 6)).toBe(100_000)
    expect(niceStep(1_000_000, 5)).toBe(200_000)
    expect(niceStep(12_000, 6)).toBe(2_000)
    expect(niceStep(0)).toBe(1)
  })

  it('lists the round moments inside the range with their labels', () => {
    const list = ticks(range, 4)

    expect(list.map((t) => t.us)).toEqual([0, 100_000, 200_000, 300_000, 400_000])
    expect(list.map((t) => t.label)).toEqual(['0', '100 ms', '200 ms', '300 ms', '400 ms'])
    expect(list[2]?.pct).toBe(50)
  })

  it('starts at the first round moment after a zoomed range begins', () => {
    const list = ticks({ from: 120_000, to: 220_000 }, 4)

    expect(list[0]?.us).toBeGreaterThanOrEqual(120_000)
    expect(list.every((t) => t.pct >= 0 && t.pct <= 100.0001)).toBe(true)
  })
})

describe('brush', () => {
  it('turns two places on the overview into a range, in either direction', () => {
    expect(rangeFromBrush(0.25, 0.5, 400_000)).toEqual({ from: 100_000, to: 200_000 })
    expect(rangeFromBrush(0.5, 0.25, 400_000)).toEqual({ from: 100_000, to: 200_000 })
    expect(rangeFromBrush(-0.2, 1.4, 400_000)).toEqual({ from: 0, to: 400_000 })
  })

  it('ignores a click (a brush that is too small)', () => {
    expect(rangeFromBrush(0.4, 0.405, 400_000)).toBeNull()
  })

  it('compares ranges', () => {
    expect(sameRange({ from: 0, to: 10 }, { from: 0.4, to: 10.2 })).toBe(true)
    expect(sameRange({ from: 0, to: 10 }, { from: 5, to: 10 })).toBe(false)
  })
})
