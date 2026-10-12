<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { SpanType } from '../api/types'
import { emptyFilter, filterSteps, isFiltering, typeCounts, type ResultFilter, type SpeedFilter } from '../lib/filters'
import { formatUs, plural, shortClass, shortFile } from '../lib/format'
import { TYPE_LABEL, TYPE_ORDER, shareOf, subtitle, typeVar } from '../lib/spans'
import { ancestorIds, defaultExpanded, visibleRows, type Row, type TreeNode } from '../lib/tree'

const props = defineProps<{ roots: TreeNode[]; totalUs: number; pinned: number | null; entering: boolean; slowMs: number }>()
const emit = defineEmits<{ hover: [id: number | null]; select: [id: number] }>()

const ROW = 44
const OVERSCAN = 6

const expanded = ref<Set<number>>(defaultExpanded(props.roots))
const query = ref('')
const types = ref<Set<SpanType>>(new Set())
const speed = ref<SpeedFilter>('all')
const result = ref<ResultFilter>('all')
const scroller = ref<HTMLElement | null>(null)
const scrollTop = ref(0)
const viewHeight = ref(640)

watch(() => props.roots, (roots) => {
  expanded.value = defaultExpanded(roots)
})

const filter = computed(() => ({ query: query.value, types: types.value, speed: speed.value, result: result.value }))
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

function onScroll(): void {
  scrollTop.value = scroller.value?.scrollTop ?? 0
}

function toggleType(type: SpanType): void {
  const next = new Set(types.value)
  if (next.has(type)) next.delete(type)
  else next.add(type)
  types.value = next
}

function clearFilters(): void {
  const empty = emptyFilter()
  query.value = empty.query
  types.value = new Set()
  speed.value = empty.speed
  result.value = empty.result
}

function toggle(id: number): void {
  const next = new Set(expanded.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  expanded.value = next
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

function chips(node: TreeNode): Array<{ text: string; cls: string }> {
  const out: Array<{ text: string; cls: string }> = []
  if (node.span.count > 1) out.push({ text: `×${node.span.count}`, cls: 'count' })
  if (node.span.flag !== null) out.push({ text: node.span.flag, cls: node.span.flag })

  return out
}

function isOpen(node: TreeNode): boolean {
  return forced.value !== null ? node.children.some((c) => forced.value?.has(c.span.id)) : expanded.value.has(node.span.id)
}

/** Open the branches down to a step and scroll it into view (used by "show in flow" links). */
async function reveal(id: number, openItself = false): Promise<void> {
  clearFilters()
  const next = new Set(expanded.value)
  ancestorIds(props.roots, id).forEach((a) => next.add(a))
  if (openItself) next.add(id)
  expanded.value = next
  await nextTick()
  const index = rows.value.findIndex((r) => r.node.span.id === id)
  if (index >= 0 && scroller.value !== null) {
    scroller.value.scrollTop = Math.max(0, index * ROW - viewHeight.value / 2 + ROW)
    onScroll()
  }
}

async function focusIndex(index: number): Promise<void> {
  const target = Math.min(Math.max(index, 0), rows.value.length - 1)
  const el = scroller.value
  if (el === null || target < 0) return
  if (target * ROW < el.scrollTop) el.scrollTop = target * ROW
  else if ((target + 1) * ROW > el.scrollTop + viewHeight.value) el.scrollTop = (target + 1) * ROW - viewHeight.value
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
  <div class="tree-box">
    <div class="tree-tools">
      <label class="sr-only" for="step-search">Search steps</label>
      <input id="step-search" v-model="query" class="search" type="search" placeholder="Search steps, SQL, files" autocomplete="off">
      <button class="btn small" type="button" @click="expandAll">Expand all</button>
      <button class="btn small" type="button" @click="collapseAll">Collapse</button>
    </div>

    <div class="step-filters" aria-label="Filter the steps">
      <div class="chips" role="group" aria-label="Show only these kinds of step">
        <button v-for="t in typeList" :key="t.type" class="chip type-chip" type="button" :style="{ '--c': typeVar(t.type) }" :aria-pressed="types.has(t.type)" @click="toggleType(t.type)">
          <i aria-hidden="true"></i>{{ TYPE_LABEL[t.type] }} <span class="n">{{ t.count }}</span>
        </button>
      </div>
      <div class="filter-row">
        <div class="segmented compact" role="group" aria-label="Speed">
          <button type="button" :aria-pressed="speed === 'all'" @click="speed = 'all'">Any speed</button>
          <button type="button" :aria-pressed="speed === 'slow'" :title="`Flagged slow, or at least ${slowMs} ms`" @click="speed = 'slow'">Slow</button>
          <button type="button" :aria-pressed="speed === 'fast'" @click="speed = 'fast'">Fast</button>
        </div>
        <div class="segmented compact" role="group" aria-label="Result">
          <button type="button" :aria-pressed="result === 'all'" @click="result = 'all'">Any result</button>
          <button type="button" :aria-pressed="result === 'failed'" title="Threw, was caught after throwing, or an HTTP call answered 4xx/5xx" @click="result = 'failed'">Failed</button>
          <button type="button" :aria-pressed="result === 'ok'" @click="result = 'ok'">OK</button>
        </div>
        <template v-if="filtering">
          <span class="muted" role="status">{{ filtered?.matched.size ?? 0 }} of {{ plural(totalSteps, 'step') }}</span>
          <button class="btn small ghost" type="button" @click="clearFilters">Clear filters</button>
        </template>
      </div>
    </div>

    <div ref="scroller" class="scroller" role="tree" aria-label="Steps of the request" @scroll.passive="onScroll" @mouseleave="emit('hover', null)">
      <div :style="{ height: `${total}px`, position: 'relative' }">
        <div v-if="rows.length === 0" class="empty">No step matches these filters.</div>
        <div
          v-for="{ row, index } in windowed"
          :key="row.node.span.id"
          class="span-row"
          :class="{ pinned: pinned === row.node.span.id, enter: entering && index < 24, context: filtered !== null && !filtered.matched.has(row.node.span.id) }"
          :style="{ top: `${index * ROW}px`, '--c': typeVar(row.node.span.type), '--i': index }"
          role="treeitem"
          tabindex="0"
          :data-index="index"
          :aria-level="row.node.depth + 1"
          :aria-expanded="row.node.children.length > 0 ? isOpen(row.node) : undefined"
          :aria-selected="pinned === row.node.span.id"
          @click="emit('select', row.node.span.id)"
          @mouseenter="emit('hover', row.node.span.id)"
          @focus="emit('hover', row.node.span.id)"
          @keydown="onKey($event, index, row.node)"
        >
          <span v-if="row.node.depth > 0" class="indent" aria-hidden="true">
            <i v-for="(g, k) in row.guides.slice(1)" :key="k" :class="{ line: g }"></i>
            <i class="elbow" :class="{ cont: !row.node.last }"></i>
          </span>

          <button
            v-if="row.node.children.length > 0"
            class="toggle"
            type="button"
            :aria-expanded="isOpen(row.node)"
            :aria-label="`${isOpen(row.node) ? 'Collapse' : 'Expand'} ${plural(row.node.descendants, 'step below', 'steps below')}`"
            @click.stop="toggle(row.node.span.id)"
          >
            <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true"><path d="M5 3l4 4-4 4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" /></svg>
          </button>
          <span v-else class="toggle" aria-hidden="true"></span>

          <span class="badge" :style="{ '--badge': 'var(--c)' }">{{ TYPE_LABEL[row.node.span.type] }}</span>

          <span class="body">
            <span class="label" :title="row.node.span.label">{{ shortClass(row.node.span.label) }}</span>
            <span v-if="subtitle(row.node.span) || row.node.span.file" class="sub">
              {{ subtitle(row.node.span) }}<template v-if="subtitle(row.node.span) && row.node.span.file"> · </template><span class="faint">{{ shortFile(row.node.span.file, row.node.span.line) }}</span>
            </span>
          </span>

          <span v-for="c in chips(row.node)" :key="c.text" class="flag" :class="c.cls">{{ c.text }}</span>
          <span class="bar" aria-hidden="true"><i :style="{ width: `${Math.max(1.5, shareOf(row.node.span, totalUs))}%` }"></i></span>
          <span class="ms">{{ formatUs(row.node.span.duration_us) }}</span>
        </div>
      </div>
    </div>
  </div>
</template>
