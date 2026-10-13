<script setup lang="ts">
import { computed } from 'vue'

/** A small label. `tone` picks a status colour; `color` takes any CSS colour (a type colour, for instance). */
const props = withDefaults(defineProps<{ tone?: 'neutral' | 'ok' | 'warn' | 'bad' | 'info' | 'accent'; color?: string; solid?: boolean }>(), { tone: 'neutral', color: undefined, solid: false })

const colour = computed(() => props.color ?? ({
  neutral: 'var(--muted)',
  ok: 'var(--ok)',
  warn: 'var(--warn)',
  bad: 'var(--bad)',
  info: 'var(--info)',
  accent: 'var(--accent-ink)',
})[props.tone])
</script>

<template>
  <span
    class="inline-flex h-[19px] shrink-0 items-center gap-1 rounded-[6px] px-1.5 text-[10.5px] font-semibold uppercase leading-none tracking-[.04em]"
    :class="solid ? 'text-[#04141c]' : ''"
    :style="{ color: solid ? undefined : colour, background: solid ? colour : `color-mix(in srgb, ${colour} 14%, transparent)` }"
  ><slot /></span>
</template>
