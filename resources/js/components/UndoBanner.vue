<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import type { UndoConflict, UndoSummary } from '../api/types'
import { CircleCheck, Undo2, Zap } from 'lucide-vue-next'
import { absoluteTime, plural } from '../lib/format'
import { useStore } from '../store'
import Btn from '../ui/Btn.vue'

const props = defineProps<{ traceId: string }>()

const store = useStore()
const summary = ref<UndoSummary | null>(null)
const conflicts = ref<UndoConflict[]>([])
const busy = ref(false)
const problem = ref<string | null>(null)
const done = ref<number | null>(null)

onMounted(async () => {
  try {
    summary.value = await store.api.undoStatus(props.traceId)
  } catch {
    summary.value = null
  }
})

const changes = computed(() => Object.entries(summary.value?.tables ?? {}).map(([table, ops]) => `${table}: ${Object.entries(ops).map(([op, n]) => `${n} ${op}`).join(', ')}`))
const uncovered = computed(() => Object.entries(summary.value?.uncovered ?? {}).map(([table, n]) => `${table} (${n})`))

async function undo(force: boolean): Promise<void> {
  busy.value = true
  problem.value = null
  try {
    const result = await store.api.undo(props.traceId, force)
    if (result.error !== null) {
      problem.value = result.error
    } else if (!result.applied) {
      conflicts.value = result.conflicts
    } else {
      conflicts.value = []
      done.value = result.undone
      summary.value = await store.api.undoStatus(props.traceId)
      store.toast(`Undone: ${plural(result.undone, 'change')}.`)
    }
  } catch (e) {
    problem.value = e instanceof Error ? e.message : String(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section v-if="done !== null && summary === null" class="flex animate-pop-in items-center gap-2 rounded-xl border border-ok/40 bg-ok/10 px-3.5 py-2.5 text-ok" role="status">
    <CircleCheck class="size-4" /><strong>Undone.</strong> {{ plural(done, 'change') }} taken back.
  </section>
  <section v-else-if="summary !== null" class="animate-pop-in rounded-xl border border-warn/45 bg-warn/[.07] px-3.5 py-3" aria-label="This replay ran for real">
    <p class="flex flex-wrap items-center gap-x-2 gap-y-1">
      <Zap class="size-4 text-warn" /><strong>This replay ran for real.</strong>
      <template v-if="summary.available">
        <span>{{ plural(summary.entries, 'change') }} can be taken back</span>
        <span v-if="summary.expires_at" class="text-muted">(until {{ absoluteTime(summary.expires_at) }})</span>
      </template>
    </p>
    <ul v-if="summary.available" class="mt-2 flex flex-wrap gap-1.5">
      <li v-for="line in changes" :key="line" class="rounded-md border border-line bg-raised/70 px-2 py-0.5 font-mono text-[11.5px] text-muted">{{ line }}</li>
    </ul>
    <p v-if="uncovered.length > 0" class="mt-2 text-warn">
      Cannot be undone (query builder or raw SQL): {{ uncovered.join(', ') }}. E-mails, jobs, files and calls to other services cannot be taken back either.
    </p>

    <template v-if="conflicts.length > 0">
      <p class="mt-2 text-bad" role="alert">
        Nothing was changed: {{ plural(conflicts.length, 'row') }} {{ conflicts.length === 1 ? 'is' : 'are' }} not as the replay left {{ conflicts.length === 1 ? 'it' : 'them' }}.
      </p>
      <ul class="mt-1 space-y-0.5 font-mono text-[11.5px] text-muted">
        <li v-for="(c, i) in conflicts" :key="i">{{ c.table }} #{{ c.key }} ({{ c.operation }}): {{ c.reason }}</li>
      </ul>
    </template>
    <p v-if="problem" class="mt-2 text-bad" role="alert">{{ problem }}</p>

    <div v-if="summary.available" class="mt-2.5">
      <Btn v-if="conflicts.length === 0" variant="soft" size="sm" :disabled="busy" @click="undo(false)"><Undo2 class="size-3.5" />{{ busy ? 'Undoing…' : 'Undo these changes' }}</Btn>
      <Btn v-else variant="danger" size="sm" :disabled="busy" @click="undo(true)">{{ busy ? 'Undoing…' : 'Undo anyway' }}</Btn>
    </div>
  </section>
</template>
