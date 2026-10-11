import type { ProblemKind, SpanRow, SpanType, TraceSummary } from '../api/types'
import type { TreeNode } from './tree'

/** Display order of types in legends. */
export const TYPE_ORDER: SpanType[] = [
  'request', 'command', 'middleware', 'controller', 'validation', 'service', 'model', 'query', 'http', 'event', 'job', 'cache', 'log', 'exception',
]

export const TYPE_LABEL: Record<SpanType, string> = {
  request: 'Request', command: 'Command', middleware: 'Middleware', controller: 'Controller', validation: 'Validation',
  service: 'Service', query: 'Query', model: 'Model', http: 'HTTP', event: 'Event', job: 'Job', exception: 'Exception', cache: 'Cache', log: 'Log',
}

/** CSS variable that carries the colour of a type (defined in styles/app.css). */
export function typeVar(type: SpanType): string {
  return `var(--t-${type})`
}

export interface TimeSlice {
  type: SpanType
  us: number
  pct: number
}

/**
 * "Where the time went": the *self time* of every step, grouped by type, so nested steps are not counted twice
 * (a controller that spends 90 % of its time in a service shows that time under "service").
 */
export function timeByType(roots: TreeNode[], totalUs: number): TimeSlice[] {
  const sums = new Map<SpanType, number>()
  const visit = (node: TreeNode): void => {
    sums.set(node.span.type, (sums.get(node.span.type) ?? 0) + node.selfUs)
    node.children.forEach(visit)
  }
  roots.forEach(visit)

  const total = Math.max(totalUs, 1)
  const slices = [...sums.entries()]
    .filter(([, us]) => us > 0)
    .map(([type, us]) => ({ type, us, pct: (us / total) * 100 }))

  return slices.sort((a, b) => b.us - a.us)
}

/** The share of the whole request a step took, 0..100. */
export function shareOf(span: SpanRow, totalUs: number): number {
  return totalUs <= 0 ? 0 : Math.min(100, (span.duration_us / totalUs) * 100)
}

/** A one-line description shown under a step's label ("Returns Order #1057"). */
export function subtitle(span: SpanRow): string {
  const summary = span.meta?.['summary']
  if (typeof summary === 'string' && summary !== '') return summary
  switch (span.type) {
    case 'query': return String(span.meta?.['op'] ?? 'query').toUpperCase()
    case 'event': return 'Event'
    default: return ''
  }
}

export function flagText(span: SpanRow): string | null {
  if (span.count > 1) return `×${span.count}`
  switch (span.flag) {
    case 'slow': return 'slow'
    case 'repeated': return 'repeated'
    case 'caught': return 'caught'
    case 'failed': return 'failed'
    default: return null
  }
}

export interface ProblemText {
  title: string
  explanation: string
}

const PROBLEM_TITLES: Record<ProblemKind, string> = {
  exception_caught: 'Exception caught',
  exception_uncaught: 'Exception not caught',
  n_plus_one: 'N+1 query',
  repeated_query: 'Repeated query',
  slow_query: 'Slow query',
  slow_step: 'Slow step',
  http_failed: 'HTTP call failed',
  truncated: 'Trace truncated',
}

export function problemTitle(kind: ProblemKind): string {
  return PROBLEM_TITLES[kind] ?? kind
}

/** Chip text for the request list: "3 problems", "exception", "slow". */
export function problemNote(trace: TraceSummary, slowMs: number): { text: string; tone: 'bad' | 'warn' | 'muted' } | null {
  const kinds = trace.problem_kinds ?? []
  if (kinds.includes('exception_uncaught')) return { text: 'exception', tone: 'bad' }
  if (trace.problems_count > 1) return { text: `${trace.problems_count} problems`, tone: 'warn' }
  if (trace.problems_count === 1) {
    if (kinds.includes('exception_caught')) return { text: 'caught exception', tone: 'warn' }
    if (kinds.includes('slow_step') || kinds.includes('slow_query')) return { text: 'slow', tone: 'warn' }
    return { text: '1 problem', tone: 'warn' }
  }
  if (trace.duration_ms >= slowMs) return { text: 'slow', tone: 'warn' }
  return null
}

export function statusTone(status: number | null): 'ok' | 'info' | 'warn' | 'bad' | 'muted' {
  if (status === null) return 'muted'
  if (status >= 500) return 'bad'
  if (status >= 400) return 'warn'
  if (status >= 300) return 'info'
  return 'ok'
}

export function methodTone(method: string | null): string {
  switch ((method ?? '').toUpperCase()) {
    case 'GET': return 'get'
    case 'POST': return 'post'
    case 'PUT': case 'PATCH': return 'put'
    case 'DELETE': return 'delete'
    default: return 'other'
  }
}
