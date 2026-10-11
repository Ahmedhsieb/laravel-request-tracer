<script setup lang="ts">
import { computed, ref } from 'vue'
import type { Dump } from '../api/types'
import { flags, inline, toText } from '../lib/value'
import { useStore } from '../store'
import ValueNode from './ValueNode.vue'

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
  try {
    await navigator.clipboard.writeText(toText(props.value))
    store.toast('Copied')
  } catch {
    store.toast('Copy failed: select the text and copy it by hand')
  }
}
</script>

<template>
  <section class="value-block" :aria-label="title">
    <header>
      <strong>{{ title }}</strong>
      <span class="mono muted" style="text-transform: none; letter-spacing: 0">{{ summary }}</span>
      <span v-if="marks.masked" class="marker masked" title="Secrets are masked before anything is stored">masked</span>
      <span v-if="marks.truncated" class="marker truncated" title="Large values are cut to the configured limits">truncated</span>
      <span class="spacer"></span>
      <button class="btn small ghost" type="button" @click="expandAll">Expand</button>
      <button class="btn small ghost" type="button" @click="collapseAll">Collapse</button>
      <button class="btn small ghost" type="button" @click="copy">Copy</button>
    </header>
    <div class="dd mono">
      <ValueNode :key="generation" :value="value" :open-depth="openDepth" />
    </div>
  </section>
</template>
