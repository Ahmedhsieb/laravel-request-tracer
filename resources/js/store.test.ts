import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { DEMO_META } from './api/demo'
import type { Api, JournalEntry, JournalFilters, JournalPage } from './api/types'
import { createStore } from './store'

function entry(n: number, over: Partial<JournalEntry> = {}): JournalEntry {
  return {
    id: `e${String(n).padStart(3, '0')}`, method: 'GET', url: `/orders/${n}`, route_name: null, status: 200, duration_ms: 10, user_id: null, failure: null,
    has_body: false, body_cut: false, masked: false, trace_id: null, created_at: '2026-10-13T10:00:00+00:00', ...over,
  }
}

/** A journal of 120 requests, newest first, served 50 at a time the way the server does; `more` entries can be "made" later. */
function fakeApi() {
  const all: JournalEntry[] = Array.from({ length: 120 }, (_, i) => entry(120 - i))
  const calls: Array<{ filters: JournalFilters; cursor: string | null | undefined }> = []
  const api = {
    demo: true,
    meta: () => Promise.resolve(DEMO_META),
    traces: () => Promise.resolve({ data: [], next_cursor: null }),
    journal(filters: JournalFilters, cursor?: string | null): Promise<JournalPage> {
      calls.push({ filters, cursor })
      const from = cursor === null || cursor === undefined ? 0 : Number(cursor)
      const matching = all.filter((e) => filters.status !== 'failed' || e.failure !== null)

      return Promise.resolve({ data: matching.slice(from, from + 50), next_cursor: from + 50 < matching.length ? String(from + 50) : null })
    },
  } as unknown as Api

  return { api, all, calls, requestMade: (n: number) => all.unshift(entry(n)) }
}

describe('the journal list', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  it('keeps the pages that were loaded when the refresh looks for new requests', async () => {
    const { api, requestMade } = fakeApi()
    const store = createStore(api, '', { ...DEMO_META, poll_seconds: 2 })
    store.start()
    store.showView('journal')
    await vi.advanceTimersByTimeAsync(0)
    expect(store.journal).toHaveLength(50)

    await store.moreJournal()
    expect(store.journal).toHaveLength(100)

    // two refreshes later the loaded page is still there
    await vi.advanceTimersByTimeAsync(4_100)
    expect(store.journal).toHaveLength(100)
    expect(store.journal[99]?.id).toBe('e021')

    // and a request made since is put on top, the rest untouched
    requestMade(121)
    await vi.advanceTimersByTimeAsync(2_100)
    expect(store.journal).toHaveLength(101)
    expect(store.journal[0]?.id).toBe('e121')
    expect(store.journal[100]?.id).toBe('e021')
    store.stop()
  })

  it('does not load the same page twice and does not repeat an entry', async () => {
    const { api, calls } = fakeApi()
    const store = createStore(api, '', { ...DEMO_META, poll_seconds: 60 })
    await store.reloadJournal()

    await Promise.all([store.moreJournal(), store.moreJournal()])

    expect(calls.filter((c) => c.cursor !== undefined)).toHaveLength(1)
    expect(new Set(store.journal.map((e) => e.id)).size).toBe(store.journal.length)
  })

  it('starts over when the filter changes, and drops a page that was still on its way', async () => {
    const { api } = fakeApi()
    const store = createStore(api, '', { ...DEMO_META, poll_seconds: 60 })
    await store.reloadJournal()
    const slow = store.moreJournal()

    store.journalStatus = 'failed'
    await store.reloadJournal()
    await slow

    expect(store.journal).toHaveLength(0)             // nothing failed in the fake journal: the old page did not come back
    expect(store.journalCursor).toBeNull()
  })
})
