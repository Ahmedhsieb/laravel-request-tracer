<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { Payload, SpanLines } from '../api/types'
import { formatBytes, formatUs, percent, shortFile } from '../lib/format'
import { TYPE_LABEL, subtitle } from '../lib/spans'
import type { TreeNode } from '../lib/tree'
import { useStore } from '../store'
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
  const out: Array<{ k: string; v: string }> = [
    { k: 'Time', v: formatUs(s.duration_us) },
    { k: 'Self', v: formatUs(n.selfUs) },
    { k: 'Share', v: `${percent(s.duration_us, props.totalUs).toFixed(1)} %` },
  ]
  if (s.memory_delta !== 0) out.push({ k: 'Memory', v: `${s.memory_delta > 0 ? '+' : ''}${formatBytes(s.memory_delta)}` })
  if (s.connection !== null) out.push({ k: 'Connection', v: s.connection })
  if (s.count > 1) out.push({ k: 'Runs', v: `${s.count}×` })

  return out
})
</script>

<template>
  <aside class="detail" aria-label="Details of the selected step">
    <div v-if="span === null" class="empty">
      <p><strong>Hover a step to preview it.</strong></p>
      <p>Click a step to pin it. You will see what went in, what came out, and for traced code every line.</p>
    </div>

    <template v-else>
      <div class="detail-head">
        <div class="title">
          <span class="badge" :style="{ '--badge': `var(--t-${span.type})` }">{{ TYPE_LABEL[span.type] }}</span>
          <strong>{{ span.label }}</strong>
        </div>
        <div v-if="subtitle(span)" class="muted">{{ subtitle(span) }}</div>
        <div class="facts">
          <span v-for="f in facts" :key="f.k">{{ f.k }} <span class="mono">{{ f.v }}</span></span>
        </div>
        <div v-if="span.file" class="facts"><span class="mono">{{ shortFile(span.file, span.line) }}</span></div>
        <div class="hint">{{ pinned ? 'Pinned: click the step again to release it.' : 'Previewing: click the step to pin it.' }}</div>
      </div>

      <div v-if="hasCode" class="segmented" role="group" aria-label="What to show">
        <button type="button" :aria-pressed="view === 'io'" @click="view = 'io'">Input / Output</button>
        <button type="button" :aria-pressed="view === 'code'" @click="view = 'code'">Inside the code</button>
      </div>

      <div class="detail-body">
        <p v-if="problem" class="tone-bad" role="alert">{{ problem }}</p>
        <div v-else-if="loading" aria-busy="true">
          <div class="skeleton" style="width: 90%; margin: 14px 0 10px"></div>
          <div class="skeleton" style="width: 70%"></div>
        </div>

        <template v-else-if="view === 'io' || !hasCode">
          <div v-if="payload && (payload.input || payload.output)" class="values">
            <ValueView v-if="payload.input" title="Input" :value="payload.input.value" />
            <ValueView v-if="payload.output" title="Output" :value="payload.output.value" />
          </div>
          <p v-else-if="!span.has_input && !span.has_output" class="muted" style="margin: 14px 0 0">No input or output was captured for this kind of step.</p>
          <p v-else class="muted" style="margin: 14px 0 0">The input and output of this step were not stored (the limits for this request were reached, or the value could not be read).</p>
        </template>

        <template v-else>
          <CodeView v-if="hasLines && lines" :data="lines" />
          <div v-else class="info-card">
            <strong>This step was not traced line by line.</strong>
            <p v-if="store.meta?.deep_trace.enabled === false">Deep trace is switched off in the configuration (<code>request-tracer.deep_trace.enabled</code>).</p>
            <p v-else>
              Deep trace shows every condition, block and variable of a function. Queue this file and the next request that runs it is traced line by line.
              The file is recompiled for a moment and nothing is changed on disk.
            </p>
            <button v-if="store.meta?.deep_trace.enabled !== false && span.file" class="btn" type="button" :disabled="queued" @click="queueFile">
              {{ queued ? 'Queued: send the request again' : 'Trace this file line by line' }}
            </button>
          </div>
        </template>
      </div>
    </template>
  </aside>
</template>
