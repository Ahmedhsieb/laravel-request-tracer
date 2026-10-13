<script setup lang="ts">
import { useMediaQuery } from '@vueuse/core'
import {
  Activity, ChevronUp, Clock, Cpu, Database, Flame, Layers, Link, ListTree, RotateCcw, Table2, TriangleAlert,
} from 'lucide-vue-next'
import { Motion } from 'motion-v'
import { SplitterGroup, SplitterPanel, SplitterResizeHandle, TabsContent, TabsList, TabsRoot, TabsTrigger } from 'reka-ui'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch, type Component } from 'vue'
import type { ProblemRow, SpanRow, SpanType, TraceSummary, WriteRow } from '../api/types'
import { absoluteTime, formatBytes, formatMs, formatUs, plural, shortClass } from '../lib/format'
import { emptyForm, formFromRequest, type ReplayForm } from '../lib/replay'
import { TYPE_LABEL, methodTone, statusTone, timeByType } from '../lib/spans'
import type { Range } from '../lib/timeline'
import { useFlow } from '../lib/flow'
import { folderRange } from '../lib/folder'
import { buildTree, findNode, type TreeNode } from '../lib/tree'
import { ui } from '../lib/ui'
import { useStore } from '../store'
import Btn from '../ui/Btn.vue'
import Counter from '../ui/Counter.vue'
import Pill from '../ui/Pill.vue'
import Tip from '../ui/Tip.vue'
import DatabaseTab from './DatabaseTab.vue'
import FlowBar from './FlowBar.vue'
import GraphView from './GraphView.vue'
import ProblemsTab from './ProblemsTab.vue'
import SpanTree from './SpanTree.vue'
import StepDetail from './StepDetail.vue'
import TimeMap from './TimeMap.vue'
import UndoBanner from './UndoBanner.vue'

const props = defineProps<{ traceId: string }>()

const store = useStore()
type Tab = 'flow' | 'database' | 'problems'

const trace = ref<TraceSummary | null>(null)
const spans = ref<SpanRow[]>([])
const writes = ref<WriteRow[]>([])
const problems = ref<ProblemRow[]>([])
const children = ref<TraceSummary[]>([])
const loading = ref(true)
const error = ref<string | null>(null)
const tab = ref<Tab>('flow')
const hoverId = ref<number | null>(null)
const pinnedId = ref<number | null>(null)
const entering = ref(true)
const tree = ref<InstanceType<typeof SpanTree> | null>(null)
const range = ref<Range>({ from: 0, to: 1 })
let enteringTimer: ReturnType<typeof setTimeout> | null = null
const stacked = useMediaQuery('(max-width: 1180px)')

const roots = computed<TreeNode[]>(() => buildTree(spans.value))
const slowMs = computed(() => store.meta?.slow_step_ms ?? 100)
const flow = useFlow(roots, slowMs)
const { types } = flow
const totalUs = computed(() => Math.max(roots.value[0]?.span.duration_us ?? 0, (trace.value?.duration_ms ?? 0) * 1000, 1))
const slices = computed(() => timeByType(roots.value, totalUs.value))
const sliceLabel = computed(() => `Time by type: ${slices.value.map((s) => `${TYPE_LABEL[s.type]} ${formatUs(s.us)}`).join(', ')}`)
const shownId = computed(() => pinnedId.value ?? hoverId.value)
const shownNode = computed(() => (shownId.value === null ? null : findNode(roots.value, shownId.value)))
const marker = computed(() => (shownNode.value === null ? null : { start: shownNode.value.span.start_us, duration: shownNode.value.span.duration_us }))
const tablesWritten = computed(() => trace.value?.tables_written?.length ?? new Set(writes.value.map((w) => w.table)).size)

// the figures above the flow take room the flow can use: they fold away, and the choice is remembered (storage may be missing)
const SUMMARY_KEY = 'request-tracer:summary'
const overview = ref(initialOverview())

function initialOverview(): boolean {
  try {
    const saved = window.localStorage.getItem(SUMMARY_KEY)
    if (saved === 'open' || saved === 'closed') return saved === 'open'
  } catch {
    // not remembered
  }

  return window.innerHeight >= 1000
}

function toggleOverview(): void {
  overview.value = !overview.value
  try {
    window.localStorage.setItem(SUMMARY_KEY, overview.value ? 'open' : 'closed')
  } catch {
    // not remembered
  }
}

const tabs = computed(() => [
  { id: 'flow' as const, label: 'Flow', icon: Activity, count: spans.value.length, hot: false },
  { id: 'database' as const, label: 'Database', icon: Database, count: tablesWritten.value, hot: false },
  { id: 'problems' as const, label: 'Problems', icon: Flame, count: problems.value.length, hot: problems.value.length > 0 },
])

interface Tile {
  key: string
  label: string
  icon: Component
  value: number
  format: (n: number) => string
  tone?: 'warn'
}

const tiles = computed<Tile[]>(() => {
  const t = trace.value
  if (t === null) return []
  const count = (n: number): string => String(Math.round(n))

  return [
    { key: 'time', label: 'Time', icon: Clock, value: t.duration_ms, format: formatMs },
    { key: 'memory', label: 'Memory', icon: Cpu, value: t.memory_peak, format: formatBytes },
    { key: 'queries', label: 'Queries', icon: Database, value: t.queries_count, format: count },
    { key: 'steps', label: 'Steps', icon: ListTree, value: t.spans_count, format: count },
    { key: 'problems', label: 'Problems', icon: TriangleAlert, value: t.problems_count, format: count, tone: t.problems_count > 0 ? 'warn' : undefined },
    { key: 'tables', label: 'Tables written', icon: Table2, value: tablesWritten.value, format: count },
  ]
})

const statusColor = computed(() => ({ ok: 'var(--ok)', info: 'var(--info)', warn: 'var(--warn)', bad: 'var(--bad)', muted: 'var(--faint)' })[statusTone(trace.value?.status ?? null)])
const METHOD_COLOR: Record<string, string> = { get: 'var(--ok)', post: 'var(--info)', put: 'var(--warn)', delete: 'var(--bad)', other: 'var(--muted)' }
const methodColor = computed(() => (trace.value?.kind === 'request' ? (METHOD_COLOR[methodTone(trace.value.method)] ?? 'var(--muted)') : `var(--t-${trace.value?.kind === 'job' ? 'job' : 'command'})`))

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
    range.value = { from: 0, to: Math.max(...s.map((x) => x.start_us + x.duration_us), t.duration_ms * 1000, 1) }
    entering.value = true
    enteringTimer = setTimeout(() => {
      entering.value = false
    }, 1400)
  } catch (e) {
    error.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
  // open the folder of the step that is shown first (the controller, with what it did inside), otherwise a long middleware chain hides everything
  flow.reset()
  if (pinnedId.value !== null) flow.reveal(pinnedId.value, true)
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
  flow.reveal(id)
  await nextTick()
  void tree.value?.scrollTo(id)
}

/** Pin a step without toggling it off (the open folder in the path above the steps). */
function pin(id: number): void {
  pinnedId.value = id
}

function toggleType(type: SpanType): void {
  flow.toggleType(type)
}

// opening a folder puts its own stretch of time on the axis: its steps fill the lane instead of a sliver of the request
watch(flow.folder, (folder) => {
  range.value = folderRange(folder, totalUs.value)
})

async function copyLink(): Promise<void> {
  try {
    await navigator.clipboard.writeText(`${window.location.origin}${store.basePath}/trace/${props.traceId}`)
    store.toast('Link copied')
  } catch {
    store.toast('Copy failed: copy the address from the browser bar')
  }
}

// what this request offers to the command palette: its steps, and the way to jump to one
watch(spans, (list) => {
  ui.steps = list.map((s) => ({ id: s.id, label: shortClass(s.label), hint: `${TYPE_LABEL[s.type]} · ${formatUs(s.duration_us)}`, type: s.type }))
}, { immediate: true })

onMounted(() => {
  ui.reveal = (id) => void show(id)
  void load()
})
onBeforeUnmount(() => {
  if (enteringTimer !== null) clearTimeout(enteringTimer)
  ui.steps = []
  ui.reveal = null
})
</script>

<template>
  <div v-if="loading" class="space-y-4 p-6" aria-busy="true">
    <div class="skeleton h-8 w-2/5"></div>
    <div class="grid grid-cols-3 gap-3 lg:grid-cols-6"><div v-for="n in 6" :key="n" class="skeleton h-[68px]"></div></div>
    <div class="skeleton h-3 w-full"></div>
    <div class="skeleton h-[360px] w-full"></div>
  </div>

  <div v-else-if="error" class="grid h-full place-items-center p-6 text-center" role="alert">
    <div>
      <TriangleAlert class="mx-auto mb-3 size-9 text-bad" />
      <p class="text-bad">{{ error }}</p>
      <div class="mt-4 flex justify-center gap-2"><Btn @click="load">Try again</Btn><Btn variant="ghost" @click="store.close()">Back to the list</Btn></div>
    </div>
  </div>

  <div v-else-if="trace" class="flex h-full min-h-0 flex-col gap-3 px-5 pb-4 pt-4 max-md:px-3">
    <!-- the request -->
    <header class="flex flex-col gap-2">
      <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-2">
        <Btn class="md:hidden" size="sm" variant="ghost" @click="store.close()">← All requests</Btn>
        <Pill :color="methodColor" solid>{{ trace.method ?? trace.kind }}</Pill>
        <h1 class="min-w-0 flex-1 truncate font-mono text-[18px] font-semibold tracking-tight" :title="trace.path ?? trace.kind">{{ trace.path ?? trace.kind }}</h1>
        <span v-if="trace.status !== null" class="rounded-lg px-2 py-0.5 font-mono text-[14px] font-bold tabular-nums" :style="{ color: statusColor, background: `color-mix(in srgb, ${statusColor} 14%, transparent)` }">{{ trace.status }}</span>
        <Pill v-if="trace.mode === 'replay'" color="var(--t-event)"><RotateCcw class="size-2.5" />replay</Pill>
        <div class="flex items-center gap-1.5">
          <Tip text="Copy a link to this request"><Btn variant="ghost" size="icon" aria-label="Copy link" @click="copyLink"><Link class="size-4" /></Btn></Tip>
          <Btn v-if="store.meta?.replay.enabled && trace.kind === 'request'" variant="soft" size="sm" @click="replayThis"><RotateCcw class="size-3.5" />Replay this request</Btn>
        </div>
      </div>
      <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[12px] text-muted">
        <span>{{ absoluteTime(trace.created_at) }}</span>
        <span v-if="trace.user_id">User {{ trace.user_id }}</span>
        <span v-if="trace.tenant">Tenant {{ trace.tenant }}</span>
        <span v-if="trace.module">Module {{ trace.module }}</span>
        <span v-if="trace.route_name" class="font-mono">{{ trace.route_name }}</span>
        <template v-if="!overview">
          <span class="font-mono text-fg">{{ formatMs(trace.duration_ms) }}</span>
          <span>{{ plural(trace.queries_count, 'query', 'queries') }}</span>
          <span>{{ plural(trace.spans_count, 'step') }}</span>
          <span v-if="trace.problems_count > 0" class="text-warn">{{ plural(trace.problems_count, 'problem') }}</span>
        </template>
        <span class="font-mono text-faint">{{ trace.id }}</span>
        <button v-if="trace.parent_trace_id" class="text-accent-ink hover:underline" type="button" @click="store.open(trace.parent_trace_id)">Dispatched by a request: open it</button>
        <button v-for="c in children" :key="c.id" class="rounded-md bg-raised px-1.5 py-0.5 text-fg hover:bg-hover" type="button" @click="store.open(c.id)">Started: {{ shortClass(c.path ?? c.kind) }} ({{ formatMs(c.duration_ms) }})</button>
      </div>
      <UndoBanner v-if="trace.mode === 'replay' && store.meta?.replay.enabled" :key="trace.id" :trace-id="trace.id" />
      <p v-if="trace.truncated" class="rounded-lg border border-warn/40 bg-warn/10 px-3 py-2 text-[12px] text-warn" role="status">
        This trace hit the step limit, so the busiest parts were merged or left out. Raise <code>request-tracer.limits.max_spans</code> to keep more.
      </p>
    </header>

    <!-- the figures, and where the time went -->
    <section v-if="overview" class="shrink-0 space-y-3">
      <div class="grid grid-cols-3 gap-2.5 xl:grid-cols-6" role="list">
        <Motion
          v-for="(m, i) in tiles"
          :key="m.key"
          role="listitem"
          :initial="{ opacity: 0, y: 12 }"
          :animate="{ opacity: 1, y: 0 }"
          :transition="{ delay: i * 0.045, type: 'spring', stiffness: 220, damping: 24 }"
          class="glass relative overflow-hidden rounded-xl border border-line px-3 py-2.5"
        >
          <div class="flex items-center gap-1.5 text-[11px] font-medium uppercase tracking-wide text-faint"><component :is="m.icon" class="size-3.5" :class="m.tone === 'warn' ? 'text-warn' : ''" />{{ m.label }}</div>
          <div class="mt-1 font-mono text-[20px] font-semibold leading-none tracking-tight" :class="m.tone === 'warn' ? 'text-warn' : 'text-fg'"><Counter :value="m.value" :format="m.format" /></div>
        </Motion>
      </div>

      <div class="glass rounded-xl border border-line p-3">
        <div class="mb-2 flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-faint"><Layers class="size-3.5" />Where the time went <span class="font-normal normal-case tracking-normal text-faint">click a kind to filter the steps</span></div>
        <div class="flex h-3.5 gap-[3px] overflow-hidden rounded-full" role="img" :aria-label="sliceLabel">
          <Tip v-for="(s, i) in slices" :key="s.type" :text="`${TYPE_LABEL[s.type]}: ${formatUs(s.us)} (${s.pct.toFixed(1)} %)`">
            <button
              type="button"
              class="h-full origin-left animate-grow-x rounded-[4px] outline-none transition-[filter,opacity] hover:brightness-125"
              :class="types.size > 0 && !types.has(s.type) ? 'opacity-35' : ''"
              :style="{ width: `${Math.max(s.pct, 0.8)}%`, background: `var(--t-${s.type})`, animationDelay: `${i * 70}ms` }"
              :aria-label="`${TYPE_LABEL[s.type]} ${formatUs(s.us)}`"
              @click="toggleType(s.type)"
            ></button>
          </Tip>
        </div>
        <ul class="mt-2.5 flex flex-wrap gap-x-4 gap-y-1 text-[11.5px] text-muted">
          <li v-for="s in slices" :key="s.type"><button type="button" class="flex items-center gap-1.5 hover:text-fg" @click="toggleType(s.type)"><span class="size-2 rounded-[3px]" :style="{ background: `var(--t-${s.type})` }"></span>{{ TYPE_LABEL[s.type] }} <span class="font-mono text-fg/80">{{ formatUs(s.us) }}</span></button></li>
        </ul>
      </div>
    </section>

    <!-- the sections -->
    <TabsRoot v-model="tab" class="flex min-h-0 flex-1 flex-col">
      <div class="flex items-center border-b border-line">
        <TabsList class="flex gap-1" aria-label="Request sections">
          <TabsTrigger v-for="t in tabs" :key="t.id" :value="t.id" class="relative flex h-10 items-center gap-2 px-3.5 font-medium text-muted outline-none transition-colors hover:text-fg data-[state=active]:text-fg">
            <component :is="t.icon" class="size-3.5" />{{ t.label }}
            <span class="rounded-full px-1.5 font-mono text-[10.5px] font-semibold" :class="t.hot ? 'bg-warn/20 text-warn' : 'bg-hover text-muted'">{{ t.count }}</span>
            <Motion v-if="tab === t.id" layout-id="trace-tab-underline" class="absolute inset-x-2 -bottom-px h-[2px] rounded-full grad-bg" :transition="{ type: 'spring', stiffness: 500, damping: 36 }" />
          </TabsTrigger>
        </TabsList>
        <span class="flex-1"></span>
        <Tip :text="overview ? 'Hide the figures and the time bar' : 'Show the figures and the time bar'">
          <Btn variant="ghost" size="xs" :aria-expanded="overview" @click="toggleOverview"><ChevronUp class="size-3.5 transition-transform duration-300" :class="overview ? '' : 'rotate-180'" />{{ overview ? 'Hide overview' : 'Show overview' }}</Btn>
        </Tip>
      </div>

      <TabsContent value="flow" class="mt-3 flex min-h-0 flex-1 flex-col gap-3 outline-none">
        <TimeMap v-model:range="range" :roots="roots" :total-us="totalUs" :marker="marker" class="shrink-0" />
        <SplitterGroup :direction="stacked ? 'vertical' : 'horizontal'" auto-save-id="request-tracer:flow" class="min-h-0 flex-1">
          <SplitterPanel :default-size="52" :min-size="30" class="min-h-0 min-w-0">
            <div class="flex h-full min-h-0 flex-col overflow-hidden rounded-xl border border-line bg-surface/80">
              <FlowBar :flow="flow" @select="pin" />
              <SpanTree
                v-if="flow.view.value === 'waterfall'"
                ref="tree"
                :flow="flow"
                :total-us="totalUs"
                :pinned="pinnedId"
                :entering="entering"
                :range="range"
                @hover="hoverId = $event"
                @select="select"
              />
              <GraphView v-else :flow="flow" :pinned="pinnedId" @hover="hoverId = $event" @select="select" />
            </div>
          </SplitterPanel>
          <SplitterResizeHandle class="group relative outline-none transition-colors" :class="stacked ? 'h-3' : 'w-3'" aria-label="Resize the steps and the details">
            <span class="absolute rounded-full bg-line transition-colors group-hover:bg-accent group-data-[resize-handle-state=drag]:bg-accent" :class="stacked ? 'inset-x-0 top-1/2 h-px' : 'inset-y-0 left-1/2 w-px'"></span>
          </SplitterResizeHandle>
          <SplitterPanel :min-size="25" class="min-h-0 min-w-0">
            <StepDetail :trace-id="trace.id" :node="shownNode" :pinned="pinnedId !== null && shownId === pinnedId" :total-us="totalUs" />
          </SplitterPanel>
        </SplitterGroup>
      </TabsContent>

      <TabsContent value="database" class="mt-3 min-h-0 flex-1 overflow-auto outline-none">
        <DatabaseTab :writes="writes" :spans="spans" :trace="trace" :mask="store.meta?.mask ?? '••••••••'" @show="show" />
      </TabsContent>

      <TabsContent value="problems" class="mt-3 min-h-0 flex-1 overflow-auto outline-none">
        <ProblemsTab :problems="problems" :spans="spans" :notes="trace.notes" @show="show" />
      </TabsContent>
    </TabsRoot>
  </div>
</template>
