// Shapes of the JSON API (docs/api.md). Keep in sync with src/Http/Controllers.

export type SpanType =
  | 'request' | 'command' | 'middleware' | 'controller' | 'validation' | 'service' | 'query'
  | 'model' | 'http' | 'event' | 'job' | 'exception' | 'cache' | 'log'

export type SpanFlag = 'slow' | 'repeated' | 'caught' | 'failed'

export type RecordingMode = 'always' | 'triggered' | 'off'

export interface Meta {
  name: string
  version: string
  environment: string
  is_production: boolean
  mode: RecordingMode
  enabled: boolean
  /** somebody is running `php artisan request-tracer:listen` right now */
  listening: boolean
  listen_command: string
  replay: { enabled: boolean }
  code: { paths: string[]; except: string[]; line_by_line: boolean }
  recording: {
    mode: RecordingMode
    header: string
    watch_list: boolean
    sampling: { enabled: boolean; rate: number }
    token_ttl: number
  }
  user: { id: string | number; name: string } | null
  slow_ms: number
  poll_seconds: number
  mask: string
  deep_trace: { enabled: boolean }
  limits: { max_spans: number; max_hits: number }
}

export interface WrittenTable {
  connection: string | null
  table: string
  insert: number
  update: number
  delete: number
}

export interface Note {
  kind: string
  target: string
  message: string
}

export interface TraceSummary {
  id: string
  kind: 'request' | 'job' | 'command' | 'schedule'
  method: string | null
  path: string | null
  route_name: string | null
  status: number | null
  duration_ms: number
  memory_peak: number
  user_id: string | null
  tenant: string | null
  module: string | null
  spans_count: number
  queries_count: number
  problems_count: number
  tables_written: WrittenTable[] | null
  tables_read: Record<string, { queries: number; rows: number }> | null
  notes: Note[] | null
  truncated: boolean
  laravel_version: string | null
  environment: string | null
  mode: string | null
  parent_trace_id: string | null
  created_at: string
  problem_kinds?: string[]
}

export interface TracePage {
  data: TraceSummary[]
  next_cursor: string | null
}

export interface SpanRow {
  id: number
  parent_id: number | null
  type: SpanType
  label: string
  file: string | null
  line: number | null
  start_us: number
  duration_us: number
  memory_delta: number
  flag: SpanFlag | null
  connection: string | null
  count: number
  meta: Record<string, unknown> | null
  has_input: boolean
  has_output: boolean
}

/** The value format produced by the server-side dumper (docs/05-how-capture-works.md). */
export type Dump =
  | null
  | boolean
  | number
  | string
  | { $: 'arr'; n: number; list?: boolean; i?: Array<Dump | [string | number, Dump]>; cut?: number }
  | { $: 'obj'; c: string; kind?: string; i?: Array<[string, string, Dump]>; cut?: number }
  | { $: 'str'; v: string; cut: number }
  | { $: 'mask' }
  | { $: 'type'; t: string; size?: number }
  | { $: 'bin'; len: number }
  | { $: 'float'; v: string }
  | { $: 'int'; v: string }

export interface Payload {
  input: { value: Dump } | null
  output: { value: Dump } | null
}

export type WriteOperation = 'insert' | 'update' | 'delete'

export interface WriteRow {
  id: number
  span_id: number | null
  connection: string | null
  table: string
  operation: WriteOperation
  row_key: string | null
  before: Record<string, unknown> | null
  after: Record<string, unknown> | null
  transaction: 'none' | 'open' | 'committed' | 'rolled_back' | null
  rows: number | null
}

export type ProblemKind =
  | 'exception_caught' | 'exception_uncaught' | 'n_plus_one' | 'repeated_query'
  | 'slow_query' | 'slow_step' | 'http_failed' | 'truncated'

export interface ProblemRow {
  id: number
  span_id: number | null
  kind: ProblemKind
  message: string
  file: string | null
  line: number | null
}

export type LineKind = 'cond' | 'block' | 'assign' | 'loop' | 'return' | 'throw'

export interface LineResult {
  seq?: number
  fn?: [string, number, number]
  name?: string
  true?: number
  false?: number
  samples?: Array<{ a: Dump; b: Dump; op: string | null; result: boolean | null }>
  values?: Record<string, number>
  last?: Dump
  first?: Dump
  changes?: number
  iterations?: number
  snapshots?: Dump[]
  value?: Dump
  exception?: Dump
  caught?: Dump
}

export interface LineRow {
  span_id: number | null
  file: string
  line: number
  kind: LineKind
  hits: number
  result: LineResult | null
  snippet: string | null
}

export interface SourceFunction {
  file: string
  name: string
  start: number
  end: number
  lines: Array<{ n: number; text: string }>
  /** lines that carry a probe (only those can be "not run"); null when the file could not be analysed */
  statements?: number[] | null
  missing: boolean
  changed_since_trace: boolean
}

export interface SpanLines {
  lines: LineRow[]
  sources: SourceFunction[]
}

/** What the replay dialog sends: a pasted cURL command, or the pieces of a request. */
export interface ReplayInput {
  curl?: string
  method?: string
  url?: string
  headers?: Record<string, string>
  body?: string
  as_user?: string
}

export interface ReplayResult {
  trace_id: string | null
  status: number | null
  error: string | null
}

export interface IssuedToken {
  token: string
  expires_at: number
  header: string
  example: string
}

export interface WatchEntry {
  user_id: string
  expires_at: number
}

export interface QueueEntry {
  file: string
  expires_at: number
}

export interface TraceFilters {
  search?: string
  has_problems?: boolean
  slow?: boolean
  module?: string
  tenant?: string
  /** only the traces dispatched by this trace (jobs of a request) */
  parent_trace_id?: string
}

/** Everything the UI needs from the server. Implemented by HttpApi (real) and DemoApi (seeded data). */
export interface Api {
  readonly demo: boolean
  meta(): Promise<Meta>
  traces(filters: TraceFilters, cursor?: string | null): Promise<TracePage>
  trace(id: string): Promise<TraceSummary>
  spans(id: string): Promise<SpanRow[]>
  payload(id: string, span: number): Promise<Payload>
  spanLines(id: string, span: number): Promise<SpanLines>
  writes(id: string): Promise<WriteRow[]>
  problems(id: string): Promise<ProblemRow[]>
  /** Run a request that already happened again, traced; database changes are rolled back. */
  replay(input: ReplayInput): Promise<ReplayResult>
  issueToken(options: { ttl?: number; user_id?: string; route?: string }): Promise<IssuedToken>
  watchList(): Promise<WatchEntry[]>
  watch(userId: string, minutes: number): Promise<WatchEntry[]>
  unwatch(userId: string): Promise<WatchEntry[]>
  queue(): Promise<QueueEntry[]>
  queueFile(file: string): Promise<QueueEntry[]>
}
