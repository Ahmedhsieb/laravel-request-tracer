<script setup lang="ts">
import { computed, inject, onMounted, ref } from 'vue'
import type { SpanLines } from '../api/types'
import { formatUs, shortClass, shortFile } from '../lib/format'
import { CODE_CONTEXT } from '../lib/codeContext'
import { TYPE_LABEL } from '../lib/spans'
import type { TreeNode } from '../lib/tree'
import { useStore } from '../store'
import CodeView from './CodeView.vue'

const props = defineProps<{ node: TreeNode; name: string; autoOpen?: boolean }>()

const store = useStore()
const ctx = inject(CODE_CONTEXT)
const open = ref(false)
const data = ref<SpanLines | null>(null)
const loading = ref(false)
const problem = ref<string | null>(null)

const span = computed(() => props.node.span)

async function toggle(): Promise<void> {
  open.value = !open.value
  if (!open.value || data.value !== null || ctx === undefined) return
  loading.value = true
  problem.value = null
  try {
    data.value = await store.api.spanLines(ctx.traceId, span.value.id)
  } catch (e) {
    problem.value = e instanceof Error ? e.message : String(e)
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  if (props.autoOpen === true) void toggle()
})
</script>

<template>
  <section class="code nested child-step">
    <header>
      <button class="fold" type="button" :aria-expanded="open" :aria-label="`${open ? 'Hide' : 'Show'} the code of ${name}`" @click="toggle">
        <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true"><path d="M5 3l4 4-4 4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" /></svg>
        <span class="name" :title="name">{{ shortClass(name) }}</span>
      </button>
      <span class="muted mono">{{ shortFile(span.file, span.line) }}</span>
      <span class="tag">{{ TYPE_LABEL[span.type] }} step · {{ formatUs(span.duration_us) }}</span>
    </header>
    <div v-if="open" class="child-body">
      <p v-if="problem" class="tone-bad" role="alert">{{ problem }}</p>
      <p v-else-if="loading" class="muted" aria-busy="true">Loading…</p>
      <CodeView v-else-if="data && data.sources.length > 0" :data="data" :node="node" :nested="true" />
      <p v-else class="muted info-line">This step was not traced line by line, so there is no code to show. Select it in the flow to queue its file.</p>
    </div>
  </section>
</template>
