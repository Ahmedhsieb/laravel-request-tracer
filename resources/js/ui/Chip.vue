<script setup lang="ts">
import { cn } from '../lib/cn'

/** A toggle chip: pressed means "this filter is on". `color` tints it (a type colour). */
defineProps<{ active?: boolean; color?: string; class?: string }>()
</script>

<template>
  <button
    type="button"
    :aria-pressed="active"
    :class="cn('inline-flex h-7 shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full border px-3 text-[12px] font-medium outline-none transition-all duration-200 active:scale-[.96]', active ? 'border-transparent text-fg' : 'border-line text-muted hover:border-line-strong hover:text-fg', $props.class)"
    :style="active ? { background: `color-mix(in srgb, ${color ?? 'var(--accent)'} 18%, transparent)`, boxShadow: `inset 0 0 0 1px color-mix(in srgb, ${color ?? 'var(--accent)'} 55%, transparent)` } : undefined"
  ><slot /></button>
</template>
