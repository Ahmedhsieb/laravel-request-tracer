<script lang="ts">
const counter = { last: 0 }
</script>

<script setup lang="ts">
import { computed, inject, nextTick, onBeforeUnmount, onMounted, provide, ref, shallowRef, watch } from 'vue'
import type { SpanLines } from '../api/types'
import { buildCode, executionOrder, explainUntraced, linkCalls, mainBlock, outsideCodePaths, suggestPath, type CodeLine, type DotState } from '../lib/code'
import { CODE_CONTEXT, CODE_POPOVER, type CodeContext, type Edge, type LinePopoverApi } from '../lib/codeContext'
import { plural } from '../lib/format'
import type { TreeNode } from '../lib/tree'
import { useStore } from '../store'
import ChildCode from './ChildCode.vue'
import CodeBlockView from './CodeBlockView.vue'
import LinePopover from './LinePopover.vue'

const props = defineProps<{ data: SpanLines; node: TreeNode | null; traceId?: string; nested?: boolean }>()

const uid = ++counter.last

const store = useStore()
const parentCtx = inject(CODE_CONTEXT, null)
const sharedPopover = inject(CODE_POPOVER, null)
const traceId = props.traceId ?? parentCtx?.traceId ?? ''

const blocks = computed(() => buildCode(props.data))
const main = computed(() => mainBlock(blocks.value, props.node?.span.file ?? null, props.node?.span.line ?? null))

/**
 * Traces recorded before a controller's action was folded into the controller step have the action as a step of its own, a child with the
 * same file and line, and the lines of the action under that child. Show that child as the code of the step.
 */
const duplicate = computed(() => {
  const span = props.node?.span
  if (main.value >= 0 || span === undefined || span.file === null) return null

  return props.node?.children.find((c) => c.span.file === span.file && c.span.line === span.line) ?? null
})

/** Who calls whom, only among the functions that ran in this step: the step's own function, what it calls, what those call. */
const plan = computed(() => {
  const all = blocks.value
  const parent = new Map<number, number | null>(main.value >= 0 ? [[main.value, null]] : [])
  const edges = new Map<number, Map<number, Edge[]>>()
  const steps = (props.node?.children ?? [])
    .filter((c) => c.span.file !== null && ['service', 'validation', 'job', 'event', 'command', 'middleware', 'controller'].includes(c.span.type))
    .map((c) => ({ id: c.span.id, name: c.span.label, node: c }))
  const takenSteps = new Set<number>()
  const queue = main.value >= 0 ? [main.value] : []
  while (queue.length > 0) {
    const from = queue.shift() as number
    const block = all[from]
    if (block === undefined) continue
    const byLine = new Map<number, Edge[]>()
    const add = (line: number, edge: Edge): void => {
      const list = byLine.get(line)
      if (list === undefined) byLine.set(line, [edge])
      else list.push(edge)
    }
    const candidates = all.map((b, id) => ({ id, name: b.name })).filter((c) => !parent.has(c.id))
    for (const link of linkCalls(block, candidates)) {
      parent.set(link.target, from)
      queue.push(link.target)
      add(link.line, { kind: 'block', target: link.target })
    }
    for (const link of linkCalls(block, steps.filter((s) => !takenSteps.has(s.id)))) {
      takenSteps.add(link.target)
      const step = steps.find((s) => s.id === link.target)
      if (step !== undefined) add(link.line, { kind: 'span', id: step.id, name: step.name, node: step.node })
    }
    edges.set(from, byLine)
  }
  const others = all.map((_, i) => i).filter((i) => !parent.has(i))

  return { parent, edges, others }
})

const open = ref<ReadonlySet<number>>(new Set(main.value >= 0 ? [main.value] : []))
watch(() => props.data, () => {
  open.value = new Set(main.value >= 0 ? [main.value] : [])
})

function toggle(block: number): void {
  popover.hide()                                               // the line it pointed at may be about to disappear
  const next = new Set(open.value)
  if (next.has(block)) next.delete(block)
  else next.add(block)
  open.value = next
}

// ---- the output of a line: one pop-up for the whole code view, however deep the nesting goes
const ownPopover = sharedPopover === null
const activeKey = ref<string | null>(null)
const pinned = ref(false)
const popLine = shallowRef<CodeLine | null>(null)
const popAnchor = shallowRef<HTMLElement | null>(null)
let hideTimer: ReturnType<typeof setTimeout> | null = null

function cancelHide(): void {
  if (hideTimer !== null) clearTimeout(hideTimer)
  hideTimer = null
}

const popover: LinePopoverApi = sharedPopover ?? {
  activeKey,
  pinned,
  show(key, line, element, pin) {
    cancelHide()
    if (pinned.value && !pin) return                       // a line that was clicked stays until it is closed
    activeKey.value = key
    popLine.value = line
    popAnchor.value = element
    pinned.value = pin
  },
  hide() {
    cancelHide()
    activeKey.value = null
    popLine.value = null
    popAnchor.value = null
    pinned.value = false
  },
  release() {
    if (pinned.value) return
    cancelHide()
    hideTimer = setTimeout(() => popover.hide(), 220)
  },
}
if (ownPopover) provide(CODE_POPOVER, popover)


/** The step's own file is outside `code.paths`: say so, with the line to add. */
const outside = computed(() => {
  const file = props.node?.span.file ?? null
  const paths = store.meta?.code.paths ?? []
  if (props.nested || file === null || !outsideCodePaths(file, paths)) return null

  return { file, suggestion: [...paths, suggestPath(file)].map((p) => `'${p}'`).join(', ') }
})
/** Why the step's own function shows no run data, when it shows none. */
const untraced = computed(() => (blocks.value[main.value]?.untraced === true ? explainUntraced(outside.value !== null, props.data.lines.length) : null))
const queued = ref(false)
const queueProblem = ref<string | null>(null)

async function queueFile(): Promise<void> {
  const file = props.node?.span.file
  if (file === null || file === undefined) return
  try {
    await store.api.queueFile(file)
    queued.value = true
    store.toast('Queued: the next request that runs this file is traced line by line')
  } catch (e) {
    queueProblem.value = e instanceof Error ? e.message : String(e)
  }
}

const context: CodeContext = {
  uid,
  traceId,
  blocks,
  open,
  main,
  untraced,
  edges: computed(() => plan.value.edges),
  parent: computed(() => plan.value.parent),
  toggle,
  popover,
}
provide(CODE_CONTEXT, context)

function onKey(event: KeyboardEvent): void {
  if (event.key === 'Escape' && pinned.value) popover.hide()
}

function onPointer(event: MouseEvent): void {
  const target = event.target as HTMLElement | null
  if (pinned.value && target !== null && target.closest('.line-popover, .cl') === null) popover.hide()
}

onMounted(() => {
  if (!ownPopover) return
  document.addEventListener('keydown', onKey)
  document.addEventListener('mousedown', onPointer)
})

// ---- the replay of the execution, over the functions that are open
const root = ref<HTMLElement | null>(null)
const position = ref(-1)
const playing = ref(false)
let timer: ReturnType<typeof setInterval> | null = null

/** A function's lines are on screen when it is open and so is everything it was reached through. */
function visible(block: number): boolean {
  if (!open.value.has(block)) return false
  const from = plan.value.parent.get(block)
  if (from === undefined) return showOthers.value             // one of "the other code"
  return from === null || visible(from)
}

const steps = computed(() => executionOrder(blocks.value).filter((s) => visible(s.block)))

const legend: Array<{ state: DotState; text: string; color: string }> = [
  { state: 'ran', text: 'ran / true', color: 'var(--green)' },
  { state: 'false', text: 'false', color: 'var(--purple)' },
  { state: 'threw', text: 'threw', color: 'var(--red)' },
  { state: 'caught', text: 'caught', color: 'var(--orange)' },
  { state: 'loop', text: 'loop', color: 'var(--blue)' },
  { state: 'notrun', text: 'not run', color: '#5b667a' },
]

async function goto(index: number): Promise<void> {
  const step = steps.value[index]
  if (step === undefined) return
  position.value = index
  await nextTick()
  const element = root.value?.querySelector<HTMLElement>(`[data-line="${uid}:${step.block}:${step.line}"]`)
  const line = blocks.value[step.block]?.lines[step.line]
  if (element === null || element === undefined || line === undefined) return
  element.scrollIntoView({ block: 'center' })
  await nextTick()
  popover.show(`${uid}:${step.block}:${step.line}`, line, element, true)
}

function stop(): void {
  playing.value = false
  if (timer !== null) clearInterval(timer)
  timer = null
}

function next(): void {
  if (position.value < steps.value.length - 1) void goto(position.value + 1)
  else stop()
}

function back(): void {
  stop()
  if (position.value > 0) void goto(position.value - 1)
}

function replay(): void {
  if (playing.value) {
    stop()
    return
  }
  if (position.value >= steps.value.length - 1) position.value = -1
  playing.value = true
  next()
  timer = setInterval(next, 900)
}

const progress = computed(() => (steps.value.length === 0 ? 0 : ((position.value + 1) / steps.value.length) * 100))
// with nothing of its own to show first, the other functions are all there is: show them
const showOthers = ref(main.value < 0 && duplicate.value === null)
const reached = computed(() => plan.value.parent.size)

onBeforeUnmount(() => {
  stop()
  cancelHide()
  if (ownPopover) {
    document.removeEventListener('keydown', onKey)
    document.removeEventListener('mousedown', onPointer)
  }
})
</script>

<template>
  <div ref="root" class="code-view" :class="{ nested }">
    <p v-if="!nested" class="hint code-hint">
      The code of this step, with the functions it called under the line that calls them. Hover a line to see what happened there; click it to keep the pop-up open.
    </p>

    <div v-if="untraced === 'not-run'" class="info-card scope-card" role="note">
      <strong>This function did not run in this request.</strong>
      <p>
        The step started, but the request left before the first line of <code>{{ node?.span.label }}</code>: nothing was recorded for it, and its text is shown below as it is in the file.
        What ran under the step is listed under <em>The code that ran during this step</em>. A <code>302</code> or <code>422</code> with a failed <em>validation</em> step in the flow, or an authorization
        that refused, are the usual reasons: open the Problems tab and the validation step.
      </p>
    </div>

    <div v-else-if="untraced === 'no-data'" class="info-card scope-card" role="note">
      <strong>No line was recorded for this step.</strong>
      <p>
        The request was recorded without line-by-line tracing, so only the text of the function is shown. Start <code>php artisan request-tracer:listen</code> before the request,
        or queue this file and send the request again; the file is recompiled for a moment and nothing is changed on disk.
      </p>
      <button v-if="store.meta?.deep_trace.enabled !== false" class="btn" type="button" :disabled="queued" @click="queueFile">{{ queued ? 'Queued: send the request again' : 'Trace this file line by line' }}</button>
      <p v-if="queueProblem" class="tone-bad" role="alert">{{ queueProblem }}</p>
    </div>

    <div v-if="outside" class="info-card scope-card" role="note">
      <strong>This step's own code was not traced line by line.</strong>
      <p>
        <code>{{ outside.file }}</code> is outside <code>request-tracer.code.paths</code> (<code>{{ (store.meta?.code.paths ?? []).join(', ') || 'empty' }}</code>), so only its text is shown,
        without what ran. To trace it, add its folder and send the request again:
      </p>
      <p><code>'code' => ['paths' => [{{ outside.suggestion }}]]</code></p>
    </div>

    <ChildCode v-if="duplicate" :node="duplicate" :name="duplicate.span.label" :auto-open="true" />
    <CodeBlockView v-if="main >= 0" :index="main" :depth="0" />

    <div v-if="plan.others.length > 0" class="others">
      <button class="others-toggle" type="button" :aria-expanded="showOthers" @click="showOthers = !showOthers">
        <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true"><path d="M5 3l4 4-4 4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" /></svg>
        {{ main >= 0 || duplicate ? 'Other code that ran during this step' : 'The code that ran during this step' }}
        <span class="count">{{ plan.others.length }}</span>
        <span v-if="reached > 0" class="muted">{{ plural(reached, 'function') }} above are called from the step itself</span>
      </button>
      <template v-if="showOthers">
        <p class="hint">{{ main >= 0 || duplicate ? 'Functions the step did not call by name (framework hooks, constructors, callbacks, base classes).' : 'The function of the step itself has no lines here: these are the functions that ran under it (constructors, base classes, hooks).' }}</p>
        <CodeBlockView v-for="i in plan.others" :key="i" :index="i" :depth="0" />
      </template>
    </div>

    <template v-if="!nested">
      <div class="replay" role="group" aria-label="Replay the execution">
        <button class="btn small" type="button" :disabled="steps.length === 0" @click="replay">{{ playing ? 'Pause' : (position >= 0 ? 'Resume' : 'Replay') }}</button>
        <button class="btn small" type="button" :disabled="position <= 0" @click="back">Back</button>
        <button class="btn small" type="button" :disabled="position >= steps.length - 1" @click="stop(); next()">Next</button>
        <div class="progress" role="progressbar" :aria-valuenow="Math.max(0, position + 1)" aria-valuemin="0" :aria-valuemax="steps.length" aria-label="Replay position"><i :style="{ width: `${progress}%` }"></i></div>
        <span class="muted mono">{{ Math.max(0, position + 1) }} / {{ steps.length }}</span>
      </div>

      <ul class="dots-legend" aria-label="Legend">
        <li v-for="item in legend" :key="item.state" style="list-style: none"><span><i :style="{ '--dc': item.color }"></i>{{ item.text }}</span></li>
      </ul>
    </template>

    <LinePopover
      v-if="ownPopover && popLine && popAnchor"
      :line="popLine"
      :anchor="popAnchor"
      :pinned="pinned"
      @close="popover.hide()"
      @hold="cancelHide()"
      @release="popover.release()"
    />
  </div>
</template>
