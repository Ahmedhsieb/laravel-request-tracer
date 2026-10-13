<script setup lang="ts">
import { CircleDot, Clock, Code2, Database, FileCode, HardDrive, Hourglass, MousePointerClick, Pin, PinOff, Sparkles, Wrench } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'
import type { Payload, SpanLines } from '../api/types'
import { formatBytes, formatUs, percent, shortFile } from '../lib/format'
import { TYPE_LABEL, subtitle } from '../lib/spans'
import type { TreeNode } from '../lib/tree'
import { useStore } from '../store'
import Btn from '../ui/Btn.vue'
import Pill from '../ui/Pill.vue'
import Seg from '../ui/Seg.vue'
import TypeIcon from '../ui/TypeIcon.vue'
import CodeView from './CodeView.vue'
import ValueView from './ValueView.vue'

const props = defineProps<{ traceId: string; node: TreeNode | null; pinned: boolean; totalUs: number }>()

const store = useStore()
const view = ref<'io' | 'code'>('io')
const payloads = new Map<number, Payload>()
const lineData = new Map<number, SpanLines>()
const payload = ref<Payload | null>(null)
const lines = ref<SpanLines | null>(null)
const loading = ref(false)
const problem = ref<string | null>(null)
const queued = ref(false)

const span = computed(() => props.node?.span ?? null)
const hasCode = computed(() => span.value !== null && span.value.file !== null && ['service', 'controller', 'middleware', 'validation', 'event', 'job', 'command'].includes(span.value.type))
const hasLines = computed(() => lines.value !== null && lines.value.sources.length > 0)

async function load(): Promise<void> {
  const s = span.value
  payload.value = null
  lines.value = null
  problem.value = null
  queued.value = false
  if (s === null) return

  const id = s.id
  loading.value = true
  try {
    if (view.value === 'code' && hasCode.value) {
      const cached = lineData.get(id) ?? await store.api.spanLines(props.traceId, id)
      lineData.set(id, cached)
      if (span.value?.id === id) lines.value = cached
    } else if (s.has_input || s.has_output) {
      const cached = payloads.get(id) ?? await store.api.payload(props.traceId, id)
      payloads.set(id, cached)
      if (span.value?.id === id) payload.value = cached
    }
  } catch (e) {
    if (span.value?.id === id) problem.value = e instanceof Error ? e.message : String(e)
  } finally {
    if (span.value?.id === id) loading.value = false
  }
}

watch([() => span.value?.id, view], () => void load(), { immediate: true })
// a step with no code of its own has nothing to show on the code side
watch(hasCode, (yes) => {
  if (!yes) view.value = 'io'
})

async function queueFile(): Promise<void> {
  const file = span.value?.file
  if (file === null || file === undefined) return
  try {
    await store.api.queueFile(file)
    queued.value = true
    store.toast('Queued: the next request that runs this file is traced line by line')
  } catch (e) {
    problem.value = e instanceof Error ? e.message : String(e)
  }
}

const facts = computed(() => {
  const s = span.value
  const n = props.node
  if (s === null || n === null) return []
  const out: Array<{ k: string; v: string; icon: typeof Clock }> = [
    { k: 'Time', v: formatUs(s.duration_us), icon: Clock },
    { k: 'Self', v: formatUs(n.selfUs), icon: Hourglass },
    { k: 'Share', v: `${percent(s.duration_us, props.totalUs).toFixed(1)} %`, icon: CircleDot },
  ]
  if (s.memory_delta !== 0) out.push({ k: 'Memory', v: `${s.memory_delta > 0 ? '+' : ''}${formatBytes(s.memory_delta)}`, icon: HardDrive })
  if (s.connection !== null) out.push({ k: 'Connection', v: s.connection, icon: Database })
  if (s.count > 1) out.push({ k: 'Runs', v: `${s.count}×`, icon: Sparkles })

  return out
})

const viewOptions = [{ value: 'io' as const, label: 'Input / Output' }, { value: 'code' as const, label: 'Inside the code' }]
</script>

<template>
  <aside class="flex h-full min-h-0 flex-col overflow-hidden rounded-xl border border-line bg-surface/80" aria-label="Details of the selected step">
    <div v-if="span === null" class="grid h-full place-items-center p-8 text-center">
      <div class="max-w-[300px]">
        <MousePointerClick class="mx-auto mb-3 size-9 text-faint" />
        <p class="font-medium text-fg">Hover a step to preview it</p>
        <p class="mt-1 text-muted">Click a step to pin it. You will see what went in, what came out, and for traced code every line.</p>
      </div>
    </div>

    <template v-else>
      <div :key="span.id" class="shrink-0 animate-fade-in space-y-2.5 border-b border-line px-4 py-3.5">
        <div class="flex items-start gap-2.5">
          <span class="mt-0.5 grid size-8 shrink-0 place-items-center rounded-lg" :style="{ background: `color-mix(in srgb, var(--t-${span.type}) 15%, transparent)` }"><TypeIcon :type="span.type" class="!size-4" /></span>
          <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
              <Pill :color="`var(--t-${span.type})`">{{ TYPE_LABEL[span.type] }}</Pill>
              <span v-if="span.flag" class="font-mono text-[10.5px] font-semibold uppercase" :class="span.flag === 'failed' ? 'text-bad' : 'text-warn'">{{ span.flag }}</span>
            </div>
            <h2 class="mt-1 break-words font-mono text-[13.5px] font-semibold leading-snug">{{ span.label }}</h2>
            <p v-if="subtitle(span)" class="mt-0.5 text-[12.5px] text-muted">{{ subtitle(span) }}</p>
          </div>
          <span class="flex shrink-0 items-center gap-1 rounded-md px-1.5 py-1 text-[10.5px]" :class="pinned ? 'bg-[var(--accent)]/15 text-accent-ink' : 'text-faint'" :title="pinned ? 'Pinned: click the step again to release it' : 'Previewing: click the step to pin it'">
            <component :is="pinned ? Pin : PinOff" class="size-3" />{{ pinned ? 'pinned' : 'preview' }}
          </span>
        </div>

        <div class="flex flex-wrap gap-1.5">
          <span v-for="f in facts" :key="f.k" class="inline-flex items-center gap-1.5 rounded-lg border border-line bg-raised/70 px-2 py-1 text-[11.5px]"><component :is="f.icon" class="size-3 text-faint" /><span class="text-muted">{{ f.k }}</span><span class="font-mono font-medium text-fg">{{ f.v }}</span></span>
          <span v-if="span.file" class="inline-flex items-center gap-1.5 rounded-lg border border-line bg-raised/70 px-2 py-1 font-mono text-[11.5px] text-muted" :title="span.file"><FileCode class="size-3 text-faint" />{{ shortFile(span.file, span.line) }}</span>
        </div>

        <Seg v-if="hasCode" v-model="view" label="What to show" :options="viewOptions" />
      </div>

      <div class="min-h-0 flex-1 overflow-auto p-4">
        <p v-if="problem" class="rounded-lg border border-bad/40 bg-bad/10 px-3 py-2 text-bad" role="alert">{{ problem }}</p>
        <div v-else-if="loading" class="space-y-3" aria-busy="true">
          <div class="skeleton h-5 w-3/4"></div>
          <div class="skeleton h-24 w-full"></div>
          <div class="skeleton h-5 w-1/2"></div>
        </div>

        <template v-else-if="view === 'io' || !hasCode">
          <div v-if="payload && (payload.input || payload.output)" class="space-y-4 animate-fade-in">
            <ValueView v-if="payload.input" title="Input" :value="payload.input.value" />
            <ValueView v-if="payload.output" title="Output" :value="payload.output.value" />
          </div>
          <p v-else-if="!span.has_input && !span.has_output" class="text-muted">No input or output was captured for this kind of step.</p>
          <p v-else class="text-muted">The input and output of this step were not stored (the limits for this request were reached, or the value could not be read).</p>
        </template>

        <template v-else>
          <CodeView v-if="hasLines && lines" :key="span.id" :data="lines" :node="node" :trace-id="traceId" />
          <div v-else class="space-y-3 rounded-xl border border-dashed border-line-strong bg-raised/50 p-5">
            <p class="flex items-center gap-2 font-semibold"><Code2 class="size-4 text-accent-ink" />This step was not traced line by line.</p>
            <p v-if="store.meta?.deep_trace.enabled === false" class="text-muted">Deep trace is switched off in the configuration (<code>request-tracer.deep_trace.enabled</code>).</p>
            <p v-else class="text-muted">
              Deep trace shows every condition, block and variable of a function. Queue this file and the next request that runs it is traced line by line.
              The file is recompiled for a moment and nothing is changed on disk.
            </p>
            <Btn v-if="store.meta?.deep_trace.enabled !== false && span.file" variant="soft" size="md" :disabled="queued" @click="queueFile"><Wrench class="size-3.5" />{{ queued ? 'Queued: send the request again' : 'Trace this file line by line' }}</Btn>
          </div>
        </template>
      </div>
    </template>
  </aside>
</template>
