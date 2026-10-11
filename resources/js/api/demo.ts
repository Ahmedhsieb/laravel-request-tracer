import type {
  Api, Dump, IssuedToken, LineRow, Meta, Payload, ProblemRow, QueueEntry, SourceFunction, SpanLines, SpanRow, TraceFilters, TracePage,
  TraceSummary, WatchEntry, WriteRow,
} from './types'

/**
 * Seeded demo data: a believable "place an order" request with a failed-then-retried HTTP call, an N+1, a caught exception,
 * Deep trace of OrderService::create and tables written with before/after, plus a few other traces for the list.
 * Used by the sandbox (`npm run dev`) and by the dashboard when `?demo=1` is in the URL. Nothing here talks to a server.
 */

const MASK = '••••••••'
const NOW = Date.now()

export const DEMO_META: Meta = {
  name: 'Request Tracer',
  version: '0.1.0',
  environment: 'production',
  is_production: true,
  mode: 'triggered',
  enabled: true,
  recording: { mode: 'triggered', header: 'X-Request-Tracer', watch_list: true, sampling: { enabled: false, rate: 0.01 }, token_ttl: 300 },
  user: { id: 1, name: 'Amira Hassan' },
  slow_ms: 500,
  poll_seconds: 5,
  mask: MASK,
  deep_trace: { enabled: true },
  limits: { max_spans: 5000, max_hits: 20000 },
}

// ---------------------------------------------------------------------------------------------- value helpers

const list = (items: Dump[]): Dump => ({ $: 'arr', n: items.length, list: true, i: items })
const map = (entries: Array<[string, Dump]>): Dump => ({ $: 'arr', n: entries.length, i: entries })
const obj = (c: string, entries: Array<[string, Dump]>, kind?: string): Dump => ({
  $: 'obj', c, ...(kind === undefined ? {} : { kind }), i: entries.map(([k, v]) => [k, 'public', v] as [string, string, Dump]),
})
const model = (c: string, key: number, attributes: Array<[string, Dump]>): Dump => ({
  $: 'obj', c, kind: 'model', i: [['key', 'virtual', key], ['attributes', 'virtual', map(attributes)]],
})
const mask: Dump = { $: 'mask' }

// ---------------------------------------------------------------------------------------------- the flagship trace

export const ORDER_TRACE_ID = '01J8DEMO0RDER0FL0W00000001'
const JOB_TRACE_ID = '01J8DEMO0J0B000000000000002'

const ORDER_FILE = 'app/Services/OrderService.php'
const PRICING_FILE = 'app/Services/PricingService.php'

function span(id: number, parent: number | null, type: SpanRow['type'], label: string, start: number, duration: number, extra: Partial<SpanRow> = {}): SpanRow {
  return {
    id, parent_id: parent, type, label, file: null, line: null, start_us: start, duration_us: duration, memory_delta: 0, flag: null,
    connection: null, count: 1, meta: null, has_input: true, has_output: true, ...extra,
  }
}

const SQL = {
  user: 'select * from `users` where `id` = ? limit 1',
  products: 'select * from `products` where `id` in (?, ?, ?, ?, ?, ?)',
  insertOrder: 'insert into `orders` (`user_id`, `status`, `total`, `updated_at`, `created_at`) values (?, ?, ?, ?, ?)',
  product: 'select * from `products` where `products`.`id` = ? limit 1',
  insertItem: 'insert into `order_items` (`order_id`, `product_id`, `qty`, `updated_at`, `created_at`) values (?, ?, ?, ?, ?)',
  stock: 'update `products` set `stock` = `stock` - ?, `updated_at` = ? where `id` = ?',
}

const ORDER_SPANS: SpanRow[] = [
  span(1, null, 'request', 'POST /orders', 0, 412_000, { memory_delta: 6_291_456, meta: { summary: 'Responds 302 → /orders/1057' } }),
  span(2, 1, 'middleware', 'Illuminate\\Session\\Middleware\\StartSession', 600, 408_000, { meta: { summary: 'Session #a91f…' }, memory_delta: 262_144 }),
  span(3, 2, 'middleware', 'Illuminate\\Auth\\Middleware\\Authenticate', 1_800, 406_000, { meta: { summary: 'Authenticated as User #42' } }),
  span(4, 3, 'middleware', 'Illuminate\\Routing\\Middleware\\ThrottleRequests', 2_600, 404_000, { meta: { summary: '3 of 60 attempts used' } }),
  span(5, 4, 'controller', 'App\\Http\\Controllers\\OrderController@store', 4_000, 399_000, { file: 'app/Http/Controllers/OrderController.php', line: 31, memory_delta: 5_242_880, meta: { summary: 'Redirects to /orders/1057' } }),
  span(6, 5, 'validation', 'App\\Http\\Requests\\StoreOrderRequest', 4_500, 3_200, { file: 'app/Http/Requests/StoreOrderRequest.php', line: 12, meta: { summary: 'Valid: 4 fields' } }),
  span(7, 5, 'service', 'App\\Services\\OrderService::create', 8_000, 380_000, { file: ORDER_FILE, line: 24, memory_delta: 4_718_592, meta: { summary: 'Returns Order #1057', deep: true } }),
  span(8, 7, 'query', SQL.user, 9_000, 1_800, { connection: 'mysql', meta: { op: 'select', table: 'users', rows: 1 }, file: ORDER_FILE, line: 25 }),
  span(9, 7, 'service', 'App\\Services\\PricingService::quote', 12_000, 190_000, { file: PRICING_FILE, line: 18, meta: { summary: 'Returns Quote (total 184.50 EGP)' } }),
  span(10, 9, 'query', SQL.products, 12_500, 3_200, { connection: 'mysql', meta: { op: 'select', table: 'products', rows: 6 }, file: PRICING_FILE, line: 22 }),
  span(11, 9, 'http', 'GET https://tax.example.com/v1/rates/EG', 20_000, 60_000, { flag: 'failed', meta: { status: 503, summary: 'Failed with 503 Service Unavailable' }, file: PRICING_FILE, line: 31 }),
  span(12, 9, 'exception', 'GuzzleHttp\\Exception\\ServerException', 80_500, 0, { flag: 'caught', file: PRICING_FILE, line: 34, meta: { summary: 'Caught in PricingService::quote' }, has_output: false }),
  span(13, 9, 'http', 'GET https://tax.example.com/v1/rates/EG', 85_000, 95_000, { flag: 'slow', meta: { status: 200, summary: 'Responded 200 OK in 95 ms' }, file: PRICING_FILE, line: 31 }),
  span(14, 7, 'query', SQL.insertOrder, 210_000, 4_500, { connection: 'mysql', meta: { op: 'insert', table: 'orders', rows: 1 }, file: ORDER_FILE, line: 34 }),
  span(15, 7, 'query', SQL.product, 220_000, 36_000, { connection: 'mysql', flag: 'repeated', count: 6, meta: { op: 'select', table: 'products', rows: 6 }, file: ORDER_FILE, line: 41 }),
  span(16, 7, 'query', SQL.insertItem, 258_000, 24_000, { connection: 'mysql', count: 6, meta: { op: 'insert', table: 'order_items', rows: 6 }, file: ORDER_FILE, line: 42 }),
  span(17, 7, 'query', SQL.stock, 284_000, 9_000, { connection: 'mysql', count: 6, meta: { op: 'update', table: 'products', rows: 6 }, file: ORDER_FILE, line: 43 }),
  span(18, 7, 'event', 'App\\Events\\OrderPlaced', 330_000, 40_000, { file: ORDER_FILE, line: 47, meta: { summary: '2 listeners' } }),
  span(19, 18, 'job', 'App\\Jobs\\SendOrderConfirmation', 332_000, 800, { meta: { summary: 'Queued on redis · default' } }),
  span(20, 18, 'cache', 'forget orders:user:42', 345_000, 600, { meta: { summary: 'Cache miss → forgotten' } }),
  span(21, 5, 'log', 'info: Order created', 390_000, 100, { meta: { summary: 'context: order_id 1057' }, has_output: false }),
]

const USER = model('App\\Models\\User', 42, [['name', 'Amira Hassan'], ['email', 'amira@example.com'], ['country', 'EG'], ['is_vip', false], ['password', mask]])
const ITEMS = list([1, 2, 3, 4, 5, 6].map((i) => map([['product_id', 300 + i], ['qty', i % 3 + 1]])))
const ORDER = model('App\\Models\\Order', 1057, [['user_id', 42], ['status', 'pending'], ['total', '184.50'], ['created_at', '2026-10-10 14:03:22']])
const QUOTE = obj('App\\Support\\Quote', [['subtotal', '161.84'], ['tax', '22.66'], ['total', '184.50'], ['currency', 'EGP']])

const request = obj('Illuminate\\Http\\Request', [
  ['method', 'POST'], ['url', 'https://shop.example.com/orders'],
  ['headers', map([['content-type', 'application/x-www-form-urlencoded'], ['cookie', mask], ['x-request-tracer', mask], ['user-agent', 'Mozilla/5.0']])],
  ['input', map([['items', ITEMS], ['coupon', null], ['card_number', mask], ['_token', mask]])],
], 'request')

const ORDER_PAYLOADS = new Map<number, Payload>([
  [1, { input: { value: request }, output: { value: obj('Illuminate\\Http\\RedirectResponse', [['status', 302], ['headers', map([['location', 'https://shop.example.com/orders/1057']])]], 'response') } }],
  [2, { input: { value: map([['request', request]]) }, output: { value: obj('Illuminate\\Http\\RedirectResponse', [['status', 302]], 'response') } }],
  [5, { input: { value: map([['request', obj('App\\Http\\Requests\\StoreOrderRequest', [['validated', map([['items', ITEMS], ['coupon', null]])]])], ['service', obj('App\\Services\\OrderService', [])]]) }, output: { value: obj('Illuminate\\Http\\RedirectResponse', [['status', 302], ['targetUrl', 'https://shop.example.com/orders/1057']], 'response') } }],
  [6, { input: { value: map([['rules', map([['items', 'required|array|min:1'], ['items.*.product_id', 'required|exists:products,id'], ['items.*.qty', 'required|integer|min:1'], ['coupon', 'nullable|string']])]]) }, output: { value: map([['items', ITEMS], ['coupon', null]]) } }],
  [7, { input: { value: map([['user', USER], ['items', ITEMS]]) }, output: { value: ORDER } }],
  [9, { input: { value: map([['items', ITEMS], ['country', 'EG']]) }, output: { value: QUOTE } }],
  [11, { input: { value: map([['method', 'GET'], ['url', 'https://tax.example.com/v1/rates/EG'], ['headers', map([['authorization', mask], ['accept', 'application/json']])]]) }, output: { value: obj('Illuminate\\Http\\Client\\Response', [['status', 503], ['body', '{"error":"upstream timeout"}']], 'response') } }],
  [13, { input: { value: map([['method', 'GET'], ['url', 'https://tax.example.com/v1/rates/EG'], ['headers', map([['authorization', mask], ['accept', 'application/json']])]]) }, output: { value: obj('Illuminate\\Http\\Client\\Response', [['status', 200], ['body', '{"country":"EG","rate":0.14}']], 'response') } }],
  [12, { input: { value: obj('GuzzleHttp\\Exception\\ServerException', [['message', 'Server error: `GET https://tax.example.com/v1/rates/EG` resulted in a `503 Service Unavailable` response'], ['code', 503], ['file', PRICING_FILE], ['line', 31]], 'exception') }, output: null }],
  [18, { input: { value: obj('App\\Events\\OrderPlaced', [['order', ORDER]]) }, output: { value: list(['App\\Listeners\\ReserveStock', 'App\\Listeners\\SendOrderConfirmation']) } }],
  [19, { input: { value: obj('App\\Jobs\\SendOrderConfirmation', [['order', ORDER], ['queue', 'default']]) }, output: { value: 'queued' } }],
  [20, { input: { value: map([['key', 'orders:user:42']]) }, output: { value: true } }],
])

function queryPayload(sql: string, bindings: Dump[], affected: number, rows: boolean): Payload {
  return {
    input: { value: map([['sql', sql], ['bindings', list(bindings)]]) },
    output: { value: map(rows ? [['rows', affected]] : [['affected', affected]]) },
  }
}

ORDER_PAYLOADS.set(8, queryPayload(SQL.user, [42], 1, true))
ORDER_PAYLOADS.set(10, queryPayload(SQL.products, [301, 302, 303, 304, 305, 306], 6, true))
ORDER_PAYLOADS.set(14, queryPayload(SQL.insertOrder, [42, 'pending', '184.50', '2026-10-10 14:03:22', '2026-10-10 14:03:22'], 1, false))
ORDER_PAYLOADS.set(15, queryPayload(SQL.product, [301], 1, true))
ORDER_PAYLOADS.set(16, queryPayload(SQL.insertItem, [1057, 301, 2, '2026-10-10 14:03:22', '2026-10-10 14:03:22'], 1, false))
ORDER_PAYLOADS.set(17, queryPayload(SQL.stock, [2, '2026-10-10 14:03:22', 301], 1, false))

// ---------------------------------------------------------------------------------------------- Deep trace of OrderService::create

const SOURCE_TEXT = [
  'public function create(User $user, array $items): Order',
  '{',
  '    $quote = $this->pricing->quote($items, $user->country);',
  '',
  '    if ($user->is_vip) {',
  '        $discount = $quote->total * 0.1;',
  '    } else {',
  '        $discount = 0;',
  '    }',
  '',
  '    $order = Order::create([',
  "        'user_id' => $user->id,",
  "        'status' => 'pending',",
  "        'total' => $quote->total - $discount,",
  '    ]);',
  '',
  '    foreach ($items as $item) {',
  "        $product = Product::find($item['product_id']);",
  "        $order->items()->create(['product_id' => $product->id, 'qty' => $item['qty']]);",
  "        $product->decrement('stock', $item['qty']);",
  '    }',
  '',
  '    OrderPlaced::dispatch($order);',
  '',
  '    return $order;',
  '}',
]
const START = 24

const ORDER_SOURCE: SourceFunction = {
  file: ORDER_FILE,
  name: 'OrderService::create',
  start: START,
  end: START + SOURCE_TEXT.length - 1,
  lines: SOURCE_TEXT.map((text, i) => ({ n: START + i, text })),
  // the lines that carry a probe; the continuation lines of the multi-line Order::create([...]) call do not
  statements: [24, 26, 28, 31, 34, 40, 41, 42, 43, 46, 48],
  missing: false,
  changed_since_trace: false,
}

const FN: [string, number, number] = ['OrderService::create', ORDER_SOURCE.start, ORDER_SOURCE.end]

function row(line: number, kind: LineRow['kind'], hits: number, result: LineRow['result'], snippet: string | null): LineRow {
  return { span_id: 7, file: ORDER_FILE, line, kind, hits, result, snippet }
}

const ORDER_LINES: LineRow[] = [
  row(24, 'assign', 1, { seq: 1, fn: FN, first: map([['user', USER], ['items', ITEMS]]), last: map([['user', USER], ['items', ITEMS]]) }, 'arguments'),
  row(26, 'assign', 1, { seq: 2, name: 'quote', changes: 1, first: QUOTE, last: QUOTE }, '$quote = $this->pricing->quote($items, $user->country)'),
  row(28, 'cond', 1, { seq: 3, true: 0, false: 1, samples: [{ a: false, b: null, op: null, result: false }] }, '$user->is_vip'),
  row(31, 'assign', 1, { seq: 4, name: 'discount', changes: 1, first: 0, last: 0 }, '$discount = 0'),
  row(34, 'assign', 1, { seq: 5, name: 'order', changes: 1, first: ORDER, last: ORDER }, '$order = Order::create([...])'),
  row(40, 'loop', 6, { seq: 6, iterations: 6, snapshots: [map([['item', map([['product_id', 301], ['qty', 2]])]]), map([['item', map([['product_id', 302], ['qty', 3]])]])] }, 'foreach ($items as $item)'),
  row(41, 'assign', 6, { seq: 7, name: 'product', changes: 6, first: model('App\\Models\\Product', 301, [['name', 'Desk lamp'], ['stock', 40]]), last: model('App\\Models\\Product', 306, [['name', 'Cable set'], ['stock', 12]]) }, "$product = Product::find($item['product_id'])"),
  row(42, 'block', 6, { seq: 8 }, "$order->items()->create([...])"),
  row(43, 'block', 6, { seq: 9 }, "$product->decrement('stock', $item['qty'])"),
  row(46, 'block', 1, { seq: 10 }, 'OrderPlaced::dispatch($order)'),
  row(48, 'return', 1, { seq: 11, value: ORDER }, 'return $order'),
]

// ---------------------------------------------------------------------------------------------- writes and problems

const PRODUCTS = [
  [301, 'Desk lamp', 40, 2], [302, 'USB hub', 18, 3], [303, 'Notebook', 120, 1], [304, 'Monitor stand', 9, 1], [305, 'Webcam', 22, 2], [306, 'Cable set', 14, 2],
] as const

const ORDER_WRITES: WriteRow[] = (() => {
  const rows: WriteRow[] = []
  let id = 1
  rows.push({
    id: id++, span_id: 14, connection: 'mysql', table: 'orders', operation: 'insert', row_key: '1057', before: null,
    after: { id: 1057, user_id: 42, status: 'pending', total: '184.50', card_number: MASK, created_at: '2026-10-10 14:03:22' },
    transaction: 'committed', rows: 1,
  })
  for (const [pid, , , qty] of PRODUCTS) {
    rows.push({
      id: id++, span_id: 16, connection: 'mysql', table: 'order_items', operation: 'insert', row_key: String(2000 + pid), before: null,
      after: { id: 2000 + pid, order_id: 1057, product_id: pid, qty }, transaction: 'committed', rows: 1,
    })
  }
  for (const [pid, name, stock, qty] of PRODUCTS) {
    rows.push({
      id: id++, span_id: 17, connection: 'mysql', table: 'products', operation: 'update', row_key: String(pid),
      before: { id: pid, name, stock }, after: { id: pid, name, stock: stock - qty }, transaction: 'committed', rows: 1,
    })
  }

  return rows
})()

const ORDER_PROBLEMS: ProblemRow[] = [
  { id: 1, span_id: 15, kind: 'n_plus_one', message: '`select * from products where id = ?` ran 6 times in a loop. Load the products once with whereIn() or with().', file: ORDER_FILE, line: 41 },
  { id: 2, span_id: 11, kind: 'http_failed', message: 'GET https://tax.example.com/v1/rates/EG returned 503 Service Unavailable after 60 ms (the call was retried).', file: PRICING_FILE, line: 31 },
  { id: 3, span_id: 12, kind: 'exception_caught', message: 'GuzzleHttp\\Exception\\ServerException was caught in PricingService::quote and the request carried on.', file: PRICING_FILE, line: 34 },
  { id: 4, span_id: 13, kind: 'slow_step', message: 'GET https://tax.example.com/v1/rates/EG took 95 ms, 23 % of the request.', file: PRICING_FILE, line: 31 },
]

// ---------------------------------------------------------------------------------------------- the other traces

function ago(seconds: number): string {
  return new Date(NOW - seconds * 1000).toISOString()
}

function summary(id: string, over: Partial<TraceSummary>): TraceSummary {
  return {
    id, kind: 'request', method: 'GET', path: '/', route_name: null, status: 200, duration_ms: 80, memory_peak: 12_582_912, user_id: '42', tenant: 'acme',
    module: null, spans_count: 9, queries_count: 4, problems_count: 0, tables_written: null, tables_read: null, notes: null, truncated: false,
    laravel_version: '12.4.0', environment: 'production', mode: 'triggered', parent_trace_id: null, created_at: ago(60), problem_kinds: [], ...over,
  }
}

const TRACES: TraceSummary[] = [
  summary(ORDER_TRACE_ID, {
    method: 'POST', path: '/orders', route_name: 'orders.store', status: 302, duration_ms: 412, memory_peak: 18_874_368, module: 'Sales',
    spans_count: ORDER_SPANS.length, queries_count: 21, problems_count: ORDER_PROBLEMS.length, created_at: ago(24),
    problem_kinds: ['n_plus_one', 'http_failed', 'exception_caught', 'slow_step'],
    tables_written: [
      { connection: 'mysql', table: 'orders', insert: 1, update: 0, delete: 0 },
      { connection: 'mysql', table: 'order_items', insert: 6, update: 0, delete: 0 },
      { connection: 'mysql', table: 'products', insert: 0, update: 6, delete: 0 },
    ],
    tables_read: { users: { queries: 1, rows: 1 }, products: { queries: 7, rows: 12 } },
    notes: [{ kind: 'not_traceable', target: 'App\\Support\\LegacyTaxTable', message: 'Final class: it cannot be proxied. Its queries still show under the nearest traced step.' }],
  }),
  summary(JOB_TRACE_ID, {
    kind: 'job', method: null, path: 'App\\Jobs\\SendOrderConfirmation', status: null, duration_ms: 318, module: 'Sales', spans_count: 7, queries_count: 2, created_at: ago(22),
    parent_trace_id: ORDER_TRACE_ID, user_id: null, mode: 'always',
  }),
  summary('01J8DEMO0LIST00000000000003', { path: '/orders', route_name: 'orders.index', duration_ms: 86, module: 'Sales', spans_count: 12, queries_count: 5, created_at: ago(95), tables_read: { orders: { queries: 2, rows: 25 } } }),
  summary('01J8DEMO0INV0ICE0000000004', {
    path: '/api/invoices/9912', route_name: 'invoices.show', status: 500, duration_ms: 231, module: 'Billing', spans_count: 11, queries_count: 3, problems_count: 1,
    problem_kinds: ['exception_uncaught'], created_at: ago(240),
  }),
  summary('01J8DEMO0L0GIN000000000005', { method: 'POST', path: '/login', route_name: 'login', status: 302, duration_ms: 140, user_id: null, spans_count: 8, queries_count: 2, created_at: ago(420) }),
  summary('01J8DEMO0DASH0000000000006', {
    path: '/dashboard', route_name: 'dashboard', duration_ms: 1240, module: 'Reports', spans_count: 31, queries_count: 18, problems_count: 1, problem_kinds: ['slow_step'], created_at: ago(700),
  }),
  summary('01J8DEMO0PR0DUCTS00000007', { path: '/products?page=2', route_name: 'products.index', duration_ms: 64, module: 'Catalog', spans_count: 10, queries_count: 3, created_at: ago(1300) }),
  summary('01J8DEMO0C0MMAND00000008', { kind: 'command', method: null, path: 'orders:reconcile', status: 0, duration_ms: 5200, module: 'Sales', spans_count: 44, queries_count: 120, user_id: null, mode: 'always', created_at: ago(3600) }),
]

/** A plausible, small step tree for the traces that are not the flagship one. */
function simpleSpans(t: TraceSummary): SpanRow[] {
  const total = Math.max(1000, Math.round(t.duration_ms * 1000))
  const name = (t.route_name ?? t.path ?? 'run').replace(/[^A-Za-z.]/g, ' ').split(/[. ]+/).filter(Boolean).map((p) => p[0]!.toUpperCase() + p.slice(1)).join('')
  const failed = (t.status ?? 0) >= 500
  const slow = t.duration_ms >= 500

  if (t.kind !== 'request') {
    return [
      span(1, null, t.kind === 'job' ? 'job' : 'command', t.path ?? t.kind, 0, total, { meta: { summary: t.kind === 'job' ? 'Processed on redis · default' : 'Exit code 0' } }),
      span(2, 1, 'service', `App\\Services\\${name || 'Work'}::handle`, Math.round(total * 0.02), Math.round(total * 0.9), { meta: { summary: 'Returns true' } }),
      span(3, 2, 'query', 'select * from `orders` where `id` = ? limit 1', Math.round(total * 0.05), Math.round(total * 0.1), { connection: 'mysql', meta: { op: 'select', table: 'orders', rows: 1 } }),
      span(4, 2, 'http', 'POST https://mail.example.com/v1/send', Math.round(total * 0.3), Math.round(total * 0.5), { meta: { status: 202, summary: 'Responded 202 Accepted' } }),
    ]
  }

  const spans: SpanRow[] = [
    span(1, null, 'request', `${t.method} ${t.path}`, 0, total, { meta: { summary: `Responds ${t.status}` } }),
    span(2, 1, 'middleware', 'Illuminate\\Auth\\Middleware\\Authenticate', 400, total - 800, { meta: { summary: 'Authenticated as User #42' } }),
    span(3, 2, 'controller', `App\\Http\\Controllers\\${name || 'Page'}Controller@${t.route_name?.split('.').pop() ?? 'index'}`, 1200, total - 2400, { flag: failed ? 'failed' : null, meta: { summary: failed ? 'Threw RuntimeException' : 'Returns view' } }),
    span(4, 3, 'service', `App\\Services\\${name || 'Page'}Service::load`, Math.round(total * 0.05), Math.round(total * 0.8), { meta: { summary: 'Returns array (12)' } }),
    span(5, 4, 'query', 'select * from `orders` where `user_id` = ? order by `id` desc limit 25', Math.round(total * 0.08), Math.round(total * (slow ? 0.55 : 0.2)), { connection: 'mysql', flag: slow ? 'slow' : null, meta: { op: 'select', table: 'orders', rows: 25 } }),
    span(6, 4, 'query', 'select count(*) as aggregate from `orders` where `user_id` = ?', Math.round(total * 0.7), Math.round(total * 0.08), { connection: 'mysql', meta: { op: 'select', table: 'orders', rows: 1 } }),
  ]
  if (failed) {
    spans.push(span(7, 4, 'exception', 'RuntimeException', Math.round(total * 0.6), 0, { flag: 'failed', meta: { summary: 'Invoice 9912 has no ledger entry' }, has_output: false }))
  }

  return spans
}

function simpleProblems(t: TraceSummary): ProblemRow[] {
  if ((t.problem_kinds ?? []).includes('exception_uncaught')) {
    return [{ id: 1, span_id: 7, kind: 'exception_uncaught', message: 'RuntimeException: Invoice 9912 has no ledger entry. Nothing caught it, so the request ended with a 500.', file: 'app/Services/InvoiceService.php', line: 58 }]
  }
  if ((t.problem_kinds ?? []).includes('slow_step')) {
    return [{ id: 1, span_id: 5, kind: 'slow_step', message: 'select * from `orders` … took 682 ms, 55 % of the request. Check for a missing index on user_id.', file: null, line: null }]
  }

  return []
}

function simplePayload(spanId: number): Payload {
  return { input: { value: map([['id', spanId]]) }, output: { value: map([['ok', true]]) } }
}

// ---------------------------------------------------------------------------------------------- the API

const sleep = (ms: number): Promise<void> => new Promise((resolve) => setTimeout(resolve, ms))

export class DemoApi implements Api {
  readonly demo = true

  private watched: WatchEntry[] = [{ user_id: '42', expires_at: Math.floor(NOW / 1000) + 25 * 60 }]

  private queued: QueueEntry[] = []

  async meta(): Promise<Meta> {
    await sleep(20)

    return DEMO_META
  }

  async traces(filters: TraceFilters): Promise<TracePage> {
    await sleep(40)
    const needle = (filters.search ?? '').trim().toLowerCase()
    const data = TRACES.filter((t) => {
      if (filters.has_problems === true && t.problems_count === 0) return false
      if (filters.slow === true && t.duration_ms < DEMO_META.slow_ms) return false
      if (filters.module !== undefined && filters.module !== '' && t.module !== filters.module) return false
      if (filters.tenant !== undefined && filters.tenant !== '' && t.tenant !== filters.tenant) return false
      if (filters.parent_trace_id !== undefined && t.parent_trace_id !== filters.parent_trace_id) return false
      if (needle === '') return true

      return [t.path, t.route_name, t.id, t.method, t.module].some((v) => (v ?? '').toLowerCase().includes(needle))
    })

    return { data, next_cursor: null }
  }

  private find(id: string): TraceSummary {
    const found = TRACES.find((t) => t.id === id)
    if (found === undefined) throw new Error(`No trace ${id} in the demo data.`)

    return found
  }

  async trace(id: string): Promise<TraceSummary> {
    await sleep(20)

    return this.find(id)
  }

  async spans(id: string): Promise<SpanRow[]> {
    await sleep(60)

    return id === ORDER_TRACE_ID ? ORDER_SPANS : simpleSpans(this.find(id))
  }

  async payload(id: string, spanId: number): Promise<Payload> {
    await sleep(30)
    if (id === ORDER_TRACE_ID) return ORDER_PAYLOADS.get(spanId) ?? simplePayload(spanId)

    return simplePayload(spanId)
  }

  async spanLines(id: string, spanId: number): Promise<SpanLines> {
    await sleep(30)
    if (id === ORDER_TRACE_ID && spanId === 7) return { lines: ORDER_LINES, sources: [ORDER_SOURCE] }

    return { lines: [], sources: [] }
  }

  async writes(id: string): Promise<WriteRow[]> {
    await sleep(30)

    return id === ORDER_TRACE_ID ? ORDER_WRITES : []
  }

  async problems(id: string): Promise<ProblemRow[]> {
    await sleep(30)

    return id === ORDER_TRACE_ID ? ORDER_PROBLEMS : simpleProblems(this.find(id))
  }

  async issueToken(options: { ttl?: number; user_id?: string; route?: string }): Promise<IssuedToken> {
    await sleep(30)
    const expires = Math.floor(NOW / 1000) + (options.ttl ?? 300)
    const token = `demo.${expires}.${(options.user_id ?? '*')}.not-a-real-token`

    return { token, expires_at: expires, header: 'X-Request-Tracer', example: `curl -H 'X-Request-Tracer: ${token}' https://shop.example.com/orders` }
  }

  async watchList(): Promise<WatchEntry[]> {
    return this.watched
  }

  async watch(userId: string, minutes: number): Promise<WatchEntry[]> {
    this.watched = [...this.watched.filter((w) => w.user_id !== userId), { user_id: userId, expires_at: Math.floor(Date.now() / 1000) + minutes * 60 }]

    return this.watched
  }

  async unwatch(userId: string): Promise<WatchEntry[]> {
    this.watched = this.watched.filter((w) => w.user_id !== userId)

    return this.watched
  }

  async queue(): Promise<QueueEntry[]> {
    return this.queued
  }

  async queueFile(file: string): Promise<QueueEntry[]> {
    this.queued = [...this.queued.filter((q) => q.file !== file), { file, expires_at: Math.floor(Date.now() / 1000) + 3600 }]

    return this.queued
  }
}
