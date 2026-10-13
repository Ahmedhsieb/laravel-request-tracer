<script setup lang="ts">
import { ChevronsDownUp, ChevronsUpDown, Copy } from 'lucide-vue-next'
import { computed, ref } from 'vue'
import type { Dump } from '../api/types'
import { flags, inline, toText } from '../lib/value'
import { useStore } from '../store'
import Btn from '../ui/Btn.vue'
import Pill from '../ui/Pill.vue'
import Tip from '../ui/Tip.vue'
import ValueNode from './ValueNode.vue'
import { copyText } from '../lib/clipboard'

const props = defineProps<{ title: string; value: Dump }>()

const store = useStore()
const marks = computed(() => flags(props.value))
const summary = computed(() => inline(props.value, 80))
const openDepth = ref(2)
const generation = ref(0)

function expandAll(): void {
  openDepth.value = 99
  generation.value++
}

function collapseAll(): void {
  openDepth.value = 0
  generation.value++
}

async function copy(): Promise<void> {
  store.toast((await copyText(toText(props.value))) ? 'Copied' : 'Copy failed: select the text and copy it by hand')
}
</script>

<template>
  <section class="overflow-hidden rounded-xl border border-line bg-bg/40" :aria-label="title">
    <header class="flex items-center gap-2 border-b border-line bg-raised/60 px-3 py-1.5">
      <strong class="text-[11px] font-semibold uppercase tracking-[.08em] text-muted">{{ title }}</strong>
      <span class="min-w-0 truncate font-mono text-[11.5px] text-faint">{{ summary }}</span>
      <Tip text="Secrets are masked before anything is stored"><Pill v-if="marks.masked" color="var(--t-event)">masked</Pill></Tip>
      <Tip text="Large values are cut to the configured limits"><Pill v-if="marks.truncated" tone="warn">truncated</Pill></Tip>
      <span class="flex-1"></span>
      <Tip text="Expand everything"><Btn variant="ghost" size="icon-sm" aria-label="Expand" @click="expandAll"><ChevronsUpDown class="size-3.5" /></Btn></Tip>
      <Tip text="Collapse everything"><Btn variant="ghost" size="icon-sm" aria-label="Collapse" @click="collapseAll"><ChevronsDownUp class="size-3.5" /></Btn></Tip>
      <Tip text="Copy"><Btn variant="ghost" size="icon-sm" aria-label="Copy" @click="copy"><Copy class="size-3.5" /></Btn></Tip>
    </header>
    <div class="max-h-[440px] overflow-auto px-3 py-2.5">
      <ValueNode :key="generation" :value="value" :open-depth="openDepth" />
    </div>
  </section>
</template>
