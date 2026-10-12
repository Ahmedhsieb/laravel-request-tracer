<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import type { JournalEntry } from '../api/types'
import { formatMs, plural, relativeTime } from '../lib/format'
import { groupJournal } from '../lib/groups'
import { methodTone, statusTone } from '../lib/spans'
import { useStore } from '../store'

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

const groups = computed(() => (store.grouped ? groupJournal(store.journal, store.groupDepth) : []))
const allCollapsed = computed(() => groups.value.length > 0 && groups.value.every((g) => store.collapsedJournalGroups.has(g.key)))

function pick(status: 'all' | 'failed'): void {
  store.journalStatus = status
  void store.reloadJournal()
}

let debounce: ReturnType<typeof setTimeout> | null = null
function search(): void {
  if (debounce !== null) clearTimeout(debounce)
  debounce = setTimeout(() => void store.reloadJournal(), 250)
}

async function clear(): Promise<void> {
  if (!window.confirm('Delete every entry of the request journal? Traces are not affected.')) return
  try {
    await store.api.clearJournal()
    await store.reloadJournal()
  } catch (e) {
    store.toast(e instanceof Error ? e.message : String(e))
  }
}

function note(entry: JournalEntry): string {
  if (entry.failure !== null) return entry.failure
  return entry.user_id === null ? 'guest' : `User ${entry.user_id}`
}
</script>

<template>
  <div class="list-tools">
    <label class="sr-only" for="journal-search">Search the journal</label>
    <input id="journal-search" v-model="store.journalSearch" class="search" type="search" placeholder="Search URL, user or error" autocomplete="off" @input="search">
    <div class="chips" role="group" aria-label="Filter the journal">
      <button class="chip" type="button" :aria-pressed="store.journalStatus === 'all'" @click="pick('all')">All</button>
      <button class="chip" type="button" :aria-pressed="store.journalStatus === 'failed'" @click="pick('failed')">Failed</button>
    </div>
    <div class="group-tools" role="group" aria-label="Group the journal by route">
      <button class="chip" type="button" :aria-pressed="store.grouped" title="Put the requests of one route prefix together, each group can be closed" @click="store.setGrouping(!store.grouped)">Group by route</button>
      <template v-if="store.grouped">
        <label class="depth">prefix of
          <select :value="store.groupDepth" aria-label="How many segments of the route make the prefix" @change="store.setGrouping(true, Number(($event.target as HTMLSelectElement).value))">
            <option v-for="n in [1, 2, 3, 4]" :key="n" :value="n">{{ plural(n, 'segment') }}</option>
          </select>
        </label>
        <button class="btn small ghost" type="button" @click="store.setAllJournalGroups(!allCollapsed, groups.map((g) => g.key))">{{ allCollapsed ? 'Open all' : 'Close all' }}</button>
      </template>
    </div>
    <p class="hint" style="margin: 0">
      Every request of the last {{ store.meta?.journal.hours ?? 1 }} h (at most {{ store.meta?.journal.max_rows ?? 500 }}), traced or not.
      Pick one to run it again, traced.
    </p>
  </div>

  <div v-if="store.journalLoading && store.journal.length === 0" class="loading" aria-busy="true">
    <div class="skeleton" style="width: 80%; margin: 0 auto 12px"></div>
    <div class="skeleton" style="width: 60%; margin: 0 auto"></div>
  </div>
  <p v-else-if="store.journalError && store.journal.length === 0" class="error-box" role="alert">{{ store.journalError }}</p>
  <div v-else-if="store.journal.length === 0" class="empty">
    <p><strong>The journal is empty.</strong></p>
    <p v-if="store.meta?.journal.enabled">Nothing was requested in the last {{ store.meta?.journal.hours }} h, or nothing matches the filter.</p>
    <template v-else>
      <p>The journal is switched off. Turn it on to be able to go back to a request nobody traced:</p>
      <p><code>REQUEST_TRACER_JOURNAL=true</code></p>
    </template>
  </div>

  <ul v-else class="requests">
    <template v-if="store.grouped">
      <template v-for="g in groups" :key="g.key">
        <li class="group-head">
          <button type="button" :aria-expanded="!store.collapsedJournalGroups.has(g.key)" :aria-label="`${g.key}: ${plural(g.entries.length, 'request')}`" @click="store.toggleJournalGroup(g.key)">
            <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true"><path d="M5 3l4 4-4 4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" /></svg>
            <span class="gname mono">{{ g.key }}</span>
            <span class="gcount">{{ g.entries.length }}</span>
            <span v-if="g.failures > 0" class="gnote tone-bad">{{ g.failures }} failed</span>
            <span class="gdot" :class="`tone-${g.worst}`" aria-hidden="true"></span>
            <span class="gms">slowest {{ formatMs(g.slowestMs) }}</span>
          </button>
        </li>
        <template v-if="!store.collapsedJournalGroups.has(g.key)">
          <li v-for="e in g.entries" :key="e.id" class="journal-row in-group">
      <button class="req" type="button" :title="e.failure ?? 'Run this request again, traced'" @click="store.replayJournalEntry(e)">
        <span class="badge method" :class="methodTone(e.method)">{{ e.method }}</span>
        <span class="path" :title="e.url">{{ e.url }}</span>
        <span class="status" :class="`tone-${statusTone(e.status)}`">{{ e.status ?? '–' }}</span>
        <span class="note" :class="{ 'tone-bad': e.failure !== null }">
          {{ note(e) }}
          <span v-if="e.masked" class="badge" style="--badge: var(--purple); margin-left: 6px" title="Secrets were masked when this request was noted: a replay sends the mask, not the secret">masked</span>
          <span v-if="e.body_cut" class="badge" style="--badge: var(--yellow); margin-left: 6px" title="The body was too large to keep: a replay sends none">no body</span>
        </span>
        <span class="time"><span class="dur">{{ formatMs(e.duration_ms) }}</span> · {{ relativeTime(e.created_at, now) }}</span>
      </button>
      <button v-if="e.trace_id" class="btn small ghost" type="button" title="This request was traced as well" @click="store.open(e.trace_id)">Trace</button>
          </li>
        </template>
      </template>
    </template>
    <template v-else>
      <li v-for="e in store.journal" :key="e.id" class="journal-row">
      <button class="req" type="button" :title="e.failure ?? 'Run this request again, traced'" @click="store.replayJournalEntry(e)">
        <span class="badge method" :class="methodTone(e.method)">{{ e.method }}</span>
        <span class="path" :title="e.url">{{ e.url }}</span>
        <span class="status" :class="`tone-${statusTone(e.status)}`">{{ e.status ?? '–' }}</span>
        <span class="note" :class="{ 'tone-bad': e.failure !== null }">
          {{ note(e) }}
          <span v-if="e.masked" class="badge" style="--badge: var(--purple); margin-left: 6px" title="Secrets were masked when this request was noted: a replay sends the mask, not the secret">masked</span>
          <span v-if="e.body_cut" class="badge" style="--badge: var(--yellow); margin-left: 6px" title="The body was too large to keep: a replay sends none">no body</span>
        </span>
        <span class="time"><span class="dur">{{ formatMs(e.duration_ms) }}</span> · {{ relativeTime(e.created_at, now) }}</span>
      </button>
      <button v-if="e.trace_id" class="btn small ghost" type="button" title="This request was traced as well" @click="store.open(e.trace_id)">Trace</button>
      </li>
    </template>
    <li v-if="store.journalCursor" style="padding: 10px; text-align: center">
      <button class="btn small" type="button" @click="store.moreJournal()">Load more</button>
    </li>
    <li style="padding: 10px; text-align: center">
      <button class="btn small ghost" type="button" @click="clear">Clear the journal</button>
    </li>
  </ul>
</template>
