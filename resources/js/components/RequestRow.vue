<script setup lang="ts">
import { Flame, RotateCcw } from 'lucide-vue-next'
import { Motion } from 'motion-v'
import { computed } from 'vue'
import type { TraceSummary } from '../api/types'
import { formatMs, relativeTime } from '../lib/format'
import { methodTone, problemNote, statusTone } from '../lib/spans'
import { useStore } from '../store'
import Pill from '../ui/Pill.vue'

const props = defineProps<{ trace: TraceSummary; slowMs: number; now: Date; max: number; indent?: boolean }>()

const store = useStore()
const selected = computed(() => store.selected === props.trace.id)
const fresh = computed(() => store.fresh.has(props.trace.id))
const note = computed(() => problemNote(props.trace, props.slowMs))
const tone = computed(() => statusTone(props.trace.status))
const isRequest = computed(() => props.trace.kind === 'request')
const label = computed(() => (props.trace.kind === 'request' ? (props.trace.method ?? '') : props.trace.kind))
const path = computed(() => (props.trace.kind === 'request' ? (props.trace.path ?? '/') : (props.trace.path ?? props.trace.kind)))
/** how long it took against the slowest request on the screen: a square root, so the quick ones are not all hairlines */
const heat = computed(() => Math.max(3, Math.min(100, Math.sqrt(props.trace.duration_ms / Math.max(props.max, 1)) * 100)))

const METHOD_COLOR: Record<string, string> = { get: 'var(--ok)', post: 'var(--info)', put: 'var(--warn)', delete: 'var(--bad)', other: 'var(--muted)' }
const methodColor = computed(() => (isRequest.value ? METHOD_COLOR[methodTone(props.trace.method)] : `var(--t-${props.trace.kind === 'job' ? 'job' : 'command'})`))
const statusColor = computed(() => ({ ok: 'var(--ok)', info: 'var(--info)', warn: 'var(--warn)', bad: 'var(--bad)', muted: 'var(--faint)' })[tone.value])
const heatColor = computed(() => (props.trace.problems_count > 0 || tone.value === 'bad' ? 'var(--bad)' : props.trace.duration_ms >= props.slowMs ? 'var(--warn)' : 'var(--accent)'))
</script>

<template>
  <button
    class="group relative block w-full overflow-hidden border-b border-line/70 px-3 py-2 text-left outline-none transition-colors hover:bg-hover focus-visible:bg-hover"
    :class="[selected ? 'bg-hover' : '', fresh ? 'animate-flash' : '', indent ? 'pl-6' : '']"
    type="button"
    :aria-current="selected ? 'true' : undefined"
    @click="store.open(trace.id)"
  >
    <Motion v-if="selected" layout-id="request-selected" class="absolute inset-y-1.5 left-0 w-[3px] rounded-r-full grad-bg" :transition="{ type: 'spring', stiffness: 520, damping: 38 }" />

    <div class="flex items-center gap-2">
      <Pill :color="methodColor">{{ label }}</Pill>
      <span class="min-w-0 flex-1 truncate font-mono text-[12.5px] text-fg" :title="path">{{ path }}</span>
      <span class="shrink-0 font-mono text-[12px] font-semibold tabular-nums" :style="{ color: statusColor }">{{ trace.status ?? (isRequest ? '–' : '') }}</span>
    </div>

    <div class="mt-1 flex items-center gap-2 text-[11.5px]">
      <Pill v-if="trace.mode === 'replay'" color="var(--t-event)"><RotateCcw class="size-2.5" />replay</Pill>
      <span v-if="note" class="inline-flex items-center gap-1 truncate" :class="note.tone === 'bad' ? 'text-bad' : 'text-warn'"><Flame class="size-3 shrink-0" />{{ note.text }}</span>
      <span v-else class="truncate text-faint">{{ trace.module ?? trace.route_name ?? '' }}</span>
      <span class="ml-auto shrink-0 tabular-nums text-muted"><span class="font-mono text-fg/90">{{ formatMs(trace.duration_ms) }}</span> · {{ relativeTime(trace.created_at, now) }}</span>
    </div>

    <div class="mt-1.5 h-[3px] overflow-hidden rounded-full bg-line/70" aria-hidden="true">
      <div class="h-full origin-left animate-grow-x rounded-full" :style="{ width: `${heat}%`, background: heatColor, opacity: 0.85 }"></div>
    </div>
  </button>
</template>
