import { describe, expect, it } from 'vitest'
import type { SpanRow } from '../api/types'
import { emptyFilter, type StepFilter } from './filters'
import { below, crumbs, folderCount, folderRange, graphColumns, isFolder, listFolder, litSteps, scopeNodes, scopeTypeCounts } from './folder'
import { buildTree, findNode } from './tree'

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
//  │           ├─ 5 query "select * from orders"
//  │           └─ 6 http
//  └─ 7 log
const roots = buildTree([
  span(1, null, { type: 'request', duration_us: 10_000 }),
  span(2, 1, { type: 'middleware', start_us: 500, duration_us: 9000 }),
  span(3, 2, { type: 'controller', start_us: 1000, duration_us: 8000 }),
  span(4, 3, { type: 'service', start_us: 2000, duration_us: 6000 }),
  span(5, 4, { type: 'query', start_us: 2100, duration_us: 2000, label: 'select * from orders' }),
  span(6, 4, { type: 'http', start_us: 4200, duration_us: 3000, flag: 'failed' }),
  span(7, 1, { type: 'log', start_us: 9500, duration_us: 0 }),
])
const node = (id: number) => findNode(roots, id)!
const filter = (over: Partial<StepFilter>): StepFilter => ({ ...emptyFilter(), ...over })
const ids = (list: { entries: Array<{ node: { span: { id: number } } }> }) => list.entries.map((e) => e.node.span.id)

describe('the open folder', () => {
  it('lists the children of a folder, and the roots at the top', () => {
    expect(ids(listFolder(roots, node(4), emptyFilter(), 100, true))).toEqual([5, 6])
    expect(ids(listFolder(roots, null, emptyFilter(), 100, true))).toEqual([1])
    expect(ids(listFolder(roots, node(1), emptyFilter(), 100, true))).toEqual([2, 7])
  })

  it('knows the path to a step and what is a folder', () => {
    expect(crumbs(node(5)).map((n) => n.span.id)).toEqual([1, 2, 3, 4, 5])
    expect(crumbs(null)).toEqual([])
    expect(isFolder(node(4))).toBe(true)
    expect(isFolder(node(5))).toBe(false)
    expect(folderCount(node(1), roots)).toBe(1)
  })

  it('counts the steps the filters look at: everything below, or the children', () => {
    expect(below(roots, node(2))).toHaveLength(4)
    expect(scopeNodes(roots, node(2), true)).toHaveLength(4)
    expect(scopeNodes(roots, node(2), false)).toHaveLength(1)
    expect(listFolder(roots, node(2), emptyFilter(), 100, true).scope).toBe(4)
    expect(listFolder(roots, node(2), emptyFilter(), 100, false).scope).toBe(1)
  })
})

describe('filters inside a folder', () => {
  it('look at everything below the folder and list the matches flat, each with its trail', () => {
    const list = listFolder(roots, node(2), filter({ query: 'select' }), 100, true)
    expect(list.flat).toBe(true)
    expect(ids(list)).toEqual([5])
    expect(list.entries[0]!.trail.map((n) => n.span.id)).toEqual([3, 4])          // controller, service: the path inside the open folder
    expect(list.matches).toBe(1)
    expect(list.scope).toBe(4)
  })

  it('never reach outside the open folder', () => {
    expect(ids(listFolder(roots, node(4), filter({ types: new Set(['log']) }), 100, true))).toEqual([])
    expect(ids(listFolder(roots, node(1), filter({ types: new Set(['log']) }), 100, true))).toEqual([7])
  })

  it('combine kind, result and speed', () => {
    expect(ids(listFolder(roots, node(1), filter({ result: 'failed' }), 100, true))).toEqual([6])
    expect(ids(listFolder(roots, node(1), filter({ speed: 'slow' }), 2.5, true))).toEqual([2, 3, 4, 6])
    expect(ids(listFolder(roots, node(1), filter({ types: new Set(['query', 'http']), result: 'ok' }), 100, true))).toEqual([5])
  })

  it('can be narrowed to the children of the folder', () => {
    const list = listFolder(roots, node(1), filter({ query: 'select' }), 100, false)
    expect(list.flat).toBe(false)
    expect(ids(list)).toEqual([])                          // the query is three levels down, not a child of the request
    expect(ids(listFolder(roots, node(1), filter({ types: new Set(['log']) }), 100, false))).toEqual([7])
  })

  it('count the kinds in the scope', () => {
    const counts = scopeTypeCounts(below(roots, node(3)), ['service', 'query', 'http', 'log'])
    expect(counts).toEqual([{ type: 'service', count: 1 }, { type: 'query', count: 1 }, { type: 'http', count: 1 }])
  })
})

describe('the time axis of a folder', () => {
  it('is the whole request at the top and the folder with a little air inside it', () => {
    expect(folderRange(null, 10_000)).toEqual({ from: 0, to: 10_000 })
    expect(folderRange(node(1), 10_000)).toEqual({ from: 0, to: 10_000 })
    const range = folderRange(node(4), 10_000)
    expect(range.from).toBeLessThan(2000)
    expect(range.from).toBeGreaterThan(1500)
    expect(range.to).toBeGreaterThan(8000)
    expect(range.to).toBeLessThanOrEqual(10_000)
  })

  it('is never narrower than a millisecond', () => {
    const quick = buildTree([span(1, null, { duration_us: 5000 }), span(2, 1, { start_us: 100, duration_us: 3 })])
    const range = folderRange(findNode(quick, 2), 5000)
    expect(range.to - range.from).toBeGreaterThanOrEqual(1000)
  })
})

describe('what lights up in the graph', () => {
  it('lights everything while nothing is filtered', () => {
    expect(litSteps(roots, emptyFilter(), 100, true)).toBeNull()
  })

  it('lights the matches and, looking inside, the way down to them', () => {
    expect([...litSteps(roots, filter({ query: 'select' }), 100, true)!].sort()).toEqual([1, 2, 3, 4, 5])
    expect([...litSteps(roots, filter({ query: 'select' }), 100, false)!]).toEqual([5])
  })
})

describe('the columns of the graph', () => {
  it('are the children of every step on the way down to the one that is open, and the last ones that fit', () => {
    const { columns, rail } = graphColumns(roots, node(4), 3)
    expect(columns.map((c) => c.parent!.span.id)).toEqual([2, 3, 4])
    expect(columns.map((c) => c.nodes.map((n) => n.span.id))).toEqual([[3], [4], [5, 6]])
    expect(columns[0]!.selected!.span.id).toBe(3)
    expect(columns[2]!.selected).toBeNull()
    expect(rail.map((n) => n.span.id)).toEqual([1, 2])                     // what is left behind on the way
  })

  it('shows fewer columns in a narrow space and keeps the path to the left', () => {
    const { columns, rail } = graphColumns(roots, node(4), 2)
    expect(columns.map((c) => c.parent!.span.id)).toEqual([3, 4])
    expect(rail.map((n) => n.span.id)).toEqual([1, 2, 3])
  })

  it('leaves out the empty column of a step with nothing inside it', () => {
    const { columns } = graphColumns(roots, node(5), 3)
    expect(columns.map((c) => c.parent!.span.id)).toEqual([2, 3, 4])
    expect(columns[2]!.selected!.span.id).toBe(5)
  })

  it('starts from the roots when no step is chosen', () => {
    const { columns, rail } = graphColumns(roots, null, 3)
    expect(columns).toHaveLength(1)
    expect(columns[0]!.nodes.map((n) => n.span.id)).toEqual([1])
    expect(rail).toEqual([])
  })
})
