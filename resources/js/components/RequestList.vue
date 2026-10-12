<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { formatMs, plural } from '../lib/format'
import { groupTraces } from '../lib/groups'
import { useStore, type Chip } from '../store'
import RequestRow from './RequestRow.vue'

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
const groups = computed(() => (store.grouped ? groupTraces(store.traces, store.groupDepth) : []))
const allCollapsed = computed(() => groups.value.length > 0 && groups.value.every((g) => store.collapsedGroups.has(g.key)))

function pick(chip: Chip): void {
  store.chip = chip
  store.refilter()
}
</script>

<template>
  <div class="list-tools">
    <label class="sr-only" for="trace-search">Search requests</label>
    <input id="trace-search" v-model="store.search" class="search" type="search" placeholder="Search path, route or id" autocomplete="off" @input="store.refilter(250)">
    <div class="chips" role="group" aria-label="Filter requests">
      <button v-for="c in chips" :key="c.id" class="chip" type="button" :aria-pressed="store.chip === c.id" @click="pick(c.id)">{{ c.label }}</button>
    </div>
    <div class="group-tools" role="group" aria-label="Group requests by route">
      <button class="chip" type="button" :aria-pressed="store.grouped" title="Put the requests of one route prefix together, each group can be closed" @click="store.setGrouping(!store.grouped)">Group by route</button>
      <template v-if="store.grouped">
        <label class="depth">prefix of
          <select :value="store.groupDepth" aria-label="How many segments of the route make the prefix" @change="store.setGrouping(true, Number(($event.target as HTMLSelectElement).value))">
            <option v-for="n in [1, 2, 3, 4]" :key="n" :value="n">{{ plural(n, 'segment') }}</option>
          </select>
        </label>
        <button class="btn small ghost" type="button" @click="store.setAllGroups(!allCollapsed, groups.map((g) => g.key))">{{ allCollapsed ? 'Open all' : 'Close all' }}</button>
      </template>
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
    <template v-if="store.grouped">
      <template v-for="g in groups" :key="g.key">
        <li class="group-head">
          <button type="button" :aria-expanded="!store.collapsedGroups.has(g.key)" :aria-label="`${g.key}: ${plural(g.traces.length, 'request')}`" @click="store.toggleGroup(g.key)">
            <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true"><path d="M5 3l4 4-4 4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" /></svg>
            <span class="gname mono">{{ g.key }}</span>
            <span class="gcount">{{ g.traces.length }}</span>
            <span v-if="g.problems > 0" class="gnote tone-warn">{{ plural(g.problems, 'problem') }}</span>
            <span class="gdot" :class="`tone-${g.worst}`" aria-hidden="true"></span>
            <span class="gms">slowest {{ formatMs(g.slowestMs) }}</span>
          </button>
        </li>
        <template v-if="!store.collapsedGroups.has(g.key)">
          <li v-for="t in g.traces" :key="t.id" class="req-item in-group">
            <RequestRow :trace="t" :slow-ms="slowMs" :now="now" />
          </li>
        </template>
      </template>
    </template>
    <template v-else>
      <li v-for="t in store.traces" :key="t.id" class="req-item">
            <RequestRow :trace="t" :slow-ms="slowMs" :now="now" />
      </li>
    </template>
    <li v-if="store.cursor" style="padding: 10px; text-align: center">
      <button class="btn small" type="button" :disabled="store.loadingMore" @click="store.more()">{{ store.loadingMore ? 'Loading…' : 'Load more' }}</button>
    </li>
  </ul>
</template>
