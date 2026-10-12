import { describe, expect, it } from 'vitest'
import type { SpanRow, SpanType, TraceSummary } from '../api/types'
import { callsIn, classOf, linkCalls, mainBlock, outsideCodePaths, reachableBlocks, shortName, suggestPath, type CodeBlock, type CodeLine } from './code'
import { emptyFilter, filterSteps, isFailed, isSlow, typeCounts } from './filters'
import { groupTraces, isIdSegment, routeGroup } from './groups'
import { buildTree } from './tree'

function line(n: number, text: string, state: CodeLine['state'] = 'ran'): CodeLine {
  return { n, text, state, chip: '', description: '', variables: [], seq: state === 'notrun' ? null : n, hits: 1 }
}

function block(name: string, start: number, texts: Array<[string, CodeLine['state']?]>, own = false, file = 'app/Http/Controllers/PaymentController.php'): CodeBlock {
  return { file, name, start, end: start + texts.length, own, untraced: false, shift: 0, missing: false, changedSinceTrace: false, lines: texts.map(([t, s], i) => line(start + i, t, s ?? 'ran')) }
}

describe('callsIn', () => {
  it('finds method, static, plain and constructor calls', () => {
    expect(callsIn('$total = $this->service->charge($order, Money::of(5));')).toEqual([
      { name: 'charge', cls: null },
      { name: 'of', cls: 'Money' },
    ])
    expect(callsIn('$id = helper_id($x);')).toEqual([{ name: 'helper_id', cls: null }])
    expect(callsIn('return new Money($amount);')).toEqual([{ name: '__construct', cls: 'Money' }])
    expect(callsIn('self::validate($x); static::boot(); parent::save();')).toEqual([
      { name: 'validate', cls: null }, { name: 'boot', cls: null }, { name: 'save', cls: null },
    ])
  })

  it('ignores keywords, declarations, strings and comments', () => {
    expect(callsIn('if ($a && foreach_it($b)) {')).toEqual([{ name: 'foreach_it', cls: null }])
    expect(callsIn('public function charge(Order $order): Receipt')).toEqual([])
    expect(callsIn("$label = 'charge(x)';")).toEqual([])
    expect(callsIn('// $this->charge($order)')).toEqual([])
    expect(callsIn(' * @see charge()')).toEqual([])
  })
})

describe('names', () => {
  it('shortens a function name to its method and class', () => {
    expect(shortName('OrderService::create')).toBe('create')
    expect(shortName('Order@create')).toBe('create')
    expect(shortName('helper_id')).toBe('helper_id')
    expect(classOf('OrderService::create')).toBe('OrderService')
    expect(classOf('helper_id')).toBeNull()
  })
})

describe('linkCalls', () => {
  const action = block('PaymentController::store', 20, [
    ['public function store(Request $r)'],
    ['$voucher = $this->prepare($r);'],
    ['if ($r->has("x")) {'],
    ['    $this->audit($voucher);', 'notrun'],
    ['}'],
    ['return $this->service->charge($voucher);'],
    ['$again = $this->prepare($r);'],
  ], true)

  it('links a candidate once, at the first line that ran and calls it', () => {
    const links = linkCalls(action, [
      { id: 1, name: 'PaymentController::prepare' },
      { id: 2, name: 'PaymentController::audit' },
      { id: 3, name: 'PaymentService::charge' },
    ])

    expect(links).toEqual([{ line: 1, target: 1 }, { line: 5, target: 3 }])          // audit did not run, prepare is offered once
  })

  it('respects the class written in the call', () => {
    const b = block('X::run', 1, [['Money::of(5);']])

    expect(linkCalls(b, [{ id: 1, name: 'Tax::of' }, { id: 2, name: 'Money::of' }])).toEqual([{ line: 0, target: 2 }])
  })
})

describe('mainBlock and reachableBlocks', () => {
  const blocks = [
    block('Controller::__construct', 5, [['public function __construct()'], ['$this->middleware("auth");']], false, 'app/Http/Controllers/Controller.php'),
    block('PaymentController::store', 20, [['public function store()'], ['$x = $this->prepare();'], ['helper_id($x);']], true),
    block('PaymentController::prepare', 40, [['private function prepare()'], ['return Money::of(1);']]),
    block('Money::of', 5, [['public static function of($v)']], false, 'app/Support/Money.php'),
    block('helper_id', 1, [['function helper_id($x)']], false, 'app/helpers.php'),
    block('Unrelated::thing', 9, [['public function thing()']], false, 'app/Unrelated.php'),
  ]

  it('prefers the function the server marked as the step itself', () => {
    expect(mainBlock(blocks, null, null)).toBe(1)
  })

  it('falls back to the function that contains the step line', () => {
    const unmarked = blocks.map((b) => ({ ...b, own: false }))

    expect(mainBlock(unmarked, 'app/Http/Controllers/PaymentController.php', 41)).toBe(2)
    expect(mainBlock(unmarked, 'app/Elsewhere.php', 3)).toBe(-1)
  })

  it('reaches only what the action calls, directly or through what it calls', () => {
    expect([...reachableBlocks(blocks, 1)].sort()).toEqual([1, 2, 3, 4])               // not the constructor, not the unrelated function
  })
})

function span(id: number, parent: number | null, type: SpanType, over: Partial<SpanRow> = {}): SpanRow {
  return {
    id, parent_id: parent, type, label: `${type}-${id}`, file: null, line: null, start_us: 0, duration_us: 1000, memory_delta: 0,
    flag: null, connection: null, count: 1, meta: null, has_input: false, has_output: false, ...over,
  }
}

describe('step filters', () => {
  const roots = buildTree([
    span(1, null, 'request'),
    span(2, 1, 'middleware'),
    span(3, 1, 'controller', { duration_us: 700_000 }),
    span(4, 3, 'query', { label: 'select * from orders' }),
    span(5, 3, 'service', { flag: 'failed' }),
    span(6, 5, 'query', { label: 'insert into logs', duration_us: 2000 }),
    span(7, 1, 'http', { meta: { status: 502 } }),
  ])

  it('shows everything when nothing is selected', () => {
    expect(filterSteps(roots, emptyFilter(), 100)).toBeNull()
  })

  it('keeps the steps of the chosen types and the steps above them', () => {
    const result = filterSteps(roots, { ...emptyFilter(), types: new Set<SpanType>(['query']) }, 100)

    expect([...(result?.keep ?? [])].sort()).toEqual([1, 3, 4, 5, 6])
    expect([...(result?.matched ?? [])].sort()).toEqual([4, 6])
  })

  it('several types at once', () => {
    const result = filterSteps(roots, { ...emptyFilter(), types: new Set<SpanType>(['middleware', 'http']) }, 100)

    expect([...(result?.matched ?? [])].sort()).toEqual([2, 7])
  })

  it('splits slow from fast and failed from ok', () => {
    expect([...(filterSteps(roots, { ...emptyFilter(), speed: 'slow' }, 100)?.matched ?? [])]).toEqual([3])
    expect([...(filterSteps(roots, { ...emptyFilter(), result: 'failed' }, 100)?.matched ?? [])].sort()).toEqual([5, 7])
    expect([...(filterSteps(roots, { ...emptyFilter(), types: new Set<SpanType>(['query']), speed: 'fast', result: 'ok' }, 100)?.matched ?? [])].sort()).toEqual([4, 6])
  })

  it('combines with the text search', () => {
    const result = filterSteps(roots, { ...emptyFilter(), query: 'logs', types: new Set<SpanType>(['query']) }, 100)

    expect([...(result?.matched ?? [])]).toEqual([6])
  })

  it('counts the steps of each type in display order', () => {
    expect(typeCounts(roots, ['request', 'middleware', 'controller', 'service', 'query', 'http'])).toEqual([
      { type: 'request', count: 1 }, { type: 'middleware', count: 1 }, { type: 'controller', count: 1 },
      { type: 'service', count: 1 }, { type: 'query', count: 2 }, { type: 'http', count: 1 },
    ])
  })

  it('knows slow and failed', () => {
    expect(isSlow(span(1, null, 'query', { duration_us: 150_000 }), 100)).toBe(true)
    expect(isSlow(span(1, null, 'query', { flag: 'slow' }), 100)).toBe(true)
    expect(isSlow(span(1, null, 'query'), 100)).toBe(false)
    expect(isFailed(span(1, null, 'service', { flag: 'caught' }))).toBe(true)
    expect(isFailed(span(1, null, 'http', { meta: { status: 404 } }))).toBe(true)
    expect(isFailed(span(1, null, 'http', { meta: { status: 200 } }))).toBe(false)
  })
})

function trace(path: string | null, over: Partial<TraceSummary> = {}): TraceSummary {
  return {
    id: path ?? 'x', kind: 'request', method: 'GET', path, route_name: null, status: 200, duration_ms: 10, memory_peak: 0, user_id: null, tenant: null,
    module: null, spans_count: 1, queries_count: 0, problems_count: 0, tables_written: null, tables_read: null, notes: null, truncated: false,
    laravel_version: null, environment: null, mode: null, parent_trace_id: null, created_at: '2026-10-12T10:00:00+00:00', ...over,
  }
}

describe('route groups', () => {
  it('recognises record ids', () => {
    expect(['42', '9f1c2d3e-1a2b-4c3d-8e9f-0a1b2c3d4e5f', '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'a1b2c3d4e5f60718'].every(isIdSegment)).toBe(true)
    expect(['orders', 'v2', 'vouchers'].some(isIdSegment)).toBe(false)
  })

  it('groups by the first segments of the route, up to the first id', () => {
    expect(routeGroup(trace('/api/payments/vouchers/12/edit'), 1)).toBe('/api')
    expect(routeGroup(trace('/api/payments/vouchers/12/edit'), 2)).toBe('/api/payments')
    expect(routeGroup(trace('/api/payments/vouchers/12/edit'), 9)).toBe('/api/payments/vouchers')
    expect(routeGroup(trace('/orders/5'), 2)).toBe('/orders')
    expect(routeGroup(trace('/'), 2)).toBe('/')
    expect(routeGroup(trace('/login?next=%2F'), 1)).toBe('/login')
    expect(routeGroup(trace(null, { kind: 'job' }), 1)).toBe('Jobs')
    expect(routeGroup(trace(null, { kind: 'command' }), 1)).toBe('Commands')
  })

  it('keeps the order of the list and summarises each group', () => {
    const groups = groupTraces([
      trace('/api/orders/1', { duration_ms: 30 }),
      trace('/login', { status: 302 }),
      trace('/api/orders/2', { status: 500, duration_ms: 90, problems_count: 2 }),
      trace('/api/products', { status: 404 }),
    ], 2)

    expect(groups.map((g) => g.key)).toEqual(['/api/orders', '/login', '/api/products'])
    expect(groups[0]).toMatchObject({ worst: 'bad', slowestMs: 90, problems: 2 })
    expect(groups[0]?.traces).toHaveLength(2)
    expect(groups[1]?.worst).toBe('ok')
    expect(groups[2]?.worst).toBe('warn')
  })
})

describe('code scope', () => {
  it('knows when a file is outside the folders that are traced', () => {
    expect(outsideCodePaths('packages/Accounting/src/Controllers/PaymentVoucherController.php', ['app'])).toBe(true)
    expect(outsideCodePaths('app/Http/Controllers/OrderController.php', ['app'])).toBe(false)
    expect(outsideCodePaths('packages/Accounting/src/X.php', ['app', 'packages/Accounting/src/'])).toBe(false)
    expect(outsideCodePaths('app/X.php', ['app/X.php'])).toBe(false)
    expect(outsideCodePaths('packages/A/src/X.php', ['D:/Projects/erp/packages'])).toBe(false)         // an absolute path cannot be compared: say nothing
    expect(outsideCodePaths('application/X.php', ['app'])).toBe(true)                                   // a prefix of the name is not the folder
    expect(outsideCodePaths(null, ['app'])).toBe(false)
    expect(outsideCodePaths('a.php', [])).toBe(false)
  })

  it('suggests the src folder of a package, else the folder of the file', () => {
    expect(suggestPath('packages/Accounting/src/Controllers/PaymentVoucherController.php')).toBe('packages/Accounting/src')
    expect(suggestPath('modules/Billing/Http/InvoiceController.php')).toBe('modules/Billing/Http')
  })

  it('links the calls of a function that was not traced, line by line unknown', () => {
    const untraced: CodeBlock = { ...block('Ctl::show', 1, [['$x = $this->prepare($r);']], true), untraced: true }
    untraced.lines = untraced.lines.map((l) => ({ ...l, state: 'plain', seq: null }))

    expect(linkCalls(untraced, [{ id: 7, name: 'Ctl::prepare' }])).toEqual([{ line: 0, target: 7 }])
  })
})
