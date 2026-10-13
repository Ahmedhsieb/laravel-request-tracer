<script setup lang="ts">
import { useResizeObserver } from '@vueuse/core'
import { ZoomOut } from 'lucide-vue-next'
import { computed, onMounted, ref, watch } from 'vue'
import { formatUs } from '../lib/format'
import { useTheme } from '../lib/theme'
import { rangeFromBrush, sameRange, type Range } from '../lib/timeline'
import type { TreeNode } from '../lib/tree'
import Btn from '../ui/Btn.vue'

/**
 * The whole request on one strip: every step as a coloured stretch of time, one lane per depth. Drag over it to zoom the timeline of the
 * steps to that stretch, double-click (or the button) to see everything again. The step that is selected is outlined.
 */
const props = defineProps<{ roots: TreeNode[]; totalUs: number; marker: { start: number; duration: number } | null }>()
const range = defineModel<Range>('range', { required: true })

const { mode } = useTheme()
const canvas = ref<HTMLCanvasElement | null>(null)
const box = ref<HTMLElement | null>(null)
/** the drag in progress, as two places in 0..1 */
const brush = ref<[number, number] | null>(null)

const HEIGHT = 46
const zoomed = computed(() => !sameRange(range.value, { from: 0, to: props.totalUs }))
const shade = computed(() => {
  const a = brush.value
  if (a !== null) return { left: Math.min(a[0], a[1]) * 100, width: Math.abs(a[1] - a[0]) * 100 }
  if (!zoomed.value) return null

  return { left: (range.value.from / props.totalUs) * 100, width: ((range.value.to - range.value.from) / props.totalUs) * 100 }
})
const markerStyle = computed(() => (props.marker === null ? null : {
  left: `${(props.marker.start / props.totalUs) * 100}%`,
  width: `max(3px, ${(props.marker.duration / props.totalUs) * 100}%)`,
}))

function draw(): void {
  const el = canvas.value
  const holder = box.value
  if (el === null || holder === null) return
  const width = holder.clientWidth
  const ratio = window.devicePixelRatio || 1
  el.width = Math.max(1, Math.floor(width * ratio))
  el.height = Math.floor(HEIGHT * ratio)
  el.style.width = `${width}px`
  el.style.height = `${HEIGHT}px`
  const ctx = el.getContext('2d')
  if (ctx === null) return
  ctx.scale(ratio, ratio)
  ctx.clearRect(0, 0, width, HEIGHT)

  const css = getComputedStyle(document.documentElement)
  const colors = new Map<string, string>()
  const colorOf = (type: string): string => {
    let c = colors.get(type)
    if (c === undefined) {
      c = css.getPropertyValue(`--t-${type}`).trim() || '#94a3b8'
      colors.set(type, c)
    }

    return c
  }

  let deepest = 0
  const nodes: TreeNode[] = []
  const visit = (node: TreeNode): void => {
    nodes.push(node)
    deepest = Math.max(deepest, node.depth)
    node.children.forEach(visit)
  }
  props.roots.forEach(visit)

  const lanes = Math.min(deepest + 1, 9)
  const laneHeight = (HEIGHT - 6) / lanes
  for (const node of nodes) {
    const x = (node.span.start_us / props.totalUs) * width
    const w = Math.max(1.2, (node.span.duration_us / props.totalUs) * width)
    const lane = Math.min(node.depth, lanes - 1)
    ctx.globalAlpha = 0.9
    ctx.fillStyle = colorOf(node.span.type)
    ctx.fillRect(x, 3 + lane * laneHeight, w, Math.max(2, laneHeight - 1.5))
  }
  ctx.globalAlpha = 1
}

function at(event: PointerEvent): number {
  const rect = box.value?.getBoundingClientRect()
  if (rect === undefined || rect.width <= 0) return 0

  return Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width))
}

function down(event: PointerEvent): void {
  if (event.button !== 0) return
  ;(event.currentTarget as HTMLElement).setPointerCapture(event.pointerId)
  const start = at(event)
  brush.value = [start, start]
}

function move(event: PointerEvent): void {
  if (brush.value !== null) brush.value = [brush.value[0], at(event)]
}

function up(): void {
  const dragged = brush.value
  brush.value = null
  if (dragged === null) return
  const next = rangeFromBrush(dragged[0], dragged[1], props.totalUs)
  if (next !== null) range.value = next
}

function reset(): void {
  range.value = { from: 0, to: props.totalUs }
}

onMounted(draw)
useResizeObserver(box, draw)
watch(() => [props.roots, props.totalUs, mode.value], draw, { flush: 'post' })
</script>

<template>
  <div class="flex items-center gap-2">
    <div
      ref="box"
      class="relative h-[46px] min-w-0 flex-1 cursor-crosshair select-none overflow-hidden rounded-lg border border-line bg-raised/70"
      role="img"
      :aria-label="`The whole request, ${formatUs(totalUs)}: drag over it to zoom the timeline`"
      title="Drag to zoom the timeline · double-click to see everything"
      @pointerdown="down"
      @pointermove="move"
      @pointerup="up"
      @pointercancel="up"
      @dblclick="reset"
    >
      <canvas ref="canvas" class="block"></canvas>
      <template v-if="shade">
        <span class="pointer-events-none absolute inset-y-0 left-0 bg-bg/60" :style="{ width: `${shade.left}%` }"></span>
        <span class="pointer-events-none absolute inset-y-0 right-0 bg-bg/60" :style="{ width: `${100 - shade.left - shade.width}%` }"></span>
        <span class="pointer-events-none absolute inset-y-0 border-x-2 border-[var(--accent)] shadow-[0_0_14px_-2px_var(--accent)]" :style="{ left: `${shade.left}%`, width: `${shade.width}%` }"></span>
      </template>
      <span v-if="markerStyle" class="pointer-events-none absolute inset-y-0 border-x border-fg/80 bg-fg/15" :style="markerStyle"></span>
    </div>
    <Btn v-if="zoomed" variant="soft" size="sm" class="animate-pop-in" @click="reset"><ZoomOut class="size-3.5" />Reset zoom</Btn>
  </div>
</template>
