<script setup lang="ts">
import { computed } from 'vue'
import { useStore } from '../store'
import Seg from '../ui/Seg.vue'
import JournalList from './JournalList.vue'
import RequestList from './RequestList.vue'

/** The left panel: the requests that were traced, or the journal of every request that was made. */
const store = useStore()

const view = computed({
  get: () => store.view,
  set: (next: 'traces' | 'journal') => store.showView(next),
})
const options = computed(() => [
  { value: 'traces' as const, label: 'Traces', count: store.traces.length },
  { value: 'journal' as const, label: 'Journal' },
])
</script>

<template>
  <aside class="glass flex h-full min-h-0 flex-col" aria-label="Requests">
    <div class="px-3 pt-3">
      <Seg v-model="view" :options="options" label="What the list shows" />
    </div>
    <JournalList v-if="store.view === 'journal'" />
    <RequestList v-else />
  </aside>
</template>
