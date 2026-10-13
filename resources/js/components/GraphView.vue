<script setup lang="ts">
import { ChevronRight, MousePointerClick } from 'lucide-vue-next'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { graphColumns, isFolder, litSteps } from '../lib/folder'
import type { Flow } from '../lib/flow'
import { formatUs, plural, shortClass } from '../lib/format'
import type { TreeNode } from '../lib/tree'
import TypeIcon from '../ui/TypeIcon.vue'

/**
 * The steps as columns that grow to the right: the first column holds the steps of one level, the step you click is marked, and what is
 * inside it opens in the next column. Nothing is replaced: the path you came by stays on the left, and clicking an earlier step goes back.
 */
const props = defineProps<{ flow: Flow; roots: TreeNode[]; pinned: number | null; slowMs: number }>()
const emit = defineEmits<{ hover: [id: number | null]; pin: [id: number] }>()

const NODE_H = 62
const GAP = 8
const PAD = 14
const COL_GAP = 34
const MIN_COL = 130
const MAX_COL = 290
const RAIL_W = 104
const CHIP_H = 38
/** a level with more steps than this shows the first ones and says how many are left: the waterfall is the place for the rest */
const LIMIT = 60

const holder = ref<HTMLElement | null>(null)
const width = ref(720)
let observer: ResizeObserver | null = null

/** the step the columns lead to: the one that is pinned, or the folder that is open */
const focus = computed<TreeNode | null>(() => (props.pinned !== null ? props.flow.nodeOf(props.pinned) : props.flow.folder.value))

const avail = computed(() => width.value - PAD * 2 - RAIL_W - COL_GAP)
const count = computed(() => Math.min(3, Math.max(1, Math.floor((avail.value + COL_GAP) / (MIN_COL + COL_GAP)))))
const layout = computed(() => graphColumns(props.roots, focus.value, count.value))
const rail = computed(() => layout.value.rail)
const colW = computed(() => {
  const n = Math.max(1, layout.value.columns.length)

  return Math.min(MAX_COL, Math.max(MIN_COL, Math.floor((avail.value - COL_GAP * (n - 1)) / n)))
})
const colX = (j: number): number => PAD + RAIL_W + COL_GAP + j * (colW.value + COL_GAP)

const lit = computed(() => litSteps(props.roots, props.flow.filter.value, props.slowMs, props.flow.include.value))

interface Placed {
  node: TreeNode
  x: number
  y: number
  column: number
  selected: boolean
}

interface Placement {
  nodes: Placed[]
  /** per column: how many steps are not shown */
  left: number[]
  tops: number[]
  heights: number[]
}

/** Each column is centred on the step of the column before it that the path goes through, so the lines between them stay short. */
const placement = computed<Placement>(() => {
  const nodes: Placed[] = []
  const left: number[] = []
  const tops: number[] = []
  const heights: number[] = []
  let anchor = PAD + Math.max(1, rail.value.length) * (CHIP_H + 6) - (CHIP_H + 6) / 2
  layout.value.columns.forEach((col, j) => {
    let shown = col.nodes.slice(0, LIMIT)
    if (col.selected !== null && !shown.includes(col.selected)) shown = [...shown, col.selected]
    const block = shown.length * (NODE_H + GAP) - GAP
    const top = Math.max(PAD, anchor - block / 2)
    shown.forEach((node, i) => nodes.push({ node, x: colX(j), y: top + i * (NODE_H + GAP), column: j, selected: col.selected === node }))
    left.push(col.nodes.length - shown.length)
    tops.push(top)
    heights.push(block)
    const sel = col.selected === null ? -1 : shown.indexOf(col.selected)
    anchor = sel < 0 ? top + block / 2 : top + sel * (NODE_H + GAP) + NODE_H / 2
  })

  return { nodes, left, tops, heights }
})

const height = computed(() => {
  const p = placement.value
  const rails = PAD * 2 + rail.value.length * (CHIP_H + 6)
  const cols = p.tops.map((top, j) => top + (p.heights[j] ?? 0) + ((p.left[j] ?? 0) > 0 ? NODE_H + GAP : 0) + PAD)

  return Math.max(rails, ...cols, 120)
})
const contentWidth = computed(() => colX(Math.max(0, layout.value.columns.length - 1)) + colW.value + PAD)

function edge(x1: number, y1: number, x2: number, y2: number): string {
  const mid = (x1 + x2) / 2

  return `M${x1} ${y1} C${mid} ${y1} ${mid} ${y2} ${x2} ${y2}`
}

const edges = computed(() => {
  const out: Array<{ key: string; d: string; color: string; w: number; strong: boolean }> = []
  const byColumn = new Map<number, Placed[]>()
  placement.value.nodes.forEach((p) => byColumn.set(p.column, [...(byColumn.get(p.column) ?? []), p]))

  const lastChip = rail.value.length - 1
  if (lastChip >= 0) {
    const y1 = PAD + lastChip * (CHIP_H + 6) + CHIP_H / 2
    for (const p of byColumn.get(0) ?? []) {
      out.push({ key: `r${p.node.span.id}`, d: edge(PAD + RAIL_W, y1, p.x, p.y + NODE_H / 2), color: `var(--t-${p.node.span.type})`, w: 1, strong: false })
    }
  }
  layout.value.columns.forEach((col, j) => {
    const from = (byColumn.get(j) ?? []).find((p) => p.selected)
    if (from === undefined) return
    const total = Math.max(1, col.selected?.span.duration_us ?? 1)
    for (const p of byColumn.get(j + 1) ?? []) {
      out.push({ key: `e${from.node.span.id}-${p.node.span.id}`, d: edge(from.x + colW.value, from.y + NODE_H / 2, p.x, p.y + NODE_H / 2), color: `var(--t-${p.node.span.type})`, w: 1 + 3.5 * Math.min(1, p.node.span.duration_us / total), strong: true })
    }
  })

  return out
})

function share(node: TreeNode, of: number): number {
  return Math.min(100, Math.max(1.5, (node.span.duration_us / Math.max(1, of)) * 100))
}

function parentTime(p: Placed): number {
  return layout.value.columns[p.column]?.parent?.span.duration_us ?? p.node.span.duration_us
}

function dim(node: TreeNode): boolean {
  return lit.value !== null && !lit.value.has(node.span.id)
}

function onNodeKey(event: KeyboardEvent, node: TreeNode): void {
  if (event.key === 'Enter' || event.key === ' ' || event.key === 'ArrowRight') {
    event.preventDefault()
    emit('pin', node.span.id)
  } else if (event.key === 'ArrowLeft' || event.key === 'Backspace') {
    event.preventDefault()
    const parent = node.parent
    if (parent !== null) emit('pin', parent.span.id)
  }
}

// a new step opens: bring its row into the upper part of the view (instantly: a smooth scroll through a column that is moving is dizzy)
watch(focus, async () => {
  await nextTick()
  const el = holder.value
  const target = placement.value.nodes.filter((p) => p.node === focus.value).pop()
  if (el === null || target === undefined) return
  el.scrollTop = Math.max(0, target.y - el.clientHeight / 3)
})

onMounted(() => {
  width.value = holder.value?.clientWidth ?? width.value
  if (holder.value !== null && typeof ResizeObserver !== 'undefined') {
    observer = new ResizeObserver(() => {
      width.value = holder.value?.clientWidth ?? width.value
    })
    observer.observe(holder.value)
  }
})

onBeforeUnmount(() => observer?.disconnect())
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <p class="flex shrink-0 items-center gap-1.5 border-b border-line/60 px-3 py-1.5 text-[11.5px] text-muted">
      <MousePointerClick class="size-3.5 shrink-0 text-faint" />Click a step to open what is inside it on the right. Click one on the left to go back.
    </p>

    <div ref="holder" class="relative min-h-0 flex-1 overflow-auto" role="tree" aria-label="Steps as columns" @pointerleave="emit('hover', null)">
      <div class="relative" :style="{ height: `${height}px`, minWidth: `${contentWidth}px` }">
        <!-- the lines from a step to what is inside it, as thick as the share of the time it took -->
        <svg class="pointer-events-none absolute inset-0" :width="Math.max(contentWidth, width)" :height="height" aria-hidden="true">
          <path
            v-for="e in edges"
            :key="e.key"
            :d="e.d"
            fill="none"
            :stroke="e.color"
            :stroke-width="e.w"
            stroke-linecap="round"
            pathLength="1"
            stroke-dasharray="1"
            class="animate-draw"
            :style="{ '--len': 1, opacity: e.strong ? 0.6 : 0.2 }"
          />
        </svg>

        <!-- the way down: the steps on the path that are further left than the columns -->
        <div class="absolute" :style="{ left: `${PAD}px`, top: `${PAD}px`, width: `${RAIL_W}px` }">
          <button
            v-for="(c, i) in rail"
            :key="c.span.id"
            class="mb-1.5 flex w-full items-center gap-1.5 rounded-lg border px-1.5 text-left outline-none transition-colors hover:bg-hover focus-visible:border-[var(--accent)]"
            :class="i === rail.length - 1 ? 'border-[var(--accent)]/50 bg-[var(--accent)]/10' : 'border-line bg-surface/60'"
            :style="{ height: `${CHIP_H}px` }"
            type="button"
            :title="c.span.label"
            @click="emit('pin', c.span.id)"
            @pointerenter="emit('hover', c.span.id)"
          >
            <TypeIcon :type="c.span.type" /><span class="line-clamp-2 min-w-0 break-all font-mono text-[10.5px] leading-tight">{{ shortClass(c.span.label) }}</span>
          </button>
        </div>

        <div
          v-for="p in placement.nodes"
          :key="p.node.span.id"
          class="absolute animate-pop-in cursor-pointer overflow-hidden rounded-xl border bg-surface p-2.5 outline-none transition-[left,top,opacity,border-color,background-color,box-shadow] duration-300 hover:border-line-strong hover:bg-hover focus-visible:border-[var(--accent)]"
          :class="[
            p.node.span.id === pinned ? 'border-[var(--accent)] shadow-[0_0_0_3px_color-mix(in_srgb,var(--accent)_22%,transparent)]' : p.selected ? 'border-[var(--accent)]/55 bg-[var(--accent)]/10' : 'border-line',
            dim(p.node) ? 'opacity-35 hover:opacity-100' : '',
          ]"
          :style="{ left: `${p.x}px`, top: `${p.y}px`, width: `${colW}px`, height: `${NODE_H}px` }"
          role="treeitem"
          tabindex="0"
          :aria-selected="p.node.span.id === pinned"
          :aria-expanded="isFolder(p.node) ? p.selected : undefined"
          @click="emit('pin', p.node.span.id)"
          @pointerenter="emit('hover', p.node.span.id)"
          @focus="emit('hover', p.node.span.id)"
          @keydown="onNodeKey($event, p.node)"
        >
          <span class="flex items-start gap-2">
            <span class="mt-0.5 grid size-6 shrink-0 place-items-center rounded-md" :style="{ background: `color-mix(in srgb, var(--t-${p.node.span.type}) 16%, transparent)` }"><TypeIcon :type="p.node.span.type" /></span>
            <span class="line-clamp-2 min-w-0 flex-1 break-words font-mono text-[12px] leading-snug" :title="p.node.span.label">{{ shortClass(p.node.span.label) }}</span>
          </span>
          <span class="absolute inset-x-2.5 bottom-2 flex items-center gap-2">
            <span class="h-[3px] min-w-0 flex-1 overflow-hidden rounded-full bg-line"><span class="block h-full rounded-full" :style="{ width: `${share(p.node, parentTime(p))}%`, background: `var(--t-${p.node.span.type})` }"></span></span>
            <span class="shrink-0 font-mono text-[10.5px] tabular-nums text-muted">{{ formatUs(p.node.span.duration_us) }}</span>
            <span v-if="isFolder(p.node)" class="flex shrink-0 items-center font-mono text-[10px] text-faint" :title="`${plural(p.node.descendants, 'step')} inside`">{{ p.node.descendants }}<ChevronRight class="size-2.5" :class="p.selected ? 'text-[var(--accent)]' : ''" /></span>
          </span>
        </div>

        <template v-for="(n, j) in placement.left" :key="`more-${j}`">
          <div
            v-if="n > 0"
            class="absolute grid place-items-center rounded-xl border border-dashed border-line-strong p-2 text-center text-[11.5px] text-muted"
            :style="{ left: `${colX(j)}px`, top: `${(placement.tops[j] ?? 0) + (placement.heights[j] ?? 0) + GAP}px`, width: `${colW}px`, height: `${NODE_H}px` }"
          >{{ n }} more at this level. Filter them, or use the waterfall.</div>
        </template>

        <p v-if="layout.columns.length === 0" class="absolute left-1/2 top-10 -translate-x-1/2 text-muted">Nothing here.</p>
      </div>
    </div>
  </div>
</template>
