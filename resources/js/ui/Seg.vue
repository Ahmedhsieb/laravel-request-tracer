<script setup lang="ts" generic="T extends string">
import { ToggleGroupItem, ToggleGroupRoot } from 'reka-ui'
import { computed } from 'vue'
import { cn } from '../lib/cn'

/** A segmented control: equal-width options and a pill that slides to the chosen one. */
const model = defineModel<T>({ required: true })

const props = withDefaults(defineProps<{ options: Array<{ value: T; label: string; count?: number }>; size?: 'sm' | 'md'; label?: string; class?: string }>(), { size: 'md', label: undefined, class: undefined })

const index = computed(() => Math.max(0, props.options.findIndex((o) => o.value === model.value)))

function pick(value: T | undefined): void {
  if (value !== undefined && value !== '') model.value = value as T
}
</script>

<template>
  <ToggleGroupRoot
    type="single"
    :model-value="model"
    :aria-label="label"
    :class="cn('relative grid auto-cols-fr grid-flow-col rounded-[10px] border border-line bg-raised p-[3px]', props.class)"
    @update:model-value="(v) => pick(v as T | undefined)"
  >
    <span
      class="pointer-events-none absolute inset-y-[3px] left-[3px] rounded-[7px] bg-surface shadow-[0_1px_0_0_var(--line-strong),0_4px_12px_-6px_rgba(0,0,0,.5)] transition-transform duration-300 ease-[var(--ease-out-expo)]"
      :style="{ width: `calc((100% - 6px) / ${options.length})`, transform: `translateX(${index * 100}%)` }"
      aria-hidden="true"
    ></span>
    <ToggleGroupItem
      v-for="o in options"
      :key="o.value"
      :value="o.value"
      :class="cn('relative z-10 flex items-center justify-center gap-1.5 rounded-[7px] font-medium text-muted outline-none transition-colors hover:text-fg data-[state=on]:text-fg', size === 'sm' ? 'h-6 px-2.5 text-[12px]' : 'h-7 px-3 text-[12.5px]')"
    >
      {{ o.label }}<span v-if="o.count !== undefined" class="rounded-md bg-hover px-1 text-[10.5px] tabular-nums text-faint">{{ o.count }}</span>
    </ToggleGroupItem>
  </ToggleGroupRoot>
</template>
