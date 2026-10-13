<script setup lang="ts">
import { computed } from 'vue'
import type { SpanRow, TraceSummary, WriteOperation, WriteRow } from '../api/types'
import { CircleCheck, CircleDot, Database, Undo2 } from 'lucide-vue-next'
import { plural, shortClass } from '../lib/format'
import Pill from '../ui/Pill.vue'

const props = defineProps<{ writes: WriteRow[]; spans: SpanRow[]; trace: TraceSummary; mask: string }>()
const emit = defineEmits<{ show: [id: number] }>()

interface Group {
  key: string
  table: string
  connection: string | null
  operation: WriteOperation
  rows: WriteRow[]
  columns: string[]
  writers: Array<{ id: number; label: string }>
  transaction: string
}

const byId = computed(() => new Map(props.spans.map((s) => [s.id, s])))

/** The step that caused a write: the nearest ancestor that is not itself a query or model event. */
function writerOf(id: number | null): { id: number; label: string } | null {
  let span = id === null ? undefined : byId.value.get(id)
  while (span !== undefined && (span.type === 'query' || span.type === 'model')) {
    span = span.parent_id === null ? undefined : byId.value.get(span.parent_id)
  }

  return span === undefined ? null : { id: span.id, label: shortClass(span.label) }
}

const groups = computed<Group[]>(() => {
  const map = new Map<string, Group>()
  for (const w of props.writes) {
    const key = `${w.connection ?? ''}|${w.table}|${w.operation}`
    let g = map.get(key)
    if (g === undefined) {
      g = { key, table: w.table, connection: w.connection, operation: w.operation, rows: [], columns: [], writers: [], transaction: 'none' }
      map.set(key, g)
    }
    g.rows.push(w)
    const writer = writerOf(w.span_id)
    if (writer !== null && !g.writers.some((x) => x.id === writer.id)) g.writers.push(writer)
    for (const column of Object.keys(w.after ?? w.before ?? {})) {
      if (!g.columns.includes(column)) g.columns.push(column)
    }
    if (w.transaction === 'rolled_back') g.transaction = 'rolled_back'
    else if (g.transaction !== 'rolled_back' && w.transaction !== null) g.transaction = w.transaction
  }

  return [...map.values()]
})

const reads = computed(() => Object.entries(props.trace.tables_read ?? {}).sort((a, b) => b[1].queries - a[1].queries))

const OP_COLOR: Record<WriteOperation, string> = { insert: 'var(--ok)', update: 'var(--warn)', delete: 'var(--bad)' }

const TRANSACTION_SHORT: Record<string, string> = { none: 'no transaction', open: 'transaction left open', committed: 'committed', rolled_back: 'rolled back' }

const TRANSACTION_TEXT: Record<string, string> = {
  none: 'Not inside a transaction: each statement was saved on its own.',
  open: 'Inside a transaction that had not finished when the request ended.',
  committed: 'Saved in a transaction that was committed.',
  rolled_back: 'The transaction was rolled back, so these changes were not kept.',
}

function show(value: unknown): string {
  if (value === null || value === undefined) return 'null'
  if (typeof value === 'object') return JSON.stringify(value)

  return String(value)
}

function isMasked(value: unknown): boolean {
  return value === props.mask || value === '••••••••'
}

function changed(row: WriteRow, column: string): boolean {
  return row.operation === 'update' && show(row.before?.[column]) !== show(row.after?.[column])
}
</script>

<template>
  <div class="space-y-4 pb-6">
    <div v-if="groups.length === 0" class="grid place-items-center rounded-xl border border-dashed border-line-strong px-6 py-14 text-center text-muted">
      <Database class="mb-3 size-9 text-faint" />
      <p class="font-medium text-fg">No table was written.</p>
      <p class="mt-1">This request only read data.</p>
    </div>

    <div v-else class="space-y-4">
      <article v-for="(g, i) in groups" :key="g.key" class="glass animate-slide-up overflow-hidden rounded-xl border border-line" :style="{ animationDelay: `${Math.min(i, 8) * 45}ms` }">
        <header class="flex flex-wrap items-center gap-x-3 gap-y-1.5 border-b border-line bg-raised/50 px-4 py-2.5">
          <Pill :color="OP_COLOR[g.operation]" solid>{{ g.operation }}</Pill>
          <h3 class="font-mono text-[14px] font-semibold">{{ g.table }}</h3>
          <span class="text-muted">{{ plural(g.rows.length, 'row') }}</span>
          <span v-if="g.connection" class="font-mono text-[11px] text-faint">{{ g.connection }}</span>
          <span class="flex-1"></span>
          <span class="flex items-center gap-1.5 text-[11.5px]" :class="g.transaction === 'rolled_back' ? 'text-warn' : 'text-muted'">
            <component :is="g.transaction === 'rolled_back' ? Undo2 : g.transaction === 'committed' ? CircleCheck : CircleDot" class="size-3.5" />{{ TRANSACTION_SHORT[g.transaction] ?? g.transaction }}
          </span>
        </header>

        <div v-if="g.writers.length > 0" class="flex flex-wrap items-center gap-1.5 border-b border-line px-4 py-2 text-[12px] text-muted">
          <span>Written by</span>
          <button v-for="w in g.writers" :key="w.id" type="button" class="rounded-md border border-line bg-raised px-2 py-0.5 font-mono text-[11.5px] text-fg transition-colors hover:border-accent hover:text-accent-ink" title="Show this step in the flow" @click="emit('show', w.id)">{{ w.label }}</button>
        </div>

        <div class="max-h-[420px] overflow-auto">
          <table class="w-full border-collapse text-[12px]">
            <caption class="sr-only">{{ g.operation }} on {{ g.table }}</caption>
            <thead class="sticky top-0 z-[1] bg-surface">
              <tr>
                <th v-if="g.rows.some((r) => r.row_key !== null)" scope="col" class="border-b border-line px-3 py-1.5 text-left text-[11px] font-semibold uppercase tracking-wide text-faint">key</th>
                <th v-for="c in g.columns" :key="c" scope="col" class="border-b border-line px-3 py-1.5 text-left font-mono text-[11px] font-semibold text-muted">{{ c }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in g.rows" :key="r.id" class="border-b border-line/50 last:border-0 hover:bg-hover/60">
                <td v-if="g.rows.some((x) => x.row_key !== null)" class="px-3 py-1.5 font-mono text-faint">{{ r.row_key ?? '' }}</td>
                <template v-if="g.operation === 'update'">
                  <td v-for="c in g.columns" :key="c" class="px-3 py-1.5 font-mono" :class="[changed(r, c) ? 'bg-warn/[.09]' : '', isMasked(r.after?.[c]) ? 'italic text-[var(--t-event)]' : '']">
                    <template v-if="changed(r, c)">
                      <span class="text-faint line-through" :class="isMasked(r.before?.[c]) ? 'italic' : ''">{{ show(r.before?.[c]) }}</span>
                      <span class="px-1 text-warn" aria-label="changed to">→</span><strong class="text-fg">{{ show(r.after?.[c]) }}</strong>
                    </template>
                    <template v-else>{{ show(r.after?.[c]) }}</template>
                  </td>
                </template>
                <template v-else>
                  <td v-for="c in g.columns" :key="c" class="px-3 py-1.5 font-mono" :class="[isMasked((r.after ?? r.before)?.[c]) ? 'italic text-[var(--t-event)]' : '', (r.after ?? r.before)?.[c] === null ? 'text-faint' : '']">{{ show((r.after ?? r.before)?.[c]) }}</td>
                </template>
              </tr>
            </tbody>
          </table>
        </div>

        <p class="border-t border-line bg-raised/40 px-4 py-2 text-[11.5px] text-muted"><b class="text-fg">Transaction:</b> {{ TRANSACTION_TEXT[g.transaction] ?? g.transaction }}</p>
      </article>
    </div>

    <section v-if="reads.length > 0">
      <h2 class="mb-2 text-[11px] font-semibold uppercase tracking-[.08em] text-faint">Tables read (read only)</h2>
      <ul class="flex flex-wrap gap-2">
        <li v-for="[table, stat] in reads" :key="table" class="rounded-lg border border-line bg-raised/60 px-2.5 py-1 font-mono text-[11.5px] text-muted"><span class="text-fg">{{ table }}</span> · {{ plural(stat.queries, 'query', 'queries') }}<template v-if="stat.rows > 0"> · {{ plural(stat.rows, 'row') }}</template></li>
      </ul>
    </section>
  </div>
</template>
