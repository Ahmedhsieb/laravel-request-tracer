<script setup lang="ts">
import { animate } from 'motion-v'
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'

/** A number that counts up to its value when it appears and glides when it changes. */
const props = withDefaults(defineProps<{ value: number; format?: (n: number) => string; duration?: number }>(), { format: (n: number) => String(Math.round(n)), duration: 0.9 })

const shown = ref(0)
let controls: { stop: () => void } | null = null

function run(from: number, to: number): void {
  controls?.stop()
  if (typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
    shown.value = to
    return
  }
  controls = animate(from, to, { duration: props.duration, ease: [0.16, 1, 0.3, 1], onUpdate: (v: number) => { shown.value = v } })
}

onMounted(() => run(0, props.value))
watch(() => props.value, (next, prev) => run(prev, next))
onBeforeUnmount(() => controls?.stop())
</script>

<template>
  <span class="tabular-nums">{{ format(shown) }}</span>
</template>
