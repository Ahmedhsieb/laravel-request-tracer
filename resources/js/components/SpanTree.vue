<script setup lang="ts">
import { ChevronRight, FolderTree } from 'lucide-vue-next'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { isFolder } from '../lib/folder'
import { formatUs, plural, shortClass, shortFile } from '../lib/format'
import type { Flow } from '../lib/flow'
import { subtitle } from '../lib/spans'
import { bar, ticks, type Range } from '../lib/timeline'
import type { TreeNode } from '../lib/tree'
import TypeIcon from '../ui/TypeIcon.vue'

const props = defineProps<{ flow: Flow; totalUs: number; pinned: number | null; entering: boolean; range: Range }>()
const emit = defineEmits<{ hover: [id: number | null]; select: [id: number] }>()

const ROW = 44
const OVERSCAN = 8

const { list, folder, folderId, direction } = props.flow

const scroller = ref<HTMLElement | null>(null)
const ruler = ref<HTMLElement | null>(null)
const scrollTop = ref(0)
const viewHeight = ref(640)
/** where the pointer is on the time axis, in percent of the lane (null while it is elsewhere) */
const hoverPct = ref<number | null>(null)
/** the step that was just revealed: it flashes once so the eye finds it */
const flashId = ref<number | null>(null)
let flashTimer: ReturnType<typeof setTimeout> | null = null

const entries = computed(() => list.value.entries)
const total = computed(() => entries.value.length * ROW)
const first = computed(() => Math.max(0, Math.floor(scrollTop.value / ROW) - OVERSCAN))
const last = computed(() => Math.min(entries.value.length, Math.ceil((scrollTop.value + viewHeight.value) / ROW) + OVERSCAN))
const windowed = computed(() => entries.value.slice(first.value, last.value).map((entry, i) => ({ entry, index: first.value + i })))
const axis = computed(() => ticks(props.range, 6))
const zoomed = computed(() => props.range.from > 0.5 || props.range.to < props.totalUs - 0.5)
const hoverUs = computed(() => (hoverPct.value === null ? null : props.range.from + (hoverPct.value / 100) * (props.range.to - props.range.from)))
const title = computed(() => (folder.value === null ? 'Steps' : shortClass(folder.value.span.label)))

function onScroll(): void {
  scrollTop.value = scroller.value?.scrollTop ?? 0
}

function onMove(event: PointerEvent): void {
  const lane = ruler.value?.getBoundingClientRect()
  if (lane === undefined || lane.width <= 0) return
  const pct = ((event.clientX - lane.left) / lane.width) * 100
  hoverPct.value = pct >= 0 && pct <= 100 ? pct : null
}

function chips(node: TreeNode): Array<{ text: string; tone: 'neutral' | 'warn' | 'bad' }> {
  const out: Array<{ text: string; tone: 'neutral' | 'warn' | 'bad' }> = []
  if (node.span.count > 1) out.push({ text: `×${node.span.count}`, tone: 'neutral' })
  if (node.span.flag === 'slow' || node.span.flag === 'repeated') out.push({ text: node.span.flag, tone: 'warn' })
  else if (node.span.flag === 'failed') out.push({ text: 'failed', tone: 'bad' })
  else if (node.span.flag === 'caught') out.push({ text: 'caught', tone: 'warn' })

  return out
}

function barOf(node: TreeNode): ReturnType<typeof bar> {
  return bar(node.span.start_us, node.span.duration_us, props.range)
}

/** The duration is written to the right of a bar, or to its left when the bar ends near the edge. */
function labelLeft(b: ReturnType<typeof bar>): boolean {
  return b.left + b.width > 82
}

function trailText(trail: TreeNode[]): string {
  return trail.map((n) => shortClass(n.span.label)).join(' › ')
}

function enter(node: TreeNode): void {
  if (isFolder(node)) props.flow.open(node.span.id)
}

/** Show a step: open the folder it is in, bring its row into view and flash it. */
async function scrollTo(id: number): Promise<void> {
  await nextTick()
  const index = entries.value.findIndex((e) => e.node.span.id === id)
  if (index < 0 || scroller.value === null) return
  scroller.value.scrollTo({ top: Math.max(0, index * ROW - viewHeight.value / 2 + ROW), behavior: 'smooth' })
  onScroll()
  flashId.value = id
  if (flashTimer !== null) clearTimeout(flashTimer)
  flashTimer = setTimeout(() => {
    flashId.value = null
  }, 1700)
}

async function focusIndex(index: number): Promise<void> {
  const target = Math.min(Math.max(index, 0), entries.value.length - 1)
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
    case 'ArrowRight': if (isFolder(node)) { event.preventDefault(); enter(node) } break
    case 'ArrowLeft': case 'Backspace': event.preventDefault(); props.flow.up(); break
    case 'Enter': case ' ': event.preventDefault(); emit('select', node.span.id); break
    default:
  }
}

// a new folder starts at its top, and its first row takes the keyboard focus only if the focus was already in the list
watch(folderId, async () => {
  const hadFocus = scroller.value?.contains(document.activeElement) ?? false
  if (scroller.value !== null) scroller.value.scrollTop = 0
  scrollTop.value = 0
  await nextTick()
  if (hadFocus) scroller.value?.querySelector<HTMLElement>('[data-index="0"]')?.focus()
})

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

onBeforeUnmount(() => {
  observer?.disconnect()
  if (flashTimer !== null) clearTimeout(flashTimer)
})

defineExpose({ scrollTo })
</script>

<template>
  <!-- the steps of the open folder on a timeline -->
  <div
    ref="scroller"
    class="relative min-h-0 flex-1 overflow-auto [--label-w:clamp(250px,52%,560px)]"
    role="tree"
    aria-label="Steps of the open folder"
    @scroll.passive="onScroll"
    @pointermove="onMove"
    @pointerleave="hoverPct = null; emit('hover', null)"
  >
    <div class="sticky top-0 z-20 grid h-[30px] grid-cols-[var(--label-w)_minmax(0,1fr)] border-b border-line bg-surface/95 text-[11px] text-faint backdrop-blur">
      <div class="flex min-w-0 items-center gap-2 px-3">
        <span class="truncate font-medium text-muted">{{ title }}</span>
        <span v-if="zoomed" class="shrink-0 rounded bg-[var(--accent)]/15 px-1.5 text-[10.5px] text-accent-ink">{{ formatUs(range.from) }} → {{ formatUs(range.to) }}</span>
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

    <div :key="`${folderId ?? 'top'}`" class="relative" :class="direction === 'in' ? 'animate-folder-in' : 'animate-folder-out'" :style="{ height: `${Math.max(total, 1)}px` }">
      <!-- the grid of the time axis, and the line that follows the pointer -->
      <div class="pointer-events-none absolute inset-y-0 left-[var(--label-w)] right-0" aria-hidden="true">
        <span v-for="t in axis" :key="t.us" class="absolute inset-y-0 border-l border-line/50" :style="{ left: `${t.pct}%` }"></span>
        <span v-if="hoverPct !== null" class="absolute inset-y-0 w-px bg-[var(--accent)]/70 shadow-[0_0_8px_var(--accent)]" :style="{ left: `${hoverPct}%` }"></span>
      </div>

      <div v-if="entries.length === 0" class="absolute inset-x-0 top-8 px-6 text-center text-muted">
        <FolderTree class="mx-auto mb-2 size-6 text-faint" />
        <template v-if="list.filtering">Nothing {{ flow.include.value ? 'inside' : 'here' }} matches these filters.</template>
        <template v-else>Nothing inside this step.</template>
      </div>

      <div
        v-for="{ entry, index } in windowed"
        :key="entry.node.span.id"
        class="group/row absolute inset-x-0 grid cursor-pointer grid-cols-[var(--label-w)_minmax(0,1fr)] items-center border-b border-line/40 outline-none transition-colors hover:bg-hover/80 focus-visible:bg-hover/80"
        :class="[pinned === entry.node.span.id ? 'bg-hover' : '', flashId === entry.node.span.id ? 'animate-flash' : '']"
        :style="{ top: `${index * ROW}px`, height: `${ROW}px` }"
        role="treeitem"
        tabindex="0"
        :data-index="index"
        :aria-selected="pinned === entry.node.span.id"
        :aria-label="isFolder(entry.node) ? `${shortClass(entry.node.span.label)}, a folder with ${plural(entry.node.descendants, 'step')} inside` : undefined"
        @click="emit('select', entry.node.span.id)"
        @dblclick="enter(entry.node)"
        @pointerenter="emit('hover', entry.node.span.id)"
        @focus="emit('hover', entry.node.span.id)"
        @keydown="onKey($event, index, entry.node)"
      >
        <span v-if="pinned === entry.node.span.id" class="absolute inset-y-1 left-0 w-[3px] rounded-r-full grad-bg"></span>

        <!-- the step: its kind, its full name and where it is -->
        <div class="flex min-w-0 items-center gap-2 pl-3 pr-2">
          <span class="grid size-7 shrink-0 place-items-center rounded-lg" :style="{ background: `color-mix(in srgb, var(--t-${entry.node.span.type}) 14%, transparent)` }"><TypeIcon :type="entry.node.span.type" /></span>
          <span class="grid min-w-0 flex-1 leading-tight">
            <span class="flex min-w-0 items-center gap-1">
              <span class="truncate font-mono text-[12.5px]" :title="entry.node.span.label">{{ shortClass(entry.node.span.label) }}</span>
              <span v-for="c in chips(entry.node)" :key="c.text" class="shrink-0 rounded px-1 font-mono text-[10px] font-semibold" :class="c.tone === 'bad' ? 'bg-bad/15 text-bad' : c.tone === 'warn' ? 'bg-warn/15 text-warn' : 'bg-line text-muted'">{{ c.text }}</span>
            </span>
            <!-- a match found deeper down says where: the folders between this one and it -->
            <span v-if="entry.trail.length > 0" class="truncate text-[11px] text-faint [direction:rtl] [text-align:left]" :title="trailText(entry.trail)"><bdi>{{ trailText(entry.trail) }} ›</bdi></span>
            <span v-else-if="subtitle(entry.node.span) || entry.node.span.file" class="truncate text-[11px] text-muted">
              {{ subtitle(entry.node.span) }}<template v-if="subtitle(entry.node.span) && entry.node.span.file"> · </template><span class="text-faint">{{ shortFile(entry.node.span.file, entry.node.span.line) }}</span>
            </span>
          </span>
          <!-- a folder: how much is inside, and the way in -->
          <button
            v-if="isFolder(entry.node)"
            class="flex h-7 shrink-0 items-center gap-1 rounded-lg border border-line bg-raised px-2 font-mono text-[11px] text-muted outline-none transition-all hover:border-[var(--accent)] hover:bg-[var(--accent)]/10 hover:text-fg focus-visible:border-[var(--accent)] group-hover/row:border-line-strong"
            type="button"
            :aria-label="`Open ${shortClass(entry.node.span.label)}: ${plural(entry.node.descendants, 'step')} inside`"
            @click.stop="enter(entry.node)"
          >
            <FolderTree class="size-3" />{{ entry.node.descendants }}<ChevronRight class="size-3 transition-transform group-hover/row:translate-x-0.5" />
          </button>
        </div>

        <!-- when it ran, and for how long -->
        <div class="relative h-full overflow-hidden">
          <template v-if="barOf(entry.node).visible">
            <span
              class="absolute top-1/2 h-[14px] -translate-y-1/2 origin-left rounded-[5px]"
              :class="entering && index < 30 ? 'animate-grow-x' : ''"
              :style="{
                left: `${barOf(entry.node).left}%`,
                width: `${barOf(entry.node).width}%`,
                background: `linear-gradient(90deg, color-mix(in srgb, var(--t-${entry.node.span.type}) 70%, transparent), var(--t-${entry.node.span.type}))`,
                boxShadow: entry.node.span.flag === 'slow' ? `0 0 12px -1px var(--t-${entry.node.span.type})` : undefined,
                animationDelay: entering ? `${Math.min(index, 30) * 14}ms` : undefined,
                borderTopLeftRadius: barOf(entry.node).cutLeft ? '0' : undefined,
                borderBottomLeftRadius: barOf(entry.node).cutLeft ? '0' : undefined,
                borderTopRightRadius: barOf(entry.node).cutRight ? '0' : undefined,
                borderBottomRightRadius: barOf(entry.node).cutRight ? '0' : undefined,
              }"
            ></span>
            <span
              class="absolute top-1/2 -translate-y-1/2 whitespace-nowrap font-mono text-[11px] tabular-nums text-muted"
              :style="labelLeft(barOf(entry.node))
                ? { right: `calc(${100 - barOf(entry.node).left}% + 6px)` }
                : { left: `calc(${barOf(entry.node).left + barOf(entry.node).width}% + 6px)` }"
            >{{ formatUs(entry.node.span.duration_us) }}</span>
          </template>
          <span v-else class="absolute right-2 top-1/2 -translate-y-1/2 font-mono text-[11px] text-faint">{{ formatUs(entry.node.span.duration_us) }}</span>
        </div>
      </div>
    </div>
  </div>
</template>
