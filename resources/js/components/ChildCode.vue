<script setup lang="ts">
import { ChevronRight } from 'lucide-vue-next'
import { computed, inject, onMounted, ref } from 'vue'
import type { SpanLines } from '../api/types'
import { CODE_CONTEXT } from '../lib/codeContext'
import { formatUs, shortClass, shortFile } from '../lib/format'
import { TYPE_LABEL } from '../lib/spans'
import type { TreeNode } from '../lib/tree'
import { useStore } from '../store'
import Pill from '../ui/Pill.vue'
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
  <section class="my-2 ml-4 overflow-hidden rounded-xl border border-line border-l-2 border-l-[var(--accent)]/70 bg-bg/45">
    <header class="flex flex-wrap items-center gap-x-3 gap-y-1 bg-raised/60 px-2.5 py-1.5 text-[12px]">
      <button class="flex min-h-7 items-center gap-1.5 rounded-md pr-1 text-fg outline-none hover:text-accent-ink" type="button" :aria-expanded="open" :aria-label="`${open ? 'Hide' : 'Show'} the code of ${name}`" @click="toggle">
        <ChevronRight class="size-3.5 shrink-0 text-faint transition-transform duration-200" :class="open ? 'rotate-90' : ''" />
        <span class="font-mono text-[12.5px] font-semibold" :title="name">{{ shortClass(name) }}</span>
      </button>
      <span class="font-mono text-[11px] text-faint">{{ shortFile(span.file, span.line) }}</span>
      <Pill :color="`var(--t-${span.type})`">{{ TYPE_LABEL[span.type] }} step · {{ formatUs(span.duration_us) }}</Pill>
    </header>
    <div v-if="open" class="border-t border-line p-2.5">
      <p v-if="problem" class="text-bad" role="alert">{{ problem }}</p>
      <p v-else-if="loading" class="text-muted" aria-busy="true">Loading…</p>
      <CodeView v-else-if="data && data.sources.length > 0" :data="data" :node="node" :nested="true" />
      <p v-else class="text-muted">This step was not traced line by line, so there is no code to show. Select it in the flow to queue its file.</p>
    </div>
  </section>
</template>
