import type { SpanRow, SpanType } from '../api/types'
import type { TreeNode } from './tree'

export type SpeedFilter = 'all' | 'slow' | 'fast'
export type ResultFilter = 'all' | 'failed' | 'ok'

/** What the filter bar above the steps holds: nothing selected means "show everything". */
export interface StepFilter {
  query: string
  types: ReadonlySet<SpanType>
  speed: SpeedFilter
  result: ResultFilter
}

export const emptyFilter = (): StepFilter => ({ query: '', types: new Set(), speed: 'all', result: 'all' })

export function isFiltering(filter: StepFilter): boolean {
  return filter.query.trim() !== '' || filter.types.size > 0 || filter.speed !== 'all' || filter.result !== 'all'
}

/** A step is slow when the analysis flagged it, or it took at least `slowMs` (`dashboard.slow_step_ms`). */
export function isSlow(span: SpanRow, slowMs: number): boolean {
  return span.flag === 'slow' || span.duration_us >= slowMs * 1000
}

/** A step failed when it threw, was caught after throwing, or its HTTP call answered with an error status. */
export function isFailed(span: SpanRow): boolean {
  if (span.flag === 'failed' || span.flag === 'caught' || span.type === 'exception') return true
  const status = span.meta?.['status']

  return typeof status === 'number' && status >= 400
}

function haystack(span: SpanRow): string {
  return `${span.label} ${span.file ?? ''} ${String(span.meta?.['summary'] ?? '')} ${span.connection ?? ''} ${span.type}`.toLowerCase()
}

export function matches(span: SpanRow, filter: StepFilter, slowMs: number): boolean {
  if (filter.types.size > 0 && !filter.types.has(span.type)) return false
  if (filter.speed !== 'all' && isSlow(span, slowMs) !== (filter.speed === 'slow')) return false
  if (filter.result !== 'all' && isFailed(span) !== (filter.result === 'failed')) return false
  const q = filter.query.trim().toLowerCase()

  return q === '' || haystack(span).includes(q)
}

/**
 * The steps that match the filter plus all their ancestors (so a match stays reachable in the tree), or null when no filter is on.
 * `matched` is the subset that matches itself: the others are only context and are shown dimmed.
 */
export function filterSteps(roots: TreeNode[], filter: StepFilter, slowMs: number): { keep: Set<number>; matched: Set<number> } | null {
  if (!isFiltering(filter)) return null
  const keep = new Set<number>()
  const matched = new Set<number>()
  const visit = (node: TreeNode): boolean => {
    const own = matches(node.span, filter, slowMs)
    let any = own
    for (const child of node.children) any = visit(child) || any
    if (own) matched.add(node.span.id)
    if (any) keep.add(node.span.id)

    return any
  }
  roots.forEach(visit)

  return { keep, matched }
}

/** The types that occur in the trace with how many steps each has, in the given display order. */
export function typeCounts(roots: TreeNode[], order: SpanType[]): Array<{ type: SpanType; count: number }> {
  const counts = new Map<SpanType, number>()
  const visit = (node: TreeNode): void => {
    counts.set(node.span.type, (counts.get(node.span.type) ?? 0) + 1)
    node.children.forEach(visit)
  }
  roots.forEach(visit)

  return order.filter((type) => counts.has(type)).map((type) => ({ type, count: counts.get(type) ?? 0 }))
}
