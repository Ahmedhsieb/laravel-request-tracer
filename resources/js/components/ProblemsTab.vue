<script setup lang="ts">
import { computed } from 'vue'
import type { Note, ProblemKind, ProblemRow, SpanRow } from '../api/types'
import { shortClass, shortFile } from '../lib/format'
import { problemTitle } from '../lib/spans'

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

const SEVERITY_WORD = { bad: 'Error', warn: 'Warning', info: 'Notice' } as const
</script>

<template>
  <div>
    <div v-if="problems.length === 0" class="empty">
      <p class="tone-ok"><strong>No problems found.</strong></p>
      <p>No uncaught exception, N+1, repeated or slow query, slow step or failed HTTP call in this request.</p>
    </div>

    <div v-else class="cards">
      <article v-for="p in problems" :key="p.id" class="problem" :class="severity(p.kind)">
        <h3>
          <span class="badge" :class="`tone-${severity(p.kind)}`" :style="{ '--badge': `var(--${severity(p.kind) === 'bad' ? 'red' : severity(p.kind) === 'warn' ? 'orange' : 'yellow'})` }">{{ SEVERITY_WORD[severity(p.kind)] }}</span>
          {{ problemTitle(p.kind) }}
        </h3>
        <p>{{ p.message }}</p>
        <div class="links">
          <button v-if="p.span_id !== null" type="button" @click="emit('show', p.span_id)">
            Show in the flow<template v-if="labels.get(p.span_id)">: {{ labels.get(p.span_id) }}</template>
          </button>
          <span v-if="p.file" class="src">{{ shortFile(p.file, p.line) }}</span>
        </div>
      </article>
    </div>

    <template v-if="notes && notes.length > 0">
      <h2 class="section-title">Things the tracer could not do</h2>
      <ul class="note-list" style="border: 1px solid var(--border); border-radius: var(--radius); background: var(--panel); list-style: none; margin: 0">
        <li v-for="(n, i) in notes" :key="i"><strong class="mono">{{ shortClass(n.target) }}</strong>: {{ n.message }}</li>
      </ul>
    </template>
  </div>
</template>
