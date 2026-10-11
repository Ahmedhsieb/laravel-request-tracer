import { describe, expect, it } from 'vitest'
import type { SpanRow } from '../api/types'
import { ancestorIds, buildTree, defaultExpanded, findNode, searchIds, visibleRows } from './tree'
import { timeByType } from './spans'

function span(id: number, parent: number | null, over: Partial<SpanRow> = {}): SpanRow {
  return {
    id, parent_id: parent, type: 'service', label: `step ${id}`, file: null, line: null, start_us: 0, duration_us: 1000,
    memory_delta: 0, flag: null, connection: null, count: 1, meta: null, has_input: false, has_output: false, ...over,
  }
}

//  1 request
//  ├─ 2 middleware
//  │   └─ 3 controller
//  │       └─ 4 service
//  │           ├─ 5 query
//  │           └─ 6 http
//  └─ 7 log
const spans = [
  span(1, null, { type: 'request', duration_us: 10_000 }),
  span(2, 1, { type: 'middleware', duration_us: 9000 }),
  span(3, 2, { type: 'controller', duration_us: 8000 }),
  span(4, 3, { type: 'service', duration_us: 6000 }),
  span(5, 4, { type: 'query', duration_us: 2000, label: 'select * from orders' }),
  span(6, 4, { type: 'http', duration_us: 3000 }),
  span(7, 1, { type: 'log', duration_us: 0 }),
]

describe('buildTree', () => {
  const roots = buildTree(spans)

  it('nests steps by parent and records depth, descendants and self time', () => {
    expect(roots).toHaveLength(1)
    const service = findNode(roots, 4)!
    expect(service.depth).toBe(3)
    expect(service.descendants).toBe(2)
    expect(service.selfUs).toBe(1000)            // 6000 - 2000 - 3000
    expect(findNode(roots, 1)!.descendants).toBe(6)
  })

  it('marks the last child for the elbow connector', () => {
    expect(findNode(roots, 7)!.last).toBe(true)
    expect(findNode(roots, 2)!.last).toBe(false)
  })

  it('never lets self time go negative (collapsed or overlapping steps)', () => {
    const odd = buildTree([span(1, null, { duration_us: 100 }), span(2, 1, { duration_us: 500 })])
    expect(odd[0]!.selfUs).toBe(0)
  })

  it('treats a step whose parent is unknown as a root', () => {
    expect(buildTree([span(1, 99)])).toHaveLength(1)
  })
})

describe('collapsing', () => {
  const roots = buildTree(spans)

  it('opens the first levels and collapses branches deeper than depth 2', () => {
    const open = defaultExpanded(roots)
    expect([...open].sort()).toEqual([1, 2])
    const rows = visibleRows(roots, open)
    expect(rows.map((r) => r.node.span.id)).toEqual([1, 2, 3, 7])           // controller is visible but closed
  })

  it('expands on demand and computes connector guides', () => {
    const open = new Set([1, 2, 3, 4])
    const rows = visibleRows(roots, open)
    expect(rows.map((r) => r.node.span.id)).toEqual([1, 2, 3, 4, 5, 6, 7])
    expect(rows.find((r) => r.node.span.id === 5)!.guides).toEqual([false, true, false, false])
  })

  it('finds the ancestors to open for a step', () => {
    expect(ancestorIds(roots, 5).sort()).toEqual([1, 2, 3, 4])
    expect(ancestorIds(roots, 1)).toEqual([])
  })
})

describe('search', () => {
  const roots = buildTree(spans)

  it('keeps matches and their ancestors only', () => {
    const keep = searchIds(roots, 'ORDERS')!
    expect([...keep].sort()).toEqual([1, 2, 3, 4, 5])
    expect(visibleRows(roots, new Set(), keep).map((r) => r.node.span.id)).toEqual([1, 2, 3, 4, 5])
  })

  it('returns null for an empty query', () => {
    expect(searchIds(roots, '   ')).toBeNull()
  })
})

describe('timeByType', () => {
  it('groups self time by type so nested steps are not counted twice', () => {
    const roots = buildTree(spans)
    const slices = timeByType(roots, 10_000)
    const by = Object.fromEntries(slices.map((s) => [s.type, s.us]))
    expect(by['http']).toBe(3000)
    expect(by['query']).toBe(2000)
    expect(by['service']).toBe(1000)
    expect(by['request']).toBe(1000)             // 10000 - 9000
    expect(slices.reduce((sum, s) => sum + s.us, 0)).toBeLessThanOrEqual(10_000)
    expect(slices[0]!.type).toBe('http')          // biggest first
  })
})
