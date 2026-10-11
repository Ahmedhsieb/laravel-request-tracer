<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import type { TraceSummary } from '../api/types'
import { formatMs, relativeTime } from '../lib/format'
import { methodTone, problemNote, statusTone } from '../lib/spans'
import { useStore, type Chip } from '../store'

const store = useStore()
const now = ref(new Date())
let tick: ReturnType<typeof setInterval> | null = null

onMounted(() => {
  tick = setInterval(() => {
    now.value = new Date()
  }, 15_000)
})
onBeforeUnmount(() => {
  if (tick !== null) clearInterval(tick)
})

const chips: Array<{ id: Chip; label: string }> = [
  { id: 'all', label: 'All' },
  { id: 'problems', label: 'With problems' },
  { id: 'slow', label: 'Slow' },
]

const slowMs = computed(() => store.meta?.slow_ms ?? 500)

function pick(chip: Chip): void {
  store.chip = chip
  store.refilter()
}

function title(t: TraceSummary): string {
  if (t.kind === 'request') return `${t.method ?? ''} ${t.path ?? ''}`.trim()

  return `${t.kind} ${t.path ?? ''}`.trim()
}

function status(t: TraceSummary): string {
  return t.status === null ? (t.kind === 'request' ? '–' : '') : String(t.status)
}
</script>

<template>
  <div class="list-tools">
    <label class="sr-only" for="trace-search">Search requests</label>
    <input id="trace-search" v-model="store.search" class="search" type="search" placeholder="Search path, route or id" autocomplete="off" @input="store.refilter(250)">
    <div class="chips" role="group" aria-label="Filter requests">
      <button v-for="c in chips" :key="c.id" class="chip" type="button" :aria-pressed="store.chip === c.id" @click="pick(c.id)">{{ c.label }}</button>
    </div>
  </div>

  <p v-if="store.error && store.traces.length > 0" class="error-box slim" role="alert">
    {{ store.error }}
    <button class="btn small" type="button" style="margin-left: 8px" @click="store.reload()">Try again</button>
  </p>
  <p v-if="store.loading && store.traces.length > 0" class="list-busy" role="status">Loading…</p>

  <div v-if="store.loading && store.traces.length === 0" class="loading" aria-busy="true">
    <div class="skeleton" style="width: 80%; margin: 0 auto 12px"></div>
    <div class="skeleton" style="width: 60%; margin: 0 auto 12px"></div>
    <div class="skeleton" style="width: 70%; margin: 0 auto"></div>
  </div>
  <p v-else-if="store.error && store.traces.length === 0" class="error-box" role="alert">
    {{ store.error }}
    <br><button class="btn small" type="button" style="margin-top: 10px" @click="store.reload()">Try again</button>
  </p>
  <div v-else-if="store.traces.length === 0" class="empty">
    <p><strong>Nothing recorded yet.</strong></p>
    <p v-if="store.chip !== 'all' || store.search !== ''">No request matches the filter.</p>
    <template v-else>
      <p>Nothing is traced until you ask. In your project, run:</p>
      <p><code>{{ store.meta?.listen_command ?? 'php artisan request-tracer:listen' }}</code></p>
      <p>then use your app: every request, job and command shows up here while it runs.</p>
      <p v-if="store.meta?.replay.enabled"><button class="btn small" type="button" @click="store.openReplay()">Or replay a request that already happened</button></p>
    </template>
  </div>

  <ul v-else class="requests" :class="{ busy: store.loading }">
    <li v-for="t in store.traces" :key="t.id" class="req-item">
      <button class="req" type="button" :class="{ fresh: store.fresh.has(t.id) }" :aria-current="store.selected === t.id" @click="store.open(t.id)">
        <span class="badge method" :class="methodTone(t.method)">{{ t.method ?? t.kind }}</span>
        <span class="path" :title="title(t)">{{ t.kind === 'request' ? t.path : (t.path ?? t.kind) }}</span>
        <span class="status" :class="`tone-${statusTone(t.status)}`">{{ status(t) }}</span>
        <span class="note">
          <span v-if="t.mode === 'replay'" class="badge" style="--badge: var(--purple); margin-right: 6px">replay</span>
          <template v-if="problemNote(t, slowMs)">
            <span :class="`tone-${problemNote(t, slowMs)?.tone}`">{{ problemNote(t, slowMs)?.text }}</span>
          </template>
          <span v-else class="faint">{{ t.module ?? t.route_name ?? '' }}</span>
        </span>
        <span class="time"><span class="dur">{{ formatMs(t.duration_ms) }}</span> · {{ relativeTime(t.created_at, now) }}</span>
      </button>
    </li>
    <li v-if="store.cursor" style="padding: 10px; text-align: center">
      <button class="btn small" type="button" :disabled="store.loadingMore" @click="store.more()">{{ store.loadingMore ? 'Loading…' : 'Load more' }}</button>
    </li>
  </ul>
</template>
