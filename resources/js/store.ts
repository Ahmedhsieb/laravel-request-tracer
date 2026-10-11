import { computed, inject, markRaw, reactive, ref, shallowRef, type InjectionKey } from 'vue'
import type { Api, JournalEntry, Meta, TraceFilters, TraceSummary } from './api/types'
import { formFromJournal, type ReplayForm } from './lib/replay'

export type Chip = 'all' | 'problems' | 'slow'

const TRACE_PATH = /^\/trace\/([A-Za-z0-9_-]+)\/?$/

/**
 * Everything the screens share: the request list (with polling), its filters and the selected trace.
 * The selected trace lives in the URL (`<base>/trace/<id>`) so a request can be linked to and the back button works.
 */
export function createStore(api: Api, basePath: string, initialMeta: Meta | null = null) {
  const meta = shallowRef<Meta | null>(initialMeta)
  const traces = shallowRef<TraceSummary[]>([])
  const fresh = shallowRef<ReadonlySet<string>>(new Set())
  const search = ref('')
  const chip = ref<Chip>('all')
  const module = ref('')
  const loading = ref(false)
  const loadingMore = ref(false)
  const error = ref<string | null>(null)
  const cursor = ref<string | null>(null)
  const selected = ref<string | null>(readSelected())
  const toastText = ref<string | null>(null)
  const replayOpen = ref(false)
  // the request journal: which view of the left pane is open, and its list
  const view = ref<'traces' | 'journal'>('traces')
  const journal = shallowRef<JournalEntry[]>([])
  const journalStatus = ref<'all' | 'failed'>('all')
  const journalSearch = ref('')
  const journalLoading = ref(false)
  const journalCursor = ref<string | null>(null)
  const journalError = ref<string | null>(null)
  const replayInitial = shallowRef<ReplayForm | null>(null)

  let timer: ReturnType<typeof setInterval> | null = null
  let debounce: ReturnType<typeof setTimeout> | null = null
  let toastTimer: ReturnType<typeof setTimeout> | null = null
  let generation = 0

  const modules = computed(() => [...new Set(traces.value.map((t) => t.module).filter((m): m is string => m !== null && m !== ''))].sort())

  function readSelected(): string | null {
    const path = window.location.pathname
    const relative = basePath !== '' && path.startsWith(basePath) ? path.slice(basePath.length) : path

    return TRACE_PATH.exec(relative)?.[1] ?? null
  }

  function filters(): TraceFilters {
    return {
      search: search.value.trim() === '' ? undefined : search.value.trim(),
      has_problems: chip.value === 'problems' ? true : undefined,
      slow: chip.value === 'slow' ? true : undefined,
      module: module.value === '' ? undefined : module.value,
    }
  }

  async function reload(): Promise<void> {
    const mine = ++generation
    loading.value = true
    error.value = null
    try {
      const page = await api.traces(filters())
      if (mine !== generation) return
      traces.value = page.data
      cursor.value = page.next_cursor
    } catch (e) {
      if (mine === generation) error.value = e instanceof Error ? e.message : String(e)
    } finally {
      if (mine === generation) loading.value = false
    }
  }

  async function more(): Promise<void> {
    if (cursor.value === null || loadingMore.value) return
    loadingMore.value = true
    try {
      const page = await api.traces(filters(), cursor.value)
      const known = new Set(traces.value.map((t) => t.id))
      traces.value = [...traces.value, ...page.data.filter((t) => !known.has(t.id))]
      cursor.value = page.next_cursor
    } catch (e) {
      error.value = e instanceof Error ? e.message : String(e)
    } finally {
      loadingMore.value = false
    }
  }

  /** Looks for requests recorded since the last look; new rows slide in at the top. */
  async function poll(): Promise<void> {
    if (document.hidden) return
    if (view.value === 'journal') {
      if (!journalLoading.value) void reloadJournal()

      return
    }
    if (loading.value) return
    const mine = generation
    try {
      const page = await api.traces(filters())
      if (mine !== generation) return
      const known = new Set(traces.value.map((t) => t.id))
      const added = page.data.filter((t) => !known.has(t.id))
      if (added.length === 0) return
      traces.value = [...added, ...traces.value]
      fresh.value = new Set(added.map((t) => t.id))
      setTimeout(() => {
        fresh.value = new Set()
      }, 1500)
    } catch {
      // polling is best effort: a failed refresh must not replace the list with an error
    }
  }

  function start(): void {
    void api.meta().then((m) => {
      meta.value = m
      schedule()
    }).catch((e: unknown) => {
      error.value = e instanceof Error ? e.message : String(e)
    })
    void reload()
    window.addEventListener('popstate', onPop)
  }

  function schedule(): void {
    if (timer !== null) clearInterval(timer)
    const seconds = Math.max(2, meta.value?.poll_seconds ?? 5)
    timer = setInterval(() => void poll(), seconds * 1000)
  }

  function stop(): void {
    if (timer !== null) clearInterval(timer)
    window.removeEventListener('popstate', onPop)
  }

  function onPop(): void {
    selected.value = readSelected()
  }

  function open(id: string): void {
    if (selected.value === id) return
    selected.value = id
    window.history.pushState({}, '', `${basePath}/trace/${encodeURIComponent(id)}`)
  }

  function close(): void {
    selected.value = null
    window.history.pushState({}, '', basePath === '' ? '/' : basePath)
  }

  function refilter(delay = 0): void {
    if (debounce !== null) clearTimeout(debounce)
    debounce = setTimeout(() => void reload(), delay)
  }

  async function reloadJournal(): Promise<void> {
    journalLoading.value = true
    journalError.value = null
    try {
      const page = await api.journal({ status: journalStatus.value === 'failed' ? 'failed' : undefined, search: journalSearch.value.trim() === '' ? undefined : journalSearch.value.trim() })
      journal.value = page.data
      journalCursor.value = page.next_cursor
    } catch (e) {
      journalError.value = e instanceof Error ? e.message : String(e)
    } finally {
      journalLoading.value = false
    }
  }

  async function moreJournal(): Promise<void> {
    if (journalCursor.value === null) return
    try {
      const page = await api.journal({ status: journalStatus.value === 'failed' ? 'failed' : undefined, search: journalSearch.value.trim() === '' ? undefined : journalSearch.value.trim() }, journalCursor.value)
      journal.value = [...journal.value, ...page.data]
      journalCursor.value = page.next_cursor
    } catch (e) {
      journalError.value = e instanceof Error ? e.message : String(e)
    }
  }

  function showView(next: 'traces' | 'journal'): void {
    view.value = next
    if (next === 'journal') void reloadJournal()
  }

  /** Open the replay dialog filled in from a journal entry: the whole entry is fetched (headers and body come only with it). */
  async function replayJournalEntry(entry: JournalEntry): Promise<void> {
    try {
      openReplay(formFromJournal(await api.journalEntry(entry.id)))
    } catch (e) {
      toast(e instanceof Error ? e.message : String(e))
    }
  }

  /** Open the replay dialog, empty or filled in from a recorded request. */
  function openReplay(initial: ReplayForm | null = null): void {
    replayInitial.value = initial
    replayOpen.value = true
  }

  function closeReplay(): void {
    replayOpen.value = false
  }

  function toast(text: string): void {
    toastText.value = text
    if (toastTimer !== null) clearTimeout(toastTimer)
    toastTimer = setTimeout(() => {
      toastText.value = null
    }, 2200)
  }

  // reactive() unwraps the refs, so templates (and callers) use `store.meta`, not `store.meta.value`
  return reactive({ api: markRaw(api), basePath, meta, traces, fresh, search, chip, module, modules, loading, loadingMore, error, cursor, selected, toastText, replayOpen, replayInitial, view, journal, journalStatus, journalSearch, journalLoading, journalCursor, journalError, start, stop, reload, more, open, close, refilter, toast, openReplay, closeReplay, reloadJournal, moreJournal, showView, replayJournalEntry })
}

export type Store = ReturnType<typeof createStore>

export const STORE_KEY: InjectionKey<Store> = Symbol('request-tracer-store')

export function useStore(): Store {
  const store = inject(STORE_KEY)
  if (store === undefined) throw new Error('The store was not provided.')

  return store
}
