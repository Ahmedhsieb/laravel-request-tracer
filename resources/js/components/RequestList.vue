<script setup lang="ts">
import { vAutoAnimate } from '@formkit/auto-animate/vue'
import { FolderTree, Inbox, Search, X } from 'lucide-vue-next'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { formatMs, plural } from '../lib/format'
import { groupTraces } from '../lib/groups'
import { useStore, type Chip as ChipId } from '../store'
import Btn from '../ui/Btn.vue'
import Chip from '../ui/Chip.vue'
import Pick from '../ui/Pick.vue'
import GroupBlock from './GroupBlock.vue'
import PulseStrip from './PulseStrip.vue'
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

const chips: Array<{ id: ChipId; label: string }> = [
  { id: 'all', label: 'All' },
  { id: 'problems', label: 'With problems' },
  { id: 'slow', label: 'Slow' },
]

const slowMs = computed(() => store.meta?.slow_ms ?? 500)
const max = computed(() => Math.max(1, ...store.traces.map((t) => t.duration_ms)))
const groups = computed(() => (store.grouped ? groupTraces(store.traces, store.groupDepth) : []))
const allCollapsed = computed(() => groups.value.length > 0 && groups.value.every((g) => store.collapsedGroups.has(g.key)))
const depthOptions = [1, 2, 3, 4].map((n) => ({ value: String(n), label: plural(n, 'segment') }))
const depth = computed({ get: () => String(store.groupDepth), set: (v: string) => store.setGrouping(true, Number(v)) })

function pick(chip: ChipId): void {
  store.chip = chip
  store.refilter()
}
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <div class="space-y-2.5 border-b border-line px-3 pb-2.5 pt-3">
      <PulseStrip :traces="store.traces" :slow-ms="slowMs" />

      <div class="relative">
        <Search class="pointer-events-none absolute left-3 top-1/2 size-3.5 -translate-y-1/2 text-faint" />
        <label class="sr-only" for="trace-search">Search requests</label>
        <input id="trace-search" v-model="store.search" class="input pl-9 pr-8" type="search" placeholder="Search path, route or id" autocomplete="off" @input="store.refilter(250)">
        <button v-if="store.search !== ''" class="absolute right-2 top-1/2 grid size-5 -translate-y-1/2 place-items-center rounded text-faint hover:text-fg" type="button" aria-label="Clear the search" @click="store.search = ''; store.refilter()"><X class="size-3.5" /></button>
      </div>

      <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Filter requests">
        <Chip v-for="c in chips" :key="c.id" :active="store.chip === c.id" @click="pick(c.id)">{{ c.label }}</Chip>
        <span class="mx-0.5 h-4 w-px bg-line"></span>
        <Chip :active="store.grouped" title="Put the requests of one route prefix together; each group can be closed" @click="store.setGrouping(!store.grouped)"><FolderTree class="size-3.5" />Group by route</Chip>
      </div>

      <div v-if="store.grouped" class="flex items-center gap-2 text-[12px] text-muted">
        <span>prefix of</span>
        <Pick v-model="depth" label="How many segments of the route make the prefix" :options="depthOptions" />
        <Btn class="ml-auto" variant="ghost" size="xs" @click="store.setAllGroups(!allCollapsed, groups.map((g) => g.key))">{{ allCollapsed ? 'Open all' : 'Close all' }}</Btn>
      </div>
    </div>

    <p v-if="store.error && store.traces.length > 0" class="m-3 flex items-center gap-2 rounded-[10px] border border-bad/40 bg-bad/10 px-3 py-2 text-[12.5px] text-bad" role="alert">
      <span class="flex-1">{{ store.error }}</span><Btn size="xs" variant="soft" @click="store.reload()">Try again</Btn>
    </p>

    <div class="min-h-0 flex-1 overflow-auto" :class="store.loading && store.traces.length > 0 ? 'opacity-60 transition-opacity' : ''">
      <div v-if="store.loading && store.traces.length === 0" class="space-y-3 p-4" aria-busy="true">
        <div v-for="n in 6" :key="n" class="skeleton h-12" :style="{ opacity: 1 - n * 0.1 }"></div>
      </div>

      <div v-else-if="store.error && store.traces.length === 0" class="p-6 text-center" role="alert">
        <p class="text-bad">{{ store.error }}</p>
        <Btn class="mt-3" @click="store.reload()">Try again</Btn>
      </div>

      <div v-else-if="store.traces.length === 0" class="grid place-items-center px-6 py-14 text-center text-muted">
        <Inbox class="mb-3 size-9 text-faint" />
        <template v-if="store.chip !== 'all' || store.search !== ''">
          <p class="font-medium text-fg">No request matches the filter.</p>
        </template>
        <template v-else>
          <p class="font-medium text-fg">Nothing recorded yet.</p>
          <p class="mt-1">Nothing is traced until you ask. In your project, run:</p>
          <code class="mt-2 rounded-lg border border-line bg-surface px-3 py-1.5 text-[12px] text-accent-ink">{{ store.meta?.listen_command ?? 'php artisan request-tracer:listen' }}</code>
          <p class="mt-2">then use your app: every request, job and command shows up here while it runs.</p>
          <Btn v-if="store.meta?.replay.enabled" class="mt-4" variant="soft" @click="store.openReplay()">Or replay a request that already happened</Btn>
        </template>
      </div>

      <template v-else-if="store.grouped">
        <GroupBlock
          v-for="g in groups"
          :key="g.key"
          :name="g.key"
          :count="g.traces.length"
          :open="!store.collapsedGroups.has(g.key)"
          :worst="g.worst"
          :slowest="formatMs(g.slowestMs)"
          :note="g.problems > 0 ? plural(g.problems, 'problem') : undefined"
          @toggle="store.toggleGroup(g.key)"
        >
          <RequestRow v-for="t in g.traces" :key="t.id" :trace="t" :slow-ms="slowMs" :now="now" :max="max" indent />
        </GroupBlock>
        <div v-if="store.cursor" class="p-3 text-center"><Btn :disabled="store.loadingMore" @click="store.more()">{{ store.loadingMore ? 'Loading…' : 'Load more' }}</Btn></div>
      </template>

      <template v-else>
        <div v-auto-animate="{ duration: 220 }">
          <RequestRow v-for="t in store.traces" :key="t.id" :trace="t" :slow-ms="slowMs" :now="now" :max="max" />
        </div>
        <div v-if="store.cursor" class="p-3 text-center"><Btn :disabled="store.loadingMore" @click="store.more()">{{ store.loadingMore ? 'Loading…' : 'Load more' }}</Btn></div>
      </template>
    </div>
  </div>
</template>
