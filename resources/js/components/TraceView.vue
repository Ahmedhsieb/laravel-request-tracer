<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import type { ProblemRow, SpanRow, TraceSummary, WriteRow } from '../api/types'
import { absoluteTime, formatBytes, formatMs, formatUs, plural, shortClass } from '../lib/format'
import { TYPE_LABEL, methodTone, statusTone, timeByType, typeVar } from '../lib/spans'
import { emptyForm, formFromRequest, type ReplayForm } from '../lib/replay'
import { buildTree, findNode, type TreeNode } from '../lib/tree'
import { useStore } from '../store'
import DatabaseTab from './DatabaseTab.vue'
import ProblemsTab from './ProblemsTab.vue'
import SpanTree from './SpanTree.vue'
import StepDetail from './StepDetail.vue'
import UndoBanner from './UndoBanner.vue'

const props = defineProps<{ traceId: string }>()

const store = useStore()
type Tab = 'flow' | 'database' | 'problems'

const trace = ref<TraceSummary | null>(null)
const spans = ref<SpanRow[]>([])
const writes = ref<WriteRow[]>([])
const problems = ref<ProblemRow[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const tab = ref<Tab>('flow')
const hoverId = ref<number | null>(null)
const pinnedId = ref<number | null>(null)
const entering = ref(true)
const tree = ref<InstanceType<typeof SpanTree> | null>(null)
let enteringTimer: ReturnType<typeof setTimeout> | null = null

const roots = computed<TreeNode[]>(() => buildTree(spans.value))
const totalUs = computed(() => Math.max(roots.value[0]?.span.duration_us ?? 0, (trace.value?.duration_ms ?? 0) * 1000, 1))
const slices = computed(() => timeByType(roots.value, totalUs.value))
const sliceLabel = computed(() => `Time by type: ${slices.value.map((s) => `${TYPE_LABEL[s.type]} ${formatUs(s.us)}`).join(', ')}`)
const shownId = computed(() => pinnedId.value ?? hoverId.value)
const shownNode = computed(() => (shownId.value === null ? null : findNode(roots.value, shownId.value)))
const tablesWritten = computed(() => trace.value?.tables_written?.length ?? new Set(writes.value.map((w) => w.table)).size)
const children = ref<TraceSummary[]>([])
// the figures above the flow take room the flow can use: they fold away, and the choice is remembered (storage may be missing)
const SUMMARY_KEY = 'request-tracer:summary'
const summaryOpen = ref(initialSummary())

function initialSummary(): boolean {
  try {
    const saved = window.localStorage.getItem(SUMMARY_KEY)
    if (saved === 'open' || saved === 'closed') return saved === 'open'
  } catch {
    // not remembered
  }

  return window.innerHeight >= 1000
}

function toggleSummary(): void {
  summaryOpen.value = !summaryOpen.value
  try {
    window.localStorage.setItem(SUMMARY_KEY, summaryOpen.value ? 'open' : 'closed')
  } catch {
    // not remembered
  }
}

const tabs = computed(() => [
  { id: 'flow' as const, label: 'Flow', count: spans.value.length, hot: false },
  { id: 'database' as const, label: 'Database', count: tablesWritten.value, hot: false },
  { id: 'problems' as const, label: 'Problems', count: problems.value.length, hot: problems.value.length > 0 },
])

const metrics = computed(() => {
  const t = trace.value
  if (t === null) return []

  return [
    { k: 'Time', v: formatMs(t.duration_ms) },
    { k: 'Memory', v: formatBytes(t.memory_peak) },
    { k: 'Queries', v: String(t.queries_count) },
    { k: 'Steps', v: String(t.spans_count) },
    { k: 'Problems', v: String(t.problems_count) },
    { k: 'Tables written', v: String(tablesWritten.value) },
  ]
})

async function load(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    const [t, s, w, p] = await Promise.all([
      store.api.trace(props.traceId),
      store.api.spans(props.traceId),
      store.api.writes(props.traceId),
      store.api.problems(props.traceId),
    ])
    trace.value = t
    spans.value = s
    writes.value = w
    problems.value = p
    children.value = []
    void store.api.traces({ parent_trace_id: t.id }).then((page) => {
      children.value = page.data
    }).catch(() => undefined)                       // the link to jobs is a convenience: a failure must not hide the trace
    const initial = s.find((x) => x.meta?.['deep'] === true) ?? s.find((x) => x.type === 'controller')
    pinnedId.value = initial?.id ?? null
    entering.value = true
    enteringTimer = setTimeout(() => {
      entering.value = false
    }, 1400)
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
  // open the branches down to the step that is shown first, otherwise a long middleware chain hides everything
  if (pinnedId.value !== null) {
    await nextTick()
    await tree.value?.reveal(pinnedId.value, true)
  }
}

/** Open the replay dialog filled in with this request (the recorded method, path, query, headers and body). */
async function replayThis(): Promise<void> {
  const t = trace.value
  if (t === null) return
  const root = spans.value.find((s) => s.parent_id === null)
  let form: ReplayForm | null = null
  try {
    const payload = root === undefined ? null : await store.api.payload(t.id, root.id)
    form = payload?.input ? formFromRequest(payload.input.value, t.user_id) : null
  } catch {
    // fall back to what the list knows about the request
  }
  store.openReplay(form ?? { ...emptyForm(), method: t.method ?? 'GET', url: t.path ?? '/', asUser: t.user_id ?? '' })
}

function select(id: number): void {
  pinnedId.value = pinnedId.value === id ? null : id
}

async function show(id: number): Promise<void> {
  tab.value = 'flow'
  pinnedId.value = id
  await nextTick()
  await tree.value?.reveal(id)
}

async function copyLink(): Promise<void> {
  try {
    await navigator.clipboard.writeText(`${window.location.origin}${store.basePath}/trace/${props.traceId}`)
    store.toast('Link copied')
  } catch {
    store.toast('Copy failed: copy the address from the browser bar')
  }
}

function onTabKey(event: KeyboardEvent): void {
  const order = tabs.value.map((t) => t.id)
  const at = order.indexOf(tab.value)
  const delta = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0
  if (delta === 0) return
  event.preventDefault()
  tab.value = order[(at + delta + order.length) % order.length] as Tab
  void nextTick(() => document.getElementById(`tab-${tab.value}`)?.focus())
}

onMounted(() => void load())
onBeforeUnmount(() => {
  if (enteringTimer !== null) clearTimeout(enteringTimer)
})
</script>

<template>
  <div v-if="loading" aria-busy="true">
    <div class="skeleton" style="width: 40%; height: 24px; margin-bottom: 18px"></div>
    <div class="skeleton" style="width: 100%; height: 64px; margin-bottom: 14px"></div>
    <div class="skeleton" style="width: 100%; height: 280px"></div>
  </div>

  <div v-else-if="error" class="error-box" role="alert">
    <p>{{ error }}</p>
    <button class="btn" type="button" @click="load">Try again</button>
    <button class="btn ghost" type="button" @click="store.close()">Back to the list</button>
  </div>

  <div v-else-if="trace" class="trace-view">
    <div class="trace-head">
      <button class="btn small ghost" type="button" @click="store.close()">← All requests</button>
      <span class="badge method" :class="methodTone(trace.method)">{{ trace.method ?? trace.kind }}</span>
      <h1>{{ trace.path ?? trace.kind }}</h1>
      <span v-if="trace.status !== null" class="mono" :class="`tone-${statusTone(trace.status)}`">{{ trace.status }}</span>
      <span v-if="trace.mode === 'replay'" class="badge" style="--badge: var(--purple)" title="This trace is a replay: the request was run again, as a simulation (nothing was changed) or for real (see below)">replay</span>
      <button class="copy btn small ghost" type="button" @click="copyLink">Copy link</button>
      <button v-if="store.meta?.replay.enabled && trace.kind === 'request'" class="btn small" type="button" title="Run this request again, traced from the first line" @click="replayThis">Replay this request</button>
    </div>
    <div class="trace-meta">
      <span>{{ absoluteTime(trace.created_at) }}</span>
      <span v-if="trace.user_id">User {{ trace.user_id }}</span>
      <span v-if="trace.tenant">Tenant {{ trace.tenant }}</span>
      <span v-if="trace.module">Module {{ trace.module }}</span>
      <span v-if="trace.route_name" class="mono">{{ trace.route_name }}</span>
      <template v-if="!summaryOpen">
        <span class="mono">{{ formatMs(trace.duration_ms) }}</span>
        <span>{{ plural(trace.queries_count, 'query', 'queries') }}</span>
        <span>{{ plural(trace.spans_count, 'step') }}</span>
        <span v-if="trace.problems_count > 0" class="tone-warn">{{ plural(trace.problems_count, 'problem') }}</span>
      </template>
      <span class="mono faint">{{ trace.id }}</span>
      <button v-if="trace.parent_trace_id" class="copy" type="button" @click="store.open(trace.parent_trace_id)">Dispatched by a request: open it</button>
    </div>

    <UndoBanner v-if="trace.mode === 'replay' && store.meta?.replay.enabled" :key="trace.id" :trace-id="trace.id" />

    <p v-if="trace.truncated" class="tone-warn" role="status" style="margin: 10px 0 0">
      This trace hit the step limit, so the busiest parts were merged or left out. Raise <code>request-tracer.limits.max_spans</code> to keep more.
    </p>

    <div v-show="summaryOpen" class="metrics" role="list">
      <div v-for="m in metrics" :key="m.k" class="metric" role="listitem"><div class="k">{{ m.k }}</div><div class="v">{{ m.v }}</div></div>
    </div>

    <div v-show="summaryOpen" class="timebar">
      <h2>Where the time went</h2>
      <div class="stack" role="img" :aria-label="sliceLabel">
        <span v-for="(s, i) in slices" :key="s.type" :style="{ '--c': typeVar(s.type), '--i': i, width: `${s.pct}%` }" :title="`${TYPE_LABEL[s.type]}: ${formatUs(s.us)}`"></span>
      </div>
      <ul class="legend">
        <li v-for="s in slices" :key="s.type"><i :style="{ '--c': typeVar(s.type) }"></i>{{ TYPE_LABEL[s.type] }} <span class="mono">{{ formatUs(s.us) }}</span></li>
      </ul>
    </div>

    <div class="tabs" role="tablist" aria-label="Request sections" @keydown="onTabKey">
      <button
        v-for="t in tabs"
        :id="`tab-${t.id}`"
        :key="t.id"
        class="tab"
        type="button"
        role="tab"
        :aria-selected="tab === t.id"
        :aria-controls="`panel-${t.id}`"
        :tabindex="tab === t.id ? 0 : -1"
        @click="tab = t.id"
      >{{ t.label }} <span class="count" :class="{ hot: t.hot }">{{ t.count }}</span></button>
      <span class="tab-spacer"></span>
      <button class="tab summary-toggle" type="button" :aria-expanded="summaryOpen" title="Show or hide the figures and the time bar above" @click="toggleSummary">{{ summaryOpen ? 'Hide summary' : 'Show summary' }}</button>
    </div>

    <div v-show="tab === 'flow'" id="panel-flow" class="panel" role="tabpanel" aria-labelledby="tab-flow">
      <div class="flow">
        <SpanTree ref="tree" :roots="roots" :total-us="totalUs" :pinned="pinnedId" :entering="entering" :slow-ms="store.meta?.slow_step_ms ?? 100" @hover="hoverId = $event" @select="select" />
        <div class="detail-col">
          <StepDetail :trace-id="trace.id" :node="shownNode" :pinned="pinnedId !== null && shownId === pinnedId" :total-us="totalUs" />
          <div v-if="children.length > 0" class="children">
            <span class="muted">Started by this request:</span>
            <button v-for="c in children" :key="c.id" class="btn small" type="button" @click="store.open(c.id)">{{ shortClass(c.path ?? c.kind) }} ({{ formatMs(c.duration_ms) }})</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="tab === 'database'" id="panel-database" class="panel scroll" role="tabpanel" aria-labelledby="tab-database">
      <DatabaseTab :writes="writes" :spans="spans" :trace="trace" :mask="store.meta?.mask ?? '••••••••'" @show="show" />
    </div>

    <div v-if="tab === 'problems'" id="panel-problems" class="panel scroll" role="tabpanel" aria-labelledby="tab-problems">
      <ProblemsTab :problems="problems" :spans="spans" :notes="trace.notes" @show="show" />
    </div>

    <p v-if="tab === 'flow' && trace.notes && trace.notes.length > 0" class="hint" style="margin-top: 12px">
      {{ plural(trace.notes.length, 'note') }} about steps the tracer could not follow: see the Problems tab.
    </p>
  </div>
</template>
