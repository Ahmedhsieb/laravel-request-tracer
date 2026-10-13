<script setup lang="ts">
import { ChevronRight } from 'lucide-vue-next'
import { CollapsibleContent, CollapsibleRoot, CollapsibleTrigger } from 'reka-ui'
import Pill from '../ui/Pill.vue'

/** A route group of a list: a header that closes and opens it, and its rows in the slot. */
defineProps<{ name: string; count: number; open: boolean; worst: 'bad' | 'warn' | 'ok'; slowest: string; note?: string; noteTone?: 'bad' | 'warn' }>()
defineEmits<{ toggle: [] }>()
</script>

<template>
  <CollapsibleRoot :open="open" @update:open="$emit('toggle')">
    <CollapsibleTrigger
      class="group sticky top-0 z-[5] flex w-full items-center gap-2 border-b border-line bg-surface/90 px-3 py-1.5 backdrop-blur-md outline-none transition-colors hover:bg-hover"
      :aria-label="`${name}: ${count}`"
    >
      <ChevronRight class="size-3.5 shrink-0 text-faint transition-transform duration-200 group-data-[state=open]:rotate-90" />
      <span class="min-w-0 truncate font-mono text-[12px] font-semibold text-fg">{{ name }}</span>
      <Pill>{{ count }}</Pill>
      <Pill v-if="note" :tone="noteTone ?? 'warn'">{{ note }}</Pill>
      <span class="ml-auto flex shrink-0 items-center gap-2 text-[11px] text-faint">
        <span class="hidden sm:inline">slowest {{ slowest }}</span>
        <span class="size-2 rounded-full" :style="{ background: worst === 'bad' ? 'var(--bad)' : worst === 'warn' ? 'var(--warn)' : 'var(--ok)' }"></span>
      </span>
    </CollapsibleTrigger>
    <CollapsibleContent class="overflow-hidden data-[state=closed]:animate-collapse-up data-[state=open]:animate-collapse-down">
      <slot />
    </CollapsibleContent>
  </CollapsibleRoot>
</template>
