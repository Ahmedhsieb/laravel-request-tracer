import type { TraceSummary } from '../api/types'

/** A segment that is a record id, not a part of the route: 42, a UUID, a ULID, a long hex string. */
export function isIdSegment(segment: string): boolean {
  return /^\d+$/.test(segment)
    || /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(segment)
    || /^[0-9A-HJKMNP-TV-Z]{26}$/.test(segment)
    || /^[0-9a-f]{16,}$/i.test(segment)
}

/**
 * The group a trace belongs to: the first `depth` segments of its route, up to the first record id
 * (`/api/payments/vouchers/12/edit` at depth 2 is `/api/payments`). Jobs, commands and scheduled tasks group by kind.
 */
export function routeGroup(trace: Pick<TraceSummary, 'kind' | 'path'>, depth: number): string {
  if (trace.kind !== 'request') return trace.kind === 'job' ? 'Jobs' : trace.kind === 'command' ? 'Commands' : 'Scheduled tasks'
  const segments = (trace.path ?? '/').split('?')[0]!.split('/').filter((s) => s !== '')
  const fixed: string[] = []
  for (const segment of segments) {
    if (isIdSegment(segment) || fixed.length >= Math.max(1, depth)) break
    fixed.push(segment)
  }

  return fixed.length === 0 ? '/' : `/${fixed.join('/')}`
}

export interface RouteGroup {
  key: string
  traces: TraceSummary[]
  /** the worst thing in the group, for the header's colour */
  worst: 'bad' | 'warn' | 'ok'
  slowestMs: number
  problems: number
}

/** Group traces, keeping the order of the list: a group sits where its newest trace is. */
export function groupTraces(traces: TraceSummary[], depth: number): RouteGroup[] {
  const groups = new Map<string, RouteGroup>()
  for (const trace of traces) {
    const key = routeGroup(trace, depth)
    let group = groups.get(key)
    if (group === undefined) {
      group = { key, traces: [], worst: 'ok', slowestMs: 0, problems: 0 }
      groups.set(key, group)
    }
    group.traces.push(trace)
    group.slowestMs = Math.max(group.slowestMs, trace.duration_ms)
    group.problems += trace.problems_count
    if (trace.status !== null && trace.status >= 500) group.worst = 'bad'
    else if (group.worst !== 'bad' && ((trace.status !== null && trace.status >= 400) || trace.problems_count > 0)) group.worst = 'warn'
  }

  return [...groups.values()]
}
