<script setup lang="ts">
import { computed } from 'vue'
import type { TraceSummary } from '../api/types'
import { formatMs } from '../lib/format'
import { statusTone } from '../lib/spans'
import { useStore } from '../store'

/** The last requests as a row of bars, oldest to newest: height is the time it took, colour is how it went. A click opens one. */
const props = defineProps<{ traces: TraceSummary[]; slowMs: number }>()
const store = useStore()

const bars = computed(() => {
  const shown = props.traces.slice(0, 40).reverse()
  const max = Math.max(1, ...shown.map((t) => t.duration_ms))

  return shown.map((t, i) => ({
    id: t.id,
    height: Math.max(12, Math.round(Math.sqrt(t.duration_ms / max) * 100)),
    color: t.problems_count > 0 || statusTone(t.status) === 'bad' ? 'var(--bad)' : statusTone(t.status) === 'warn' || t.duration_ms >= props.slowMs ? 'var(--warn)' : 'var(--accent)',
    title: `${t.method ?? t.kind} ${t.path ?? ''} · ${t.status ?? ''} · ${formatMs(t.duration_ms)}`.trim(),
    delay: i * 14,
  }))
})
</script>

<template>
  <div v-if="bars.length > 1" class="flex h-9 items-end gap-[3px] px-1" role="group" aria-label="The latest requests: height is the time they took">
    <button
      v-for="b in bars"
      :key="b.id"
      type="button"
      class="group/bar relative min-w-[3px] flex-1 origin-bottom animate-grow-y rounded-[3px] outline-none transition-[filter,transform] duration-150 hover:-translate-y-0.5 hover:brightness-125 focus-visible:brightness-125"
      :class="store.selected === b.id ? 'ring-1 ring-fg/70' : ''"
      :style="{ height: `${b.height}%`, background: `linear-gradient(to top, color-mix(in srgb, ${b.color} 55%, transparent), ${b.color})`, animationDelay: `${b.delay}ms` }"
      :title="b.title"
      :aria-label="b.title"
      @click="store.open(b.id)"
    ></button>
  </div>
</template>
