<script setup lang="ts">
import { computed } from 'vue'
import type { SpanRow, TraceSummary, WriteOperation, WriteRow } from '../api/types'
import { plural, shortClass } from '../lib/format'

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
  <div>
    <div v-if="groups.length === 0" class="empty">
      <p><strong>No table was written.</strong></p>
      <p>This request only read data.</p>
    </div>

    <div v-else class="cards">
      <article v-for="g in groups" :key="g.key" class="card">
        <header>
          <span class="op" :class="g.operation">{{ g.operation.toUpperCase() }}</span>
          <h3>{{ g.table }}</h3>
          <span class="muted">{{ plural(g.rows.length, 'row') }}</span>
          <span v-if="g.connection" class="faint mono">{{ g.connection }}</span>
        </header>

        <div v-if="g.writers.length > 0" class="by">
          <span>Written by</span>
          <button v-for="w in g.writers" :key="w.id" type="button" :title="'Show this step in the flow'" @click="emit('show', w.id)">{{ w.label }}</button>
        </div>

        <div class="grid-scroll">
          <table class="grid">
            <caption class="sr-only">{{ g.operation }} on {{ g.table }}</caption>
            <thead>
              <tr>
                <th v-if="g.rows.some((r) => r.row_key !== null)" scope="col">key</th>
                <th v-for="c in g.columns" :key="c" scope="col">{{ c }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in g.rows" :key="r.id">
                <td v-if="g.rows.some((x) => x.row_key !== null)" class="faint">{{ r.row_key ?? '' }}</td>
                <template v-if="g.operation === 'update'">
                  <td v-for="c in g.columns" :key="c" :class="{ changed: changed(r, c), masked: isMasked(r.after?.[c]) }">
                    <template v-if="changed(r, c)">
                      <span class="before" :class="{ masked: isMasked(r.before?.[c]) }" style="text-decoration: line-through; opacity: .75">{{ show(r.before?.[c]) }}</span>
                      <span aria-label="changed to"> → </span><strong>{{ show(r.after?.[c]) }}</strong>
                    </template>
                    <template v-else>{{ show(r.after?.[c]) }}</template>
                  </td>
                </template>
                <template v-else>
                  <td v-for="c in g.columns" :key="c" :class="{ masked: isMasked((r.after ?? r.before)?.[c]), null: (r.after ?? r.before)?.[c] === null }">{{ show((r.after ?? r.before)?.[c]) }}</td>
                </template>
              </tr>
            </tbody>
          </table>
        </div>

        <p class="tx"><b>Transaction:</b> {{ TRANSACTION_TEXT[g.transaction] ?? g.transaction }}</p>
      </article>
    </div>

    <template v-if="reads.length > 0">
      <h2 class="section-title">Tables read (read only)</h2>
      <ul class="read-list">
        <li v-for="[table, stat] in reads" :key="table">{{ table }} · {{ plural(stat.queries, 'query', 'queries') }}<template v-if="stat.rows > 0"> · {{ plural(stat.rows, 'row') }}</template></li>
      </ul>
    </template>
  </div>
</template>
