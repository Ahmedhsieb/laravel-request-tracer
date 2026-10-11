import type {
  Api, IssuedToken, JournalEntry, JournalFilters, JournalPage, Meta, Payload, ProblemRow, QueueEntry, ReplayInput, ReplayResult, SpanLines, SpanRow, TraceFilters, TracePage, TraceSummary, WatchEntry, WriteRow,
} from './types'

/** What the Blade view puts on `window.__REQUEST_TRACER__`. */
export interface Bootstrap {
  basePath: string
  apiUrl: string
  csrf: string
  meta: Meta
}

declare global {
  interface Window {
    __REQUEST_TRACER__?: Bootstrap
  }
}

export class ApiError extends Error {
  constructor(readonly status: number, message: string) {
    super(message)
    this.name = 'ApiError'
  }
}

/** Talks to the package's JSON API with the session cookie of the logged-in user. */
export class HttpApi implements Api {
  readonly demo = false

  constructor(private readonly base: string, private readonly csrf: string, private readonly initialMeta: Meta | null = null) {}

  private async request<T>(method: string, path: string, query?: Record<string, string | undefined>, body?: unknown): Promise<T> {
    const url = new URL(this.base + path, window.location.origin)
    for (const [key, value] of Object.entries(query ?? {})) {
      if (value !== undefined && value !== '') url.searchParams.set(key, value)
    }

    const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    if (method !== 'GET') headers['X-CSRF-TOKEN'] = this.csrf
    if (body !== undefined) headers['Content-Type'] = 'application/json'

    const response = await fetch(url, {
      method,
      credentials: 'same-origin',
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
    })

    if (!response.ok) {
      let message = `${response.status} ${response.statusText}`
      try {
        const json = (await response.json()) as { message?: string; data?: { error?: string } }
        // a replay that failed answers 422 with the reason in data.error
        if (typeof json.data?.error === 'string' && json.data.error !== '') message = json.data.error
        else if (typeof json.message === 'string' && json.message !== '') message = json.message
      } catch {
        // not JSON: keep the status line
      }
      throw new ApiError(response.status, message)
    }

    return (await response.json()) as T
  }

  private async data<T>(method: string, path: string, query?: Record<string, string | undefined>, body?: unknown): Promise<T> {
    return (await this.request<{ data: T }>(method, path, query, body)).data
  }

  async meta(): Promise<Meta> {
    return this.initialMeta ?? (await this.request<Meta>('GET', '/meta'))
  }

  traces(filters: TraceFilters, cursor?: string | null): Promise<TracePage> {
    return this.request<TracePage>('GET', '/traces', {
      search: filters.search,
      has_problems: filters.has_problems === true ? '1' : undefined,
      slow: filters.slow === true ? '1' : undefined,
      module: filters.module,
      tenant: filters.tenant,
      parent_trace_id: filters.parent_trace_id,
      cursor: cursor ?? undefined,
    })
  }

  trace(id: string): Promise<TraceSummary> {
    return this.data('GET', `/traces/${encodeURIComponent(id)}`)
  }

  spans(id: string): Promise<SpanRow[]> {
    return this.data('GET', `/traces/${encodeURIComponent(id)}/spans`)
  }

  payload(id: string, span: number): Promise<Payload> {
    return this.data('GET', `/traces/${encodeURIComponent(id)}/spans/${span}`)
  }

  spanLines(id: string, span: number): Promise<SpanLines> {
    return this.data('GET', `/traces/${encodeURIComponent(id)}/spans/${span}/lines`)
  }

  writes(id: string): Promise<WriteRow[]> {
    return this.data('GET', `/traces/${encodeURIComponent(id)}/writes`)
  }

  problems(id: string): Promise<ProblemRow[]> {
    return this.data('GET', `/traces/${encodeURIComponent(id)}/problems`)
  }

  journal(filters: JournalFilters, cursor?: string | null): Promise<JournalPage> {
    return this.request<JournalPage>('GET', '/journal', { status: filters.status, search: filters.search, cursor: cursor ?? undefined })
  }

  journalEntry(id: string): Promise<JournalEntry> {
    return this.data('GET', `/journal/${encodeURIComponent(id)}`)
  }

  async clearJournal(): Promise<void> {
    await this.request('DELETE', '/journal')
  }

  replay(input: ReplayInput): Promise<ReplayResult> {
    return this.data('POST', '/replay', undefined, input)
  }

  issueToken(options: { ttl?: number; user_id?: string; route?: string }): Promise<IssuedToken> {
    return this.data('POST', '/tokens', undefined, options)
  }

  watchList(): Promise<WatchEntry[]> {
    return this.data('GET', '/watch')
  }

  watch(userId: string, minutes: number): Promise<WatchEntry[]> {
    return this.data('POST', '/watch', undefined, { user_id: userId, minutes })
  }

  unwatch(userId: string): Promise<WatchEntry[]> {
    return this.data('DELETE', `/watch/${encodeURIComponent(userId)}`)
  }

  queue(): Promise<QueueEntry[]> {
    return this.data('GET', '/deep-trace/queue')
  }

  queueFile(file: string): Promise<QueueEntry[]> {
    return this.data('POST', '/deep-trace/queue', undefined, { file })
  }
}
