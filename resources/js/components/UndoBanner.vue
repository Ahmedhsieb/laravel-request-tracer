<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import type { UndoConflict, UndoSummary } from '../api/types'
import { absoluteTime, plural } from '../lib/format'
import { useStore } from '../store'

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
  <section v-if="done !== null && summary === null" class="undo-banner" role="status">
    <strong>Undone.</strong> {{ plural(done, 'change') }} taken back.
  </section>
  <section v-else-if="summary !== null" class="undo-banner" aria-label="This replay ran for real">
    <p style="margin: 0">
      <strong>This replay ran for real.</strong>{{ ' ' }}
      <template v-if="summary.available">
        {{ plural(summary.entries, 'change') }} can be taken back
        <span v-if="summary.expires_at" class="muted">(until {{ absoluteTime(summary.expires_at) }})</span>:
      </template>
    </p>
    <ul v-if="summary.available" class="undo-list mono">
      <li v-for="line in changes" :key="line">{{ line }}</li>
    </ul>
    <p v-if="uncovered.length > 0" class="tone-warn" style="margin: 6px 0 0">
      Cannot be undone (query builder or raw SQL): {{ uncovered.join(', ') }}. E-mails, jobs, files and calls to other services cannot be taken back either.
    </p>

    <template v-if="conflicts.length > 0">
      <p class="tone-bad" role="alert" style="margin: 8px 0 4px">
        Nothing was changed: {{ plural(conflicts.length, 'row') }} {{ conflicts.length === 1 ? 'is' : 'are' }} not as the replay left {{ conflicts.length === 1 ? 'it' : 'them' }}.
      </p>
      <ul class="undo-list mono">
        <li v-for="(c, i) in conflicts" :key="i">{{ c.table }} #{{ c.key }} ({{ c.operation }}): {{ c.reason }}</li>
      </ul>
    </template>
    <p v-if="problem" class="tone-bad" role="alert" style="margin: 8px 0 0">{{ problem }}</p>

    <div v-if="summary.available" class="row" style="margin-top: 8px">
      <button v-if="conflicts.length === 0" class="btn small" type="button" :disabled="busy" @click="undo(false)">{{ busy ? 'Undoing…' : 'Undo these changes' }}</button>
      <button v-else class="btn small danger" type="button" :disabled="busy" @click="undo(true)">{{ busy ? 'Undoing…' : 'Undo anyway' }}</button>
    </div>
  </section>
</template>
