<script setup lang="ts">
import { ChevronRight } from 'lucide-vue-next'
import { computed, inject } from 'vue'
import { CODE_CONTEXT, type Edge } from '../lib/codeContext'
import Pill from '../ui/Pill.vue'
import ChildCode from './ChildCode.vue'

const props = defineProps<{ index: number; depth: number }>()

const ctx = inject(CODE_CONTEXT)
if (ctx === undefined) throw new Error('A code block needs a code view around it.')

const block = computed(() => ctx.blocks.value[props.index])
const isOpen = computed(() => ctx.open.value.has(props.index))
const isMain = computed(() => props.index === ctx.main.value)
const calledFrom = computed(() => {
  const from = ctx.parent.value.get(props.index)
  const where = from === null || from === undefined ? undefined : ctx.blocks.value[from]

  return where === undefined ? null : where.name
})
const edgeLines = computed(() => ctx.edges.value.get(props.index) ?? new Map<number, Edge[]>())
const ran = computed(() => block.value?.lines.filter((l) => l.state !== 'notrun' && l.state !== 'plain').length ?? 0)

const DOT: Record<string, string> = {
  ran: 'var(--ok)',
  false: 'var(--t-controller)',
  threw: 'var(--bad)',
  caught: 'var(--warn)',
  loop: 'var(--info)',
  notrun: 'var(--faint)',
}

function key(l: number): string {
  return `${ctx?.uid}:${props.index}:${l}`
}

function hover(l: number, event: Event): void {
  const line = block.value?.lines[l]
  if (line === undefined || line.state === 'plain' || ctx === undefined) return
  ctx.popover.show(key(l), line, event.currentTarget as HTMLElement, false)
}

function click(l: number, event: Event): void {
  const line = block.value?.lines[l]
  if (line === undefined || ctx === undefined) return
  if (line.state === 'plain') {
    ctx.popover.hide()
    return
  }
  if (ctx.popover.activeKey.value === key(l) && ctx.popover.pinned.value) ctx.popover.hide()
  else ctx.popover.show(key(l), line, event.currentTarget as HTMLElement, true)
}

function leave(): void {
  ctx?.popover.release()
}

function edgeId(edge: Edge): string {
  return edge.kind === 'span' ? `s${edge.id}` : `b${edge.target}`
}
</script>

<template>
  <section
    v-if="block"
    class="overflow-hidden rounded-xl border bg-bg/45"
    :class="[isMain ? 'border-[var(--accent)]/45 shadow-[0_0_0_1px_color-mix(in_srgb,var(--accent)_10%,transparent)]' : 'border-line', depth > 0 ? 'my-2 ml-4 border-l-2 border-l-[var(--accent)]/70' : '']"
    :data-block="`${ctx.uid}-${index}`"
  >
    <header class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-line bg-raised/60 px-2.5 py-1.5 text-[12px]">
      <button class="flex min-h-7 items-center gap-1.5 rounded-md pr-1 text-fg outline-none hover:text-accent-ink" type="button" :aria-expanded="isOpen" :aria-label="`${isOpen ? 'Hide' : 'Show'} the code of ${block.name}`" @click="ctx.toggle(index)">
        <ChevronRight class="size-3.5 shrink-0 text-faint transition-transform duration-200" :class="isOpen ? 'rotate-90' : ''" />
        <span class="font-mono text-[12.5px] font-semibold">{{ block.name }}</span>
      </button>
      <span class="font-mono text-[11px] text-faint">{{ block.file }}:{{ block.start }}–{{ block.end }}</span>
      <Pill v-if="isMain" tone="accent">this step</Pill>
      <Pill v-if="block.untraced && ctx.untraced.value === 'not-run'" tone="warn" title="No line of this function ran in this request">did not run</Pill>
      <Pill v-else-if="block.untraced" tone="warn" :title="ctx.untraced.value === 'outside' ? 'This folder is not in request-tracer.code.paths: the code is shown, but nothing was recorded for its lines' : 'The code is shown, but no line was recorded for it'">not traced line by line</Pill>
      <Pill v-else-if="!isMain && calledFrom">called from {{ calledFrom }}</Pill>
      <span v-if="!block.untraced" class="text-faint">{{ ran }} of {{ block.lines.length }} lines ran</span>
      <span v-if="block.missing" class="text-warn">source file not found on this server</span>
      <span v-else-if="block.changedSinceTrace && block.shift === null" class="text-warn">file changed after this request: line numbers may not match</span>
      <span v-else-if="block.changedSinceTrace && block.shift !== 0" class="text-muted" :title="`The file was edited after this request. The function moved ${Math.abs(block.shift ?? 0)} ${Math.abs(block.shift ?? 0) === 1 ? 'line' : 'lines'} ${(block.shift ?? 0) > 0 ? 'down' : 'up'}; what ran was matched to the statements by their text.`">edited since: moved {{ (block.shift ?? 0) > 0 ? '+' : '' }}{{ block.shift }} lines</span>
    </header>

    <div v-if="isOpen" class="overflow-x-auto py-1">
      <template v-for="(line, l) in block.lines" :key="line.n">
        <button
          class="group/line relative grid w-full min-w-max grid-cols-[18px_40px_minmax(0,1fr)_auto] items-center gap-x-1 py-[1px] pr-3 text-left font-mono text-[12px] leading-[1.65] outline-none transition-colors"
          :class="[
            ctx.popover.activeKey.value === key(l) ? 'bg-[var(--accent)]/12' : line.state === 'threw' ? 'bg-bad/[.07]' : 'hover:bg-hover/70 focus-visible:bg-hover/70',
          ]"
          type="button"
          :data-line="`${ctx.uid}:${index}:${l}`"
          :aria-label="line.description ? `Line ${line.n}: ${line.description}` : `Line ${line.n}`"
          @mouseenter="hover(l, $event)"
          @mouseleave="leave"
          @focus="hover(l, $event)"
          @click="click(l, $event)"
        >
          <span v-if="ctx.popover.activeKey.value === key(l)" class="absolute inset-y-0 left-0 w-[3px] grad-bg"></span>
          <span class="mx-auto size-[7px] rounded-full" :class="line.state === 'plain' ? 'invisible' : ''" :style="{ background: DOT[line.state] ?? 'var(--faint)', boxShadow: line.state === 'ran' || line.state === 'threw' ? `0 0 8px -1px ${DOT[line.state]}` : undefined }" aria-hidden="true"></span>
          <span class="select-none pr-2 text-right text-[11px] text-faint">{{ line.n }}</span>
          <span class="whitespace-pre pr-4" :class="line.state === 'notrun' ? 'text-faint' : 'text-fg'">{{ line.text }}</span>
          <span
            v-if="line.chip"
            class="max-w-[260px] truncate rounded-full px-2 text-[10.5px] font-semibold"
            :style="{ color: DOT[line.state] ?? 'var(--muted)', background: `color-mix(in srgb, ${DOT[line.state] ?? 'var(--muted)'} 13%, transparent)` }"
          >{{ line.chip }}</span>
          <span v-else></span>
        </button>

        <div v-for="edge in edgeLines.get(l) ?? []" :key="edgeId(edge)">
          <CodeBlockView v-if="edge.kind === 'block'" :index="edge.target" :depth="depth + 1" />
          <ChildCode v-else :node="edge.node" :name="edge.name" />
        </div>
      </template>
    </div>
  </section>
</template>
