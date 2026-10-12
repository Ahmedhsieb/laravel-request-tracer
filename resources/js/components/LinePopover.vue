<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { CodeLine } from '../lib/code'
import ValueNode from './ValueNode.vue'

const props = defineProps<{ line: CodeLine; anchor: HTMLElement; pinned: boolean }>()
const emit = defineEmits<{ close: []; hold: []; release: [] }>()

const el = ref<HTMLElement | null>(null)
const style = ref<Record<string, string>>({ visibility: 'hidden' })

const WIDTH = 400
const GAP = 12
const MARGIN = 8

/** Next to the line, on the side that has room (the code sits at the right edge, so usually on its left), never off the screen. */
function place(): void {
  const rect = props.anchor.getBoundingClientRect()
  const height = el.value?.offsetHeight ?? 220
  const vw = window.innerWidth
  const vh = window.innerHeight
  const width = Math.min(WIDTH, vw - 2 * MARGIN)

  let left: number
  let top: number
  if (rect.left - GAP - width >= MARGIN) {
    left = rect.left - GAP - width
    top = rect.top - 6
  } else if (vw - rect.right - GAP - width >= MARGIN) {
    left = rect.right + GAP
    top = rect.top - 6
  } else {
    left = Math.min(Math.max(MARGIN, rect.left), vw - width - MARGIN)
    top = rect.bottom + 6 + height > vh - MARGIN ? rect.top - height - 6 : rect.bottom + 6
  }
  top = Math.min(Math.max(MARGIN, top), Math.max(MARGIN, vh - height - MARGIN))
  style.value = { left: `${left}px`, top: `${top}px`, width: `${width}px` }
}

watch(() => [props.line, props.anchor], async () => {
  await nextTick()
  place()
}, { immediate: false })

onMounted(async () => {
  await nextTick()
  place()
  window.addEventListener('resize', place)
  window.addEventListener('scroll', place, true)
})
onBeforeUnmount(() => {
  window.removeEventListener('resize', place)
  window.removeEventListener('scroll', place, true)
})
</script>

<template>
  <Teleport to="body">
    <div
      ref="el"
      class="line-popover"
      :class="{ pinned }"
      :style="style"
      :role="pinned ? 'dialog' : 'tooltip'"
      :aria-label="`Line ${line.n}`"
      @mouseenter="emit('hold')"
      @mouseleave="emit('release')"
    >
      <header>
        <h3>Line {{ line.n }}</h3>
        <span v-if="line.hits > 1" class="muted mono">ran {{ line.hits }}×</span>
        <span class="spacer"></span>
        <button v-if="pinned" class="close" type="button" aria-label="Close" @click="emit('close')">✕</button>
        <span v-else class="hint">click the line to keep this open</span>
      </header>
      <p v-if="line.description">{{ line.description }}</p>
      <p v-else class="muted">Nothing is recorded for this line.</p>
      <div v-if="line.variables.length > 0" class="vars">
        <div v-for="(v, i) in line.variables" :key="i" class="var">
          <span class="k">{{ v.label }}</span>
          <div class="mono dd" style="max-height: 200px"><ValueNode :value="v.value" :open-depth="1" /></div>
        </div>
      </div>
    </div>
  </Teleport>
</template>
