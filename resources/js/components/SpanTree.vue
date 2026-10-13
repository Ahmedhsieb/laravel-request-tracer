<script setup lang="ts">
import { ChevronRight, ChevronsDownUp, ChevronsUpDown, ListFilter, Search, X } from 'lucide-vue-next'
import { PopoverContent, PopoverPortal, PopoverRoot, PopoverTrigger } from 'reka-ui'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { SpanType } from '../api/types'
import { filterSteps, isFiltering, typeCounts, type ResultFilter, type SpeedFilter } from '../lib/filters'
import { formatUs, plural, shortClass, shortFile } from '../lib/format'
import { TYPE_LABEL, TYPE_ORDER, subtitle } from '../lib/spans'
import { bar, ticks, type Range } from '../lib/timeline'
import { ancestorIds, defaultExpanded, visibleRows, type Row, type TreeNode } from '../lib/tree'
import Btn from '../ui/Btn.vue'
import Chip from '../ui/Chip.vue'
import Seg from '../ui/Seg.vue'
import TypeIcon from '../ui/TypeIcon.vue'

const props = defineProps<{ roots: TreeNode[]; totalUs: number; pinned: number | null; entering: boolean; slowMs: number; range: Range; types: ReadonlySet<SpanType> }>()
const emit = defineEmits<{ hover: [id: number | null]; select: [id: number]; 'update:types': [types: Set<SpanType>] }>()

const ROW = 40
const OVERSCAN = 8

const expanded = ref<Set<number>>(defaultExpanded(props.roots))
const query = ref('')
const speed = ref<SpeedFilter>('all')
const result = ref<ResultFilter>('all')
const scroller = ref<HTMLElement | null>(null)
const ruler = ref<HTMLElement | null>(null)
const scrollTop = ref(0)
const viewHeight = ref(640)
/** where the pointer is on the time axis, in percent of the lane (null while it is elsewhere) */
const hoverPct = ref<number | null>(null)

watch(() => props.roots, (roots) => {
  expanded.value = defaultExpanded(roots)
})

const filter = computed(() => ({ query: query.value, types: props.types, speed: speed.value, result: result.value }))
const filtering = computed(() => isFiltering(filter.value))
const filtered = computed(() => filterSteps(props.roots, filter.value, props.slowMs))
const forced = computed(() => filtered.value?.keep ?? null)
const typeList = computed(() => typeCounts(props.roots, TYPE_ORDER))
const totalSteps = computed(() => typeList.value.reduce((n, t) => n + t.count, 0))
const rows = computed<Row[]>(() => visibleRows(props.roots, expanded.value, forced.value))
const total = computed(() => rows.value.length * ROW)
const first = computed(() => Math.max(0, Math.floor(scrollTop.value / ROW) - OVERSCAN))
const last = computed(() => Math.min(rows.value.length, Math.ceil((scrollTop.value + viewHeight.value) / ROW) + OVERSCAN))
const windowed = computed(() => rows.value.slice(first.value, last.value).map((row, i) => ({ row, index: first.value + i })))
const axis = computed(() => ticks(props.range, 6))
const zoomed = computed(() => props.range.from > 0 || props.range.to < props.totalUs)
const hoverUs = computed(() => (hoverPct.value === null ? null : props.range.from + (hoverPct.value / 100) * (props.range.to - props.range.from)))
const activeFilters = computed(() => (speed.value !== 'all' ? 1 : 0) + (result.value !== 'all' ? 1 : 0))

function onScroll(): void {
  scrollTop.value = scroller.value?.scrollTop ?? 0
}

function onMove(event: PointerEvent): void {
  const lane = ruler.value?.getBoundingClientRect()
  if (lane === undefined || lane.width <= 0) return
  const pct = ((event.clientX - lane.left) / lane.width) * 100
  hoverPct.value = pct >= 0 && pct <= 100 ? pct : null
}

function toggle(id: number): void {
  const next = new Set(expanded.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  expanded.value = next
}

function toggleType(type: SpanType): void {
  const next = new Set(props.types)
  if (next.has(type)) next.delete(type)
  else next.add(type)
  emit('update:types', next)
}

function clearFilters(): void {
  query.value = ''
  speed.value = 'all'
  result.value = 'all'
  emit('update:types', new Set())
}

function expandAll(): void {
  const all = new Set<number>()
  const visit = (node: TreeNode): void => {
    if (node.children.length > 0) all.add(node.span.id)
    node.children.forEach(visit)
  }
  props.roots.forEach(visit)
  expanded.value = all
}

function collapseAll(): void {
  expanded.value = new Set(props.roots.filter((r) => r.children.length > 0).map((r) => r.span.id))
}

function chips(node: TreeNode): Array<{ text: string; tone: 'neutral' | 'warn' | 'bad' }> {
  const out: Array<{ text: string; tone: 'neutral' | 'warn' | 'bad' }> = []
  if (node.span.count > 1) out.push({ text: `×${node.span.count}`, tone: 'neutral' })
  if (node.span.flag === 'slow' || node.span.flag === 'repeated') out.push({ text: node.span.flag, tone: 'warn' })
  else if (node.span.flag === 'failed') out.push({ text: 'failed', tone: 'bad' })
  else if (node.span.flag === 'caught') out.push({ text: 'caught', tone: 'warn' })

  return out
}

function isOpen(node: TreeNode): boolean {
  return forced.value !== null ? node.children.some((c) => forced.value?.has(c.span.id)) : expanded.value.has(node.span.id)
}

function barOf(node: TreeNode): ReturnType<typeof bar> {
  return bar(node.span.start_us, node.span.duration_us, props.range)
}

/** The duration is written to the right of a bar, or to its left when the bar ends near the edge. */
function labelLeft(b: ReturnType<typeof bar>): boolean {
  return b.left + b.width > 82
}

/** Open the branches down to a step and scroll it into view (used by "show in flow" links and the command palette). */
async function reveal(id: number, openItself = false): Promise<void> {
  clearFilters()
  const next = new Set(expanded.value)
  ancestorIds(props.roots, id).forEach((a) => next.add(a))
  if (openItself) next.add(id)
  expanded.value = next
  await nextTick()
  const index = rows.value.findIndex((r) => r.node.span.id === id)
  if (index >= 0 && scroller.value !== null) {
    scroller.value.scrollTo({ top: Math.max(0, index * ROW - viewHeight.value / 2 + ROW), behavior: 'smooth' })
    onScroll()
  }
}

async function focusIndex(index: number): Promise<void> {
  const target = Math.min(Math.max(index, 0), rows.value.length - 1)
  const el = scroller.value
  if (el === null || target < 0) return
  if (target * ROW < el.scrollTop) el.scrollTop = target * ROW
  else if ((target + 1) * ROW > el.scrollTop + viewHeight.value - 30) el.scrollTop = (target + 1) * ROW - viewHeight.value + 30
  onScroll()
  await nextTick()
  el.querySelector<HTMLElement>(`[data-index="${target}"]`)?.focus()
}

function onKey(event: KeyboardEvent, index: number, node: TreeNode): void {
  switch (event.key) {
    case 'ArrowDown': event.preventDefault(); void focusIndex(index + 1); break
    case 'ArrowUp': event.preventDefault(); void focusIndex(index - 1); break
    case 'ArrowRight':
      if (node.children.length > 0 && !isOpen(node)) { event.preventDefault(); toggle(node.span.id) }
      break
    case 'ArrowLeft':
      if (node.children.length > 0 && isOpen(node)) { event.preventDefault(); toggle(node.span.id) }
      break
    case 'Enter': case ' ': event.preventDefault(); emit('select', node.span.id); break
    default:
  }
}

let observer: ResizeObserver | null = null

onMounted(() => {
  viewHeight.value = scroller.value?.clientHeight ?? 640
  // the list fills the space the page gives it, so its height changes with the window and with the filter bar
  if (scroller.value !== null && typeof ResizeObserver !== 'undefined') {
    observer = new ResizeObserver(() => {
      viewHeight.value = scroller.value?.clientHeight ?? viewHeight.value
    })
    observer.observe(scroller.value)
  }
})

onBeforeUnmount(() => observer?.disconnect())

defineExpose({ reveal })
</script>

<template>
  <div class="flex h-full min-h-0 flex-col overflow-hidden rounded-xl border border-line bg-surface/80">
    <!-- search, filters -->
    <div class="flex items-center gap-1.5 border-b border-line px-2.5 py-2">
      <div class="relative min-w-0 flex-1">
        <Search class="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-faint" />
        <label class="sr-only" for="step-search">Search steps</label>
        <input id="step-search" v-model="query" class="input h-8 pl-8 text-[12.5px]" type="search" placeholder="Search steps, SQL, files" autocomplete="off">
      </div>

      <PopoverRoot>
        <PopoverTrigger as-child>
          <Btn variant="soft" size="sm" aria-label="Filter by speed and result">
            <ListFilter class="size-3.5" /><span class="hidden xl:inline">Filters</span>
            <span v-if="activeFilters > 0" class="grid size-4 place-items-center rounded-full bg-[var(--accent)] text-[10px] font-bold text-[#04141c]">{{ activeFilters }}</span>
          </Btn>
        </PopoverTrigger>
        <PopoverPortal>
          <PopoverContent align="end" :side-offset="8" class="z-[70] w-[270px] space-y-3 rounded-xl border border-line-strong bg-surface p-3.5 shadow-2xl data-[state=open]:animate-pop-in">
            <div class="space-y-1.5">
              <p class="text-[11px] font-semibold uppercase tracking-wide text-faint">Speed</p>
              <Seg v-model="speed" label="Speed" size="sm" :options="[{ value: 'all', label: 'Any' }, { value: 'slow', label: 'Slow' }, { value: 'fast', label: 'Fast' }]" />
              <p class="text-[11px] text-faint">Slow: flagged slow, or at least {{ slowMs }} ms.</p>
            </div>
            <div class="space-y-1.5">
              <p class="text-[11px] font-semibold uppercase tracking-wide text-faint">Result</p>
              <Seg v-model="result" label="Result" size="sm" :options="[{ value: 'all', label: 'Any' }, { value: 'failed', label: 'Failed' }, { value: 'ok', label: 'OK' }]" />
              <p class="text-[11px] text-faint">Failed: threw, was caught after throwing, or an HTTP call answered 4xx/5xx.</p>
            </div>
          </PopoverContent>
        </PopoverPortal>
      </PopoverRoot>

      <Btn variant="ghost" size="icon" aria-label="Expand all" title="Expand all" @click="expandAll"><ChevronsUpDown class="size-4" /></Btn>
      <Btn variant="ghost" size="icon" aria-label="Collapse" title="Collapse" @click="collapseAll"><ChevronsDownUp class="size-4" /></Btn>
    </div>

    <!-- the kinds of step: a legend that is also a filter -->
    <div class="flex items-center gap-1.5 overflow-x-auto border-b border-line px-2.5 py-2" role="group" aria-label="Show only these kinds of step">
      <Chip v-for="t in typeList" :key="t.type" :active="types.has(t.type)" :color="`var(--t-${t.type})`" class="h-6 px-2.5 text-[11.5px]" @click="toggleType(t.type)">
        <span class="size-1.5 rounded-full" :style="{ background: `var(--t-${t.type})` }"></span>{{ TYPE_LABEL[t.type] }}<span class="font-mono text-[10.5px] text-faint">{{ t.count }}</span>
      </Chip>
      <template v-if="filtering">
        <span class="ml-1 shrink-0 text-[11.5px] text-muted" role="status">{{ filtered?.matched.size ?? 0 }} of {{ plural(totalSteps, 'step') }}</span>
        <button class="flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-[11.5px] text-muted hover:bg-hover hover:text-fg" type="button" @click="clearFilters"><X class="size-3" />Clear</button>
      </template>
    </div>

    <!-- the steps on a timeline -->
    <div
      ref="scroller"
      class="relative min-h-0 flex-1 overflow-auto [--label-w:clamp(220px,40%,440px)]"
      role="tree"
      aria-label="Steps of the request"
      @scroll.passive="onScroll"
      @pointermove="onMove"
      @pointerleave="hoverPct = null; emit('hover', null)"
    >
      <div class="sticky top-0 z-20 grid h-[30px] grid-cols-[var(--label-w)_minmax(0,1fr)] border-b border-line bg-surface/95 text-[11px] text-faint backdrop-blur">
        <div class="flex items-center gap-2 px-3">
          <span class="font-medium text-muted">Steps</span>
          <span v-if="zoomed" class="rounded bg-[var(--accent)]/15 px-1.5 text-[10.5px] text-accent-ink">zoomed: {{ formatUs(range.from) }} → {{ formatUs(range.to) }}</span>
        </div>
        <div ref="ruler" class="relative overflow-hidden">
          <span v-for="t in axis" :key="t.us" class="absolute top-0 flex h-full items-center border-l border-line pl-1 font-mono tabular-nums" :style="{ left: `${t.pct}%` }">{{ t.label }}</span>
          <span
            v-if="hoverUs !== null"
            class="absolute top-1/2 z-10 -translate-y-1/2 -translate-x-1/2 rounded-md bg-[var(--accent)] px-1.5 py-0.5 font-mono text-[10.5px] font-semibold text-[#04141c] shadow"
            :style="{ left: `clamp(24px, ${hoverPct}%, calc(100% - 24px))` }"
          >{{ formatUs(hoverUs) }}</span>
        </div>
      </div>

      <div :style="{ height: `${total}px`, position: 'relative' }">
        <!-- the grid of the time axis, and the line that follows the pointer -->
        <div class="pointer-events-none absolute inset-y-0 left-[var(--label-w)] right-0" aria-hidden="true">
          <span v-for="t in axis" :key="t.us" class="absolute inset-y-0 border-l border-line/50" :style="{ left: `${t.pct}%` }"></span>
          <span v-if="hoverPct !== null" class="absolute inset-y-0 w-px bg-[var(--accent)]/70 shadow-[0_0_8px_var(--accent)]" :style="{ left: `${hoverPct}%` }"></span>
        </div>

        <div v-if="rows.length === 0" class="absolute inset-x-0 top-6 text-center text-muted">No step matches these filters.</div>

        <div
          v-for="{ row, index } in windowed"
          :key="row.node.span.id"
          class="group/row absolute inset-x-0 grid cursor-pointer grid-cols-[var(--label-w)_minmax(0,1fr)] items-center border-b border-line/40 outline-none transition-colors hover:bg-hover/80 focus-visible:bg-hover/80"
          :class="[pinned === row.node.span.id ? 'bg-hover' : '', filtered !== null && !filtered.matched.has(row.node.span.id) ? 'opacity-45 hover:opacity-100' : '']"
          :style="{ top: `${index * ROW}px`, height: `${ROW}px` }"
          role="treeitem"
          tabindex="0"
          :data-index="index"
          :aria-level="row.node.depth + 1"
          :aria-expanded="row.node.children.length > 0 ? isOpen(row.node) : undefined"
          :aria-selected="pinned === row.node.span.id"
          @click="emit('select', row.node.span.id)"
          @pointerenter="emit('hover', row.node.span.id)"
          @focus="emit('hover', row.node.span.id)"
          @keydown="onKey($event, index, row.node)"
        >
          <span v-if="pinned === row.node.span.id" class="absolute inset-y-1 left-0 w-[3px] rounded-r-full grad-bg"></span>

          <!-- the step: indentation, a fold button, its kind and what it is -->
          <div class="flex min-w-0 items-center pr-2">
            <span v-if="row.node.depth > 0" class="flex h-full shrink-0" aria-hidden="true">
              <i v-for="(g, k) in row.guides.slice(1)" :key="k" class="relative block w-[14px]" :class="g ? 'before:absolute before:inset-y-0 before:left-[6px] before:border-l before:border-line' : ''"></i>
              <i class="relative block w-[14px] before:absolute before:left-[6px] before:top-0 before:h-1/2 before:w-[7px] before:rounded-bl-[5px] before:border-b before:border-l before:border-line" :class="!row.node.last ? 'after:absolute after:bottom-0 after:left-[6px] after:top-1/2 after:border-l after:border-line' : ''"></i>
            </span>
            <button
              v-if="row.node.children.length > 0"
              class="grid size-5 shrink-0 place-items-center rounded text-faint transition-colors hover:bg-line hover:text-fg"
              type="button"
              :aria-expanded="isOpen(row.node)"
              :aria-label="`${isOpen(row.node) ? 'Collapse' : 'Expand'} ${plural(row.node.descendants, 'step below', 'steps below')}`"
              @click.stop="toggle(row.node.span.id)"
            >
              <ChevronRight class="size-3.5 transition-transform duration-200" :class="isOpen(row.node) ? 'rotate-90' : ''" />
            </button>
            <span v-else class="size-5 shrink-0" aria-hidden="true"></span>
            <span class="mr-1.5 grid size-6 shrink-0 place-items-center rounded-md" :style="{ background: `color-mix(in srgb, var(--t-${row.node.span.type}) 14%, transparent)` }"><TypeIcon :type="row.node.span.type" /></span>
            <span class="grid min-w-0 flex-1 leading-tight">
              <span class="truncate font-mono text-[12.5px]" :title="row.node.span.label">{{ shortClass(row.node.span.label) }}</span>
              <span v-if="subtitle(row.node.span) || row.node.span.file" class="truncate text-[11px] text-muted">
                {{ subtitle(row.node.span) }}<template v-if="subtitle(row.node.span) && row.node.span.file"> · </template><span class="text-faint">{{ shortFile(row.node.span.file, row.node.span.line) }}</span>
              </span>
            </span>
            <span v-for="c in chips(row.node)" :key="c.text" class="ml-1 shrink-0 rounded px-1 font-mono text-[10px] font-semibold" :class="c.tone === 'bad' ? 'bg-bad/15 text-bad' : c.tone === 'warn' ? 'bg-warn/15 text-warn' : 'bg-line text-muted'">{{ c.text }}</span>
          </div>

          <!-- when it ran, and for how long -->
          <div class="relative h-full overflow-hidden">
            <template v-if="barOf(row.node).visible">
              <span
                class="absolute top-1/2 h-[14px] -translate-y-1/2 origin-left rounded-[5px]"
                :class="entering && index < 30 ? 'animate-grow-x' : ''"
                :style="{
                  left: `${barOf(row.node).left}%`,
                  width: `${barOf(row.node).width}%`,
                  background: `linear-gradient(90deg, color-mix(in srgb, var(--t-${row.node.span.type}) 70%, transparent), var(--t-${row.node.span.type}))`,
                  boxShadow: row.node.span.flag === 'slow' ? `0 0 12px -1px var(--t-${row.node.span.type})` : undefined,
                  animationDelay: entering ? `${Math.min(index, 30) * 14}ms` : undefined,
                  borderTopLeftRadius: barOf(row.node).cutLeft ? '0' : undefined,
                  borderBottomLeftRadius: barOf(row.node).cutLeft ? '0' : undefined,
                  borderTopRightRadius: barOf(row.node).cutRight ? '0' : undefined,
                  borderBottomRightRadius: barOf(row.node).cutRight ? '0' : undefined,
                }"
              ></span>
              <span
                class="absolute top-1/2 -translate-y-1/2 whitespace-nowrap font-mono text-[11px] tabular-nums text-muted"
                :style="labelLeft(barOf(row.node))
                  ? { right: `calc(${100 - barOf(row.node).left}% + 6px)` }
                  : { left: `calc(${barOf(row.node).left + barOf(row.node).width}% + 6px)` }"
              >{{ formatUs(row.node.span.duration_us) }}</span>
            </template>
            <span v-else class="absolute right-2 top-1/2 -translate-y-1/2 font-mono text-[11px] text-faint">{{ formatUs(row.node.span.duration_us) }}</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
