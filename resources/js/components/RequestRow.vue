<script setup lang="ts">
import type { TraceSummary } from '../api/types'
import { formatMs, relativeTime } from '../lib/format'
import { methodTone, problemNote, statusTone } from '../lib/spans'
import { useStore } from '../store'

const props = defineProps<{ trace: TraceSummary; slowMs: number; now: Date }>()

const store = useStore()

function title(): string {
  const t = props.trace
  if (t.kind === 'request') return `${t.method ?? ''} ${t.path ?? ''}`.trim()

  return `${t.kind} ${t.path ?? ''}`.trim()
}

function status(): string {
  const t = props.trace

  return t.status === null ? (t.kind === 'request' ? '–' : '') : String(t.status)
}
</script>

<template>
  <button class="req" type="button" :class="{ fresh: store.fresh.has(trace.id) }" :aria-current="store.selected === trace.id" @click="store.open(trace.id)">
    <span class="badge method" :class="methodTone(trace.method)">{{ trace.method ?? trace.kind }}</span>
    <span class="path" :title="title()">{{ trace.kind === 'request' ? trace.path : (trace.path ?? trace.kind) }}</span>
    <span class="status" :class="`tone-${statusTone(trace.status)}`">{{ status() }}</span>
    <span class="note">
      <span v-if="trace.mode === 'replay'" class="badge" style="--badge: var(--purple); margin-right: 6px">replay</span>
      <template v-if="problemNote(trace, slowMs)">
        <span :class="`tone-${problemNote(trace, slowMs)?.tone}`">{{ problemNote(trace, slowMs)?.text }}</span>
      </template>
      <span v-else class="faint">{{ trace.module ?? trace.route_name ?? '' }}</span>
    </span>
    <span class="time"><span class="dur">{{ formatMs(trace.duration_ms) }}</span> · {{ relativeTime(trace.created_at, now) }}</span>
  </button>
</template>
