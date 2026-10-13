<script setup lang="ts">
import { vAutoAnimate } from '@formkit/auto-animate/vue'
import { BookOpen, FolderTree, Search, Trash2, X } from 'lucide-vue-next'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import type { JournalEntry } from '../api/types'
import { formatMs, plural, relativeTime } from '../lib/format'
import { groupJournal, journalFailed } from '../lib/groups'
import { methodTone, statusTone } from '../lib/spans'
import { useStore } from '../store'
import Btn from '../ui/Btn.vue'
import Chip from '../ui/Chip.vue'
import Pick from '../ui/Pick.vue'
import Pill from '../ui/Pill.vue'
import GroupBlock from './GroupBlock.vue'

const store = useStore()
const now = ref(new Date())
let tick: ReturnType<typeof setInterval> | null = null
let debounce: ReturnType<typeof setTimeout> | null = null

onMounted(() => {
  tick = setInterval(() => {
    now.value = new Date()
  }, 15_000)
})
onBeforeUnmount(() => {
  if (tick !== null) clearInterval(tick)
  if (debounce !== null) clearTimeout(debounce)
})

const groups = computed(() => (store.grouped ? groupJournal(store.journal, store.groupDepth) : []))
const allCollapsed = computed(() => groups.value.length > 0 && groups.value.every((g) => store.collapsedJournalGroups.has(g.key)))
const depthOptions = [1, 2, 3, 4].map((n) => ({ value: String(n), label: plural(n, 'segment') }))
const depth = computed({ get: () => String(store.groupDepth), set: (v: string) => store.setGrouping(true, Number(v)) })

function pick(status: 'all' | 'failed'): void {
  store.journalStatus = status
  void store.reloadJournal()
}

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

const METHOD_COLOR: Record<string, string> = { get: 'var(--ok)', post: 'var(--info)', put: 'var(--warn)', delete: 'var(--bad)', other: 'var(--muted)' }
const methodColor = (e: JournalEntry): string => METHOD_COLOR[methodTone(e.method)] ?? 'var(--muted)'
const statusColor = (e: JournalEntry): string => ({ ok: 'var(--ok)', info: 'var(--info)', warn: 'var(--warn)', bad: 'var(--bad)', muted: 'var(--faint)' })[statusTone(e.status)]
const note = (e: JournalEntry): string => e.failure ?? (e.user_id === null ? 'guest' : `User ${e.user_id}`)
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <div class="space-y-2.5 border-b border-line px-3 pb-2.5 pt-3">
      <div class="relative">
        <Search class="pointer-events-none absolute left-3 top-1/2 size-3.5 -translate-y-1/2 text-faint" />
        <label class="sr-only" for="journal-search">Search the journal</label>
        <input id="journal-search" v-model="store.journalSearch" class="input pl-9 pr-8" type="search" placeholder="Search URL, user or error" autocomplete="off" @input="search">
        <button v-if="store.journalSearch !== ''" class="absolute right-2 top-1/2 grid size-5 -translate-y-1/2 place-items-center rounded text-faint hover:text-fg" type="button" aria-label="Clear the search" @click="store.journalSearch = ''; store.reloadJournal()"><X class="size-3.5" /></button>
      </div>

      <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Filter the journal">
        <Chip :active="store.journalStatus === 'all'" @click="pick('all')">All</Chip>
        <Chip :active="store.journalStatus === 'failed'" color="var(--bad)" @click="pick('failed')">Failed</Chip>
        <span class="mx-0.5 h-4 w-px bg-line"></span>
        <Chip :active="store.grouped" title="Put the requests of one route prefix together; each group can be closed" @click="store.setGrouping(!store.grouped)"><FolderTree class="size-3.5" />Group by route</Chip>
      </div>

      <div v-if="store.grouped" class="flex items-center gap-2 text-[12px] text-muted">
        <span>prefix of</span>
        <Pick v-model="depth" label="How many segments of the route make the prefix" :options="depthOptions" />
        <Btn class="ml-auto" variant="ghost" size="xs" @click="store.setAllJournalGroups(!allCollapsed, groups.map((g) => g.key))">{{ allCollapsed ? 'Open all' : 'Close all' }}</Btn>
      </div>

      <p class="text-[11.5px] leading-snug text-faint">
        Every request of the last {{ store.meta?.journal.hours ?? 1 }} h (at most {{ store.meta?.journal.max_rows ?? 500 }}), traced or not. Pick one to run it again, traced.
      </p>
    </div>

    <div class="min-h-0 flex-1 overflow-auto">
      <div v-if="store.journalLoading && store.journal.length === 0" class="space-y-3 p-4" aria-busy="true">
        <div v-for="n in 5" :key="n" class="skeleton h-12" :style="{ opacity: 1 - n * 0.12 }"></div>
      </div>
      <p v-else-if="store.journalError && store.journal.length === 0" class="m-4 rounded-[10px] border border-bad/40 bg-bad/10 px-3 py-2 text-bad" role="alert">{{ store.journalError }}</p>

      <div v-else-if="store.journal.length === 0" class="grid place-items-center px-6 py-14 text-center text-muted">
        <BookOpen class="mb-3 size-9 text-faint" />
        <p class="font-medium text-fg">The journal is empty.</p>
        <p v-if="store.meta?.journal.enabled" class="mt-1">Nothing was requested in the last {{ store.meta?.journal.hours }} h, or nothing matches the filter.</p>
        <template v-else>
          <p class="mt-1">The journal is switched off. Turn it on to be able to go back to a request nobody traced:</p>
          <code class="mt-2 rounded-lg border border-line bg-surface px-3 py-1.5 text-[12px] text-accent-ink">REQUEST_TRACER_JOURNAL=true</code>
        </template>
      </div>

      <template v-else>
        <template v-if="store.grouped">
          <GroupBlock
            v-for="g in groups"
            :key="g.key"
            :name="g.key"
            :count="g.entries.length"
            :open="!store.collapsedJournalGroups.has(g.key)"
            :worst="g.worst"
            :slowest="formatMs(g.slowestMs)"
            :note="g.failures > 0 ? `${g.failures} failed` : undefined"
            note-tone="bad"
            @toggle="store.toggleJournalGroup(g.key)"
          >
            <div v-for="e in g.entries" :key="e.id" class="group/row relative flex items-stretch border-b border-line/70 hover:bg-hover">
              <button class="block min-w-0 flex-1 px-3 py-2 pl-6 text-left outline-none focus-visible:bg-hover" type="button" :title="e.failure ?? 'Run this request again, traced'" @click="store.replayJournalEntry(e)">
                <div class="flex items-center gap-2">
                  <Pill :color="methodColor(e)">{{ e.method }}</Pill>
                  <span class="min-w-0 flex-1 truncate font-mono text-[12.5px]" :title="e.url">{{ e.url }}</span>
                  <span class="shrink-0 font-mono text-[12px] font-semibold tabular-nums" :style="{ color: statusColor(e) }">{{ e.status ?? '–' }}</span>
                </div>
                <div class="mt-1 flex items-center gap-2 text-[11.5px]">
                  <span class="min-w-0 flex-1 truncate" :class="journalFailed(e) ? 'text-bad' : 'text-faint'">{{ note(e) }}</span>
                  <Pill v-if="e.masked" color="var(--t-event)">masked</Pill>
                  <Pill v-if="e.body_cut" tone="warn">no body</Pill>
                  <span class="shrink-0 tabular-nums text-muted"><span class="font-mono text-fg/90">{{ formatMs(e.duration_ms) }}</span> · {{ relativeTime(e.created_at, now) }}</span>
                </div>
              </button>
              <Btn v-if="e.trace_id" class="m-2 self-center" size="xs" variant="soft" title="This request was traced as well" @click="store.open(e.trace_id)">Trace</Btn>
            </div>
          </GroupBlock>
        </template>
        <div v-else v-auto-animate="{ duration: 220 }">
          <div v-for="e in store.journal" :key="e.id" class="group/row relative flex items-stretch border-b border-line/70 hover:bg-hover">
            <button class="block min-w-0 flex-1 px-3 py-2 text-left outline-none focus-visible:bg-hover" type="button" :title="e.failure ?? 'Run this request again, traced'" @click="store.replayJournalEntry(e)">
              <div class="flex items-center gap-2">
                <Pill :color="methodColor(e)">{{ e.method }}</Pill>
                <span class="min-w-0 flex-1 truncate font-mono text-[12.5px]" :title="e.url">{{ e.url }}</span>
                <span class="shrink-0 font-mono text-[12px] font-semibold tabular-nums" :style="{ color: statusColor(e) }">{{ e.status ?? '–' }}</span>
              </div>
              <div class="mt-1 flex items-center gap-2 text-[11.5px]">
                <span class="min-w-0 flex-1 truncate" :class="journalFailed(e) ? 'text-bad' : 'text-faint'">{{ note(e) }}</span>
                <Pill v-if="e.masked" color="var(--t-event)">masked</Pill>
                <Pill v-if="e.body_cut" tone="warn">no body</Pill>
                <span class="shrink-0 tabular-nums text-muted"><span class="font-mono text-fg/90">{{ formatMs(e.duration_ms) }}</span> · {{ relativeTime(e.created_at, now) }}</span>
              </div>
            </button>
            <Btn v-if="e.trace_id" class="m-2 self-center" size="xs" variant="soft" title="This request was traced as well" @click="store.open(e.trace_id)">Trace</Btn>
          </div>
        </div>

        <div v-if="store.journalCursor" class="p-3 text-center"><Btn :disabled="store.journalLoadingMore" @click="store.moreJournal()">{{ store.journalLoadingMore ? 'Loading…' : 'Load more' }}</Btn></div>
        <div class="p-3 text-center"><Btn variant="ghost" size="xs" @click="clear"><Trash2 class="size-3.5" />Clear the journal</Btn></div>
      </template>
    </div>
  </div>
</template>
