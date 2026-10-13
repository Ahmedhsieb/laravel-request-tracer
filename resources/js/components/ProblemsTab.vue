<script setup lang="ts">
import { computed } from 'vue'
import type { Note, ProblemKind, ProblemRow, SpanRow } from '../api/types'
import { Crosshair, ShieldCheck } from 'lucide-vue-next'
import { shortClass, shortFile } from '../lib/format'
import { problemTitle } from '../lib/spans'
import Btn from '../ui/Btn.vue'
import Pill from '../ui/Pill.vue'

const props = defineProps<{ problems: ProblemRow[]; spans: SpanRow[]; notes: Note[] | null }>()
const emit = defineEmits<{ show: [id: number] }>()

const labels = computed(() => new Map(props.spans.map((s) => [s.id, shortClass(s.label)])))

const SEVERITY: Record<ProblemKind, 'bad' | 'warn' | 'info'> = {
  exception_uncaught: 'bad',
  http_failed: 'bad',
  n_plus_one: 'warn',
  repeated_query: 'warn',
  slow_query: 'warn',
  slow_step: 'warn',
  exception_caught: 'info',
  truncated: 'info',
}

function severity(kind: ProblemKind): 'bad' | 'warn' | 'info' {
  return SEVERITY[kind] ?? 'warn'
}

const COLOR = { bad: 'var(--bad)', warn: 'var(--warn)', info: 'var(--info)' } as const

const SEVERITY_WORD = { bad: 'Error', warn: 'Warning', info: 'Notice' } as const
</script>

<template>
  <div class="space-y-4 pb-6">
    <div v-if="problems.length === 0" class="grid place-items-center rounded-xl border border-dashed border-line-strong px-6 py-14 text-center text-muted">
      <ShieldCheck class="mb-3 size-10 text-ok" />
      <p class="text-[15px] font-semibold text-ok">No problems found.</p>
      <p class="mt-1">No uncaught exception, N+1, repeated or slow query, slow step or failed HTTP call in this request.</p>
    </div>

    <div v-else class="space-y-3">
      <article
        v-for="(p, i) in problems"
        :key="p.id"
        class="glass relative animate-slide-up overflow-hidden rounded-xl border border-line p-4 pl-5"
        :style="{ animationDelay: `${Math.min(i, 8) * 50}ms` }"
      >
        <span class="absolute inset-y-0 left-0 w-1" :style="{ background: COLOR[severity(p.kind)] }"></span>
        <h3 class="flex flex-wrap items-center gap-2 text-[14px] font-semibold">
          <Pill :color="COLOR[severity(p.kind)]">{{ SEVERITY_WORD[severity(p.kind)] }}</Pill>
          {{ problemTitle(p.kind) }}
        </h3>
        <p class="mt-1.5 leading-relaxed text-muted">{{ p.message }}</p>
        <div class="mt-3 flex flex-wrap items-center gap-3">
          <Btn v-if="p.span_id !== null" variant="soft" size="xs" @click="emit('show', p.span_id)">
            <Crosshair class="size-3.5" />Show in the flow<template v-if="labels.get(p.span_id)">: {{ labels.get(p.span_id) }}</template>
          </Btn>
          <span v-if="p.file" class="font-mono text-[11.5px] text-faint">{{ shortFile(p.file, p.line) }}</span>
        </div>
      </article>
    </div>

    <section v-if="notes && notes.length > 0">
      <h2 class="mb-2 text-[11px] font-semibold uppercase tracking-[.08em] text-faint">Things the tracer could not do</h2>
      <ul class="divide-y divide-line overflow-hidden rounded-xl border border-line bg-raised/50">
        <li v-for="(n, i) in notes" :key="i" class="px-4 py-2.5 text-muted"><strong class="font-mono text-fg">{{ shortClass(n.target) }}</strong>: {{ n.message }}</li>
      </ul>
    </section>
  </div>
</template>
