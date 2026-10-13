<script setup lang="ts">
import { autoUpdate, flip, offset, shift, size, useFloating } from '@floating-ui/vue'
import { Pin, X } from 'lucide-vue-next'
import { computed, ref, toRef } from 'vue'
import type { CodeLine } from '../lib/code'
import ValueNode from './ValueNode.vue'

const props = defineProps<{ line: CodeLine; anchor: HTMLElement; pinned: boolean }>()
const emit = defineEmits<{ close: []; hold: []; release: [] }>()

const floating = ref<HTMLElement | null>(null)

// Next to the line, on the side that has room (the code sits at the right edge, so usually on its left), never off the screen.
const { floatingStyles } = useFloating(toRef(props, 'anchor'), floating, {
  placement: 'left-start',
  strategy: 'fixed',
  transform: false,                                  // left/top, because the entrance animation owns `transform`
  whileElementsMounted: autoUpdate,
  middleware: [
    offset(14),
    flip({ fallbackPlacements: ['right-start', 'bottom-start', 'top-start'], padding: 10 }),
    shift({ padding: 10 }),
    size({
      padding: 10,
      apply({ availableHeight, elements }) {
        elements.floating.style.maxHeight = `${Math.max(180, Math.min(availableHeight, 560))}px`
      },
    }),
  ],
})

const hits = computed(() => (props.line.hits > 1 ? `ran ${props.line.hits}×` : ''))
</script>

<template>
  <Teleport to="body">
    <div
      ref="floating"
      class="glass line-popover z-[75] w-[400px] max-w-[calc(100vw-20px)] animate-pop-in overflow-auto rounded-xl border bg-surface/95 p-3.5 shadow-[0_24px_60px_-18px_rgba(0,0,0,.65)]"
      :class="pinned ? 'border-[var(--accent)]' : 'border-line-strong'"
      :style="floatingStyles"
      :role="pinned ? 'dialog' : 'tooltip'"
      :aria-label="`Line ${line.n}`"
      @mouseenter="emit('hold')"
      @mouseleave="emit('release')"
    >
      <header class="mb-2 flex items-center gap-2">
        <h3 class="font-mono text-[12.5px] font-semibold">Line {{ line.n }}</h3>
        <span v-if="hits" class="font-mono text-[11px] text-muted">{{ hits }}</span>
        <span class="flex-1"></span>
        <button v-if="pinned" class="grid size-6 place-items-center rounded-md text-muted transition-colors hover:bg-hover hover:text-fg" type="button" aria-label="Close" @click="emit('close')"><X class="size-3.5" /></button>
        <span v-else class="flex items-center gap-1 text-[10.5px] text-faint"><Pin class="size-3" />click the line to keep this open</span>
      </header>
      <p v-if="line.description" class="mb-2.5 text-[12.5px] leading-relaxed">{{ line.description }}</p>
      <p v-else class="mb-2.5 text-muted">Nothing is recorded for this line.</p>
      <div v-if="line.variables.length > 0" class="space-y-2.5">
        <div v-for="(v, i) in line.variables" :key="i">
          <span class="font-mono text-[11.5px] font-semibold text-[var(--t-controller)]">{{ v.label }}</span>
          <div class="mt-1 max-h-[220px] overflow-auto rounded-lg border border-line bg-bg/50 px-2.5 py-1.5"><ValueNode :value="v.value" :open-depth="1" /></div>
        </div>
      </div>
    </div>
  </Teleport>
</template>
