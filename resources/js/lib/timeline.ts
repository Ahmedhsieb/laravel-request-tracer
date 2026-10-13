import { formatMs } from './format'

/** A stretch of the request's time, in microseconds from its start. */
export interface Range {
  from: number
  to: number
}

/** The part of a step that is on screen, as percentages of the lane. */
export interface Bar {
  left: number
  width: number
  visible: boolean
  /** the step starts before, or ends after, the range */
  cutLeft: boolean
  cutRight: boolean
}

const MIN_WIDTH = 0.35

/** Where a moment sits in the range, in percent (below 0 or above 100 when it is outside). */
export function position(us: number, range: Range): number {
  const span = range.to - range.from

  return span <= 0 ? 0 : ((us - range.from) / span) * 100
}

/** The bar of a step in the range: clipped to the lane, never thinner than a hairline so that a quick step is still there. */
export function bar(start: number, duration: number, range: Range): Bar {
  const left = position(start, range)
  const right = position(start + duration, range)
  if (right < 0 || left > 100) return { left: 0, width: 0, visible: false, cutLeft: right < 0, cutRight: left > 100 }
  const from = Math.max(0, left)
  const to = Math.min(100, right)

  return { left: from, width: Math.max(MIN_WIDTH, to - from), visible: true, cutLeft: left < 0, cutRight: right > 100 }
}

/** A round step (1, 2, 2.5, 5 times a power of ten) that puts about `target` ticks on the range. */
export function niceStep(spanUs: number, target = 6): number {
  if (spanUs <= 0) return 1
  const rough = spanUs / target
  const magnitude = 10 ** Math.floor(Math.log10(rough))
  for (const factor of [1, 2, 2.5, 5, 10]) {
    if (factor * magnitude >= rough) return factor * magnitude
  }

  return 10 * magnitude
}

export interface Tick {
  us: number
  pct: number
  label: string
}

/** The ruler: round moments inside the range, with their place in percent and their label ("0", "50 ms", "1.2 s"). */
export function ticks(range: Range, target = 6): Tick[] {
  const span = range.to - range.from
  if (span <= 0) return []
  const step = niceStep(span, target)
  const out: Tick[] = []
  for (let us = Math.ceil(range.from / step) * step; us <= range.to + step * 1e-6; us += step) {
    out.push({ us, pct: position(us, range), label: us === 0 ? '0' : formatMs(us / 1000) })
  }

  return out
}

/** A brush dragged over the overview (two places in 0..1) as a range of the request, or null when it is too small to mean a zoom. */
export function rangeFromBrush(a: number, b: number, total: number): Range | null {
  const from = Math.max(0, Math.min(a, b))
  const to = Math.min(1, Math.max(a, b))
  if (to - from < 0.01) return null

  return { from: from * total, to: to * total }
}

export function sameRange(a: Range, b: Range): boolean {
  return Math.abs(a.from - b.from) < 1 && Math.abs(a.to - b.to) < 1
}
