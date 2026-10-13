<script setup lang="ts">
import { ChevronRight, FolderTree } from 'lucide-vue-next'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { isFolder } from '../lib/folder'
import type { Flow } from '../lib/flow'
import { formatUs, plural, shortClass } from '../lib/format'
import type { TreeNode } from '../lib/tree'
import TypeIcon from '../ui/TypeIcon.vue'

const props = defineProps<{ flow: Flow; pinned: number | null }>()
const emit = defineEmits<{ hover: [id: number | null]; select: [id: number] }>()

const NODE_H = 72
const GAP = 10
const PAD = 14
const COL_GAP = 46
const MIN_COL = 150
const MAX_COL = 300
/** a folder with more steps than this shows the first ones and says how many are left: the waterfall is the place for the rest */
const LIMIT = 60

const { list, folder, folderId } = props.flow

const holder = ref<HTMLElement | null>(null)
const width = ref(720)
let observer: ResizeObserver | null = null

interface Placed {
  node: TreeNode
  x: number
  y: number
  trail: TreeNode[]
}

/** two columns (the folder and its steps), three while a folder of the list is pinned (and what is inside it) */
const columns = computed(() => (shown.value.some((e) => e.node.span.id === props.pinned && isFolder(e.node)) ? 3 : 2))
const colW = computed(() => Math.min(MAX_COL, Math.max(MIN_COL, Math.floor((width.value - PAD * 2 - COL_GAP * (columns.value - 1)) / columns.value))))
const colX = (n: number): number => PAD + n * (colW.value + COL_GAP)

const shown = computed(() => list.value.entries.slice(0, LIMIT))
const left = computed(() => Math.max(0, list.value.entries.length - LIMIT))

/** what the open folder spans, to give every step its share of it */
const spanUs = computed(() => Math.max(1, folder.value?.span.duration_us ?? Math.max(1, ...list.value.entries.map((e) => e.node.span.duration_us))))

const children = computed<Placed[]>(() => shown.value.map((e, i) => ({ node: e.node, trail: e.trail, x: colX(1), y: PAD + i * (NODE_H + GAP) })))
const childrenHeight = computed(() => children.value.length * (NODE_H + GAP) + (left.value > 0 ? NODE_H : 0))

/** the step whose insides the third column shows: the one that is pinned, when it is one of the listed folders */
const focus = computed(() => children.value.find((c) => c.node.span.id === props.pinned && isFolder(c.node)) ?? null)

const grand = computed<Placed[]>(() => {
  const f = focus.value
  if (f === null) return []
  const kids = f.node.children.slice(0, LIMIT)
  const block = kids.length * (NODE_H + GAP) - GAP
  const top = Math.max(PAD, f.y + NODE_H / 2 - block / 2)

  return kids.map((node, i) => ({ node, trail: [], x: colX(2), y: top + i * (NODE_H + GAP) }))
})

const rootY = computed(() => PAD + Math.min(Math.max(0, (childrenHeight.value - NODE_H) / 2), 3 * (NODE_H + GAP)))
const height = computed(() => Math.max(childrenHeight.value + PAD * 2, ...grand.value.map((g) => g.y + NODE_H + PAD), rootY.value + NODE_H + PAD))
const contentWidth = computed(() => colX(columns.value - 1) + colW.value + PAD)

function edge(fromX: number, fromY: number, toX: number, toY: number): string {
  const x1 = fromX + colW.value
  const y1 = fromY + NODE_H / 2
  const y2 = toY + NODE_H / 2
  const mid = (x1 + toX) / 2

  return `M${x1} ${y1} C${mid} ${y1} ${mid} ${y2} ${toX} ${y2}`
}

const edges = computed(() => {
  const out: Array<{ key: string; d: string; color: string; w: number; hot: boolean }> = []
  const total = spanUs.value
  for (const c of children.value) {
    out.push({ key: `c${c.node.span.id}`, d: edge(colX(0), rootY.value, c.x, c.y), color: `var(--t-${c.node.span.type})`, w: 1 + 4 * Math.min(1, c.node.span.duration_us / total), hot: focus.value?.node.span.id === c.node.span.id })
  }
  const f = focus.value
  if (f !== null) {
    for (const g of grand.value) {
      out.push({ key: `g${g.node.span.id}`, d: edge(f.x, f.y, g.x, g.y), color: `var(--t-${g.node.span.type})`, w: 1 + 4 * Math.min(1, g.node.span.duration_us / Math.max(1, f.node.span.duration_us)), hot: true })
    }
  }

  return out
})

function share(node: TreeNode, of: number): number {
  return Math.min(100, Math.max(1.5, (node.span.duration_us / Math.max(1, of)) * 100))
}

function onNodeKey(event: KeyboardEvent, node: TreeNode): void {
  if (event.key === 'Enter' || event.key === ' ') {
    event.preventDefault()
    emit('select', node.span.id)
  } else if (event.key === 'ArrowRight' && isFolder(node)) {
    event.preventDefault()
    props.flow.open(node.span.id)
  } else if (event.key === 'ArrowLeft' || event.key === 'Backspace') {
    event.preventDefault()
    props.flow.up()
  }
}

// the folder that is pinned opens a third column on the right: bring it into view, and go back when it closes
watch(columns, async (count) => {
  await nextTick()
  requestAnimationFrame(() => {
    const el = holder.value
    if (el !== null) el.scrollTo({ left: count === 3 ? el.scrollWidth - el.clientWidth : 0, behavior: 'smooth' })
  })
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
  <div ref="holder" class="relative min-h-0 flex-1 overflow-auto" role="tree" aria-label="Steps of the open folder as a graph" @pointerleave="emit('hover', null)">
    <div :key="`${folderId ?? 'top'}`" class="relative" :style="{ height: `${height}px`, minWidth: `${contentWidth}px` }">
      <!-- the lines between a step and what is inside it, as thick as the share of the time it took -->
      <svg class="pointer-events-none absolute inset-0" :width="Math.max(contentWidth, width)" :height="height" aria-hidden="true">
        <path
          v-for="(e, i) in edges"
          :key="e.key"
          :d="e.d"
          fill="none"
          :stroke="e.color"
          :stroke-width="e.w"
          stroke-linecap="round"
          pathLength="1"
          stroke-dasharray="1"
          class="animate-draw"
          :style="{ '--len': 1, opacity: e.hot ? 0.8 : 0.38, animationDelay: `${Math.min(i, 20) * 25}ms` }"
        />
      </svg>

      <!-- the open folder -->
      <button
        class="absolute animate-pop-in rounded-2xl border border-[var(--accent)]/60 bg-[var(--accent)]/10 p-3 text-left outline-none transition-colors hover:bg-[var(--accent)]/16 focus-visible:border-[var(--accent)]"
        :style="{ left: `${colX(0)}px`, top: `${rootY}px`, width: `${colW}px`, height: `${NODE_H}px` }"
        type="button"
        :aria-label="folder === null ? 'The request' : `Open folder ${folder.span.label}`"
        @click="folder !== null && emit('select', folder.span.id)"
        @pointerenter="folder !== null && emit('hover', folder.span.id)"
      >
        <span class="flex items-center gap-2">
          <span v-if="folder !== null" class="grid size-6 shrink-0 place-items-center rounded-md" :style="{ background: `color-mix(in srgb, var(--t-${folder.span.type}) 18%, transparent)` }"><TypeIcon :type="folder.span.type" /></span>
          <span class="line-clamp-2 min-w-0 break-words font-mono text-[12px] font-semibold leading-snug" :title="folder?.span.label">{{ folder === null ? 'Request' : shortClass(folder.span.label) }}</span>
        </span>
        <span class="mt-1.5 block truncate text-[11px] text-muted">
          <span v-if="folder !== null" class="font-mono text-fg">{{ formatUs(folder.span.duration_us) }} · </span>{{ plural(list.scope, 'step') }}
        </span>
      </button>

      <!-- its steps -->
      <div
        v-for="(c, i) in children"
        :key="`${folderId}-${c.node.span.id}`"
        class="group/node absolute animate-pop-in cursor-pointer overflow-hidden rounded-xl border bg-surface p-2.5 outline-none transition-[border-color,background-color,box-shadow] hover:border-line-strong hover:bg-hover focus-visible:border-[var(--accent)]"
        :class="pinned === c.node.span.id ? 'border-[var(--accent)] shadow-[0_0_0_3px_color-mix(in_srgb,var(--accent)_20%,transparent)]' : 'border-line'"
        :style="{ left: `${c.x}px`, top: `${c.y}px`, width: `${colW}px`, height: `${NODE_H}px`, animationDelay: `${Math.min(i, 14) * 28}ms` }"
        role="treeitem"
        tabindex="0"
        :aria-selected="pinned === c.node.span.id"
        @click="emit('select', c.node.span.id)"
        @dblclick="flow.open(c.node.span.id)"
        @pointerenter="emit('hover', c.node.span.id)"
        @focus="emit('hover', c.node.span.id)"
        @keydown="onNodeKey($event, c.node)"
      >
        <span class="flex items-start gap-2">
          <span class="mt-0.5 grid size-6 shrink-0 place-items-center rounded-md" :style="{ background: `color-mix(in srgb, var(--t-${c.node.span.type}) 16%, transparent)` }"><TypeIcon :type="c.node.span.type" /></span>
          <span class="min-w-0 flex-1">
            <span class="line-clamp-2 break-words font-mono text-[12px] leading-snug" :title="c.node.span.label">{{ shortClass(c.node.span.label) }}</span>
            <span v-if="c.trail.length > 0" class="block truncate text-[10.5px] text-faint [direction:rtl] [text-align:left]"><bdi>{{ c.trail.map((n) => shortClass(n.span.label)).join(' › ') }} ›</bdi></span>
          </span>
        </span>
        <span class="absolute inset-x-2.5 bottom-2 flex items-center gap-2">
          <span class="h-[3px] min-w-0 flex-1 overflow-hidden rounded-full bg-line"><span class="block h-full rounded-full" :style="{ width: `${share(c.node, spanUs)}%`, background: `var(--t-${c.node.span.type})` }"></span></span>
          <span class="shrink-0 font-mono text-[10.5px] tabular-nums text-muted">{{ formatUs(c.node.span.duration_us) }}</span>
          <button
            v-if="isFolder(c.node)"
            class="flex h-5 shrink-0 items-center gap-0.5 rounded-md border border-line bg-raised px-1.5 font-mono text-[10px] text-muted outline-none transition-colors hover:border-[var(--accent)] hover:text-fg focus-visible:border-[var(--accent)]"
            type="button"
            :aria-label="`Open ${shortClass(c.node.span.label)}: ${plural(c.node.descendants, 'step')} inside`"
            @click.stop="flow.open(c.node.span.id)"
          ><FolderTree class="size-2.5" />{{ c.node.descendants }}<ChevronRight class="size-2.5" /></button>
        </span>
      </div>

      <div
        v-if="left > 0"
        class="absolute grid place-items-center rounded-xl border border-dashed border-line-strong p-2 text-center text-[11.5px] text-muted"
        :style="{ left: `${colX(1)}px`, top: `${PAD + children.length * (NODE_H + GAP)}px`, width: `${colW}px`, height: `${NODE_H}px` }"
      >{{ left }} more here. Filter them, or use the waterfall.</div>

      <!-- what is inside the step that is pinned -->
      <div
        v-for="(g, i) in grand"
        :key="`g-${g.node.span.id}`"
        class="absolute animate-pop-in cursor-pointer overflow-hidden rounded-xl border bg-surface/80 p-2.5 outline-none transition-[border-color,background-color] hover:border-line-strong hover:bg-hover"
        :class="pinned === g.node.span.id ? 'border-[var(--accent)]' : 'border-line'"
        :style="{ left: `${g.x}px`, top: `${g.y}px`, width: `${colW}px`, height: `${NODE_H}px`, animationDelay: `${Math.min(i, 10) * 24}ms` }"
        role="treeitem"
        tabindex="0"
        :aria-selected="pinned === g.node.span.id"
        @click="emit('select', g.node.span.id)"
        @dblclick="flow.open(g.node.span.id)"
        @pointerenter="emit('hover', g.node.span.id)"
        @keydown="onNodeKey($event, g.node)"
      >
        <span class="flex items-start gap-2">
          <span class="mt-0.5 grid size-6 shrink-0 place-items-center rounded-md" :style="{ background: `color-mix(in srgb, var(--t-${g.node.span.type}) 16%, transparent)` }"><TypeIcon :type="g.node.span.type" /></span>
          <span class="line-clamp-2 min-w-0 flex-1 break-words font-mono text-[12px] leading-snug" :title="g.node.span.label">{{ shortClass(g.node.span.label) }}</span>
        </span>
        <span class="absolute inset-x-2.5 bottom-2 flex items-center gap-2">
          <span class="h-[3px] min-w-0 flex-1 overflow-hidden rounded-full bg-line"><span class="block h-full rounded-full" :style="{ width: `${share(g.node, focus?.node.span.duration_us ?? 1)}%`, background: `var(--t-${g.node.span.type})` }"></span></span>
          <span class="shrink-0 font-mono text-[10.5px] tabular-nums text-muted">{{ formatUs(g.node.span.duration_us) }}</span>
          <span v-if="isFolder(g.node)" class="shrink-0 font-mono text-[10px] text-faint">{{ g.node.descendants }}</span>
        </span>
      </div>

      <p v-if="children.length === 0" class="absolute left-1/2 top-10 -translate-x-1/2 text-muted">{{ list.filtering ? 'Nothing matches these filters.' : 'Nothing inside this step.' }}</p>
    </div>
  </div>
</template>
