<script setup lang="ts">
import { computed, inject } from 'vue'
import { CODE_CONTEXT, type Edge } from '../lib/codeContext'
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
  <section v-if="block" class="code" :class="{ main: isMain, nested: depth > 0 }" :data-block="`${ctx.uid}-${index}`">
    <header>
      <button class="fold" type="button" :aria-expanded="isOpen" :aria-label="`${isOpen ? 'Hide' : 'Show'} the code of ${block.name}`" @click="ctx.toggle(index)">
        <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true"><path d="M5 3l4 4-4 4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" /></svg>
        <span class="name">{{ block.name }}</span>
      </button>
      <span class="muted mono">{{ block.file }}:{{ block.start }}–{{ block.end }}</span>
      <span v-if="isMain" class="tag">this step</span>
      <span v-if="block.untraced" class="tag warn" title="This folder is not in request-tracer.code.paths: the code is shown, but nothing was recorded for its lines">not traced line by line</span>
      <span v-else-if="calledFrom" class="tag">called from {{ calledFrom }}</span>
      <span v-if="!block.untraced" class="muted">{{ ran }} of {{ block.lines.length }} lines ran</span>
      <span v-if="block.missing" class="tone-warn">source file not found on this server</span>
      <span v-else-if="block.changedSinceTrace && block.shift === null" class="tone-warn">file changed after this request: line numbers may not match</span>
      <span v-else-if="block.changedSinceTrace && block.shift !== 0" class="muted" :title="`The file was edited after this request. The function moved ${Math.abs(block.shift ?? 0)} ${Math.abs(block.shift ?? 0) === 1 ? 'line' : 'lines'} ${(block.shift ?? 0) > 0 ? 'down' : 'up'}; what ran was matched to the statements by their text.`">edited since: moved {{ (block.shift ?? 0) > 0 ? '+' : '' }}{{ block.shift }} lines</span>
    </header>

    <div v-if="isOpen" class="code-lines">
      <template v-for="(line, l) in block.lines" :key="line.n">
        <button
          class="cl"
          type="button"
          :class="[line.state, { current: ctx.popover.activeKey.value === key(l) }]"
          :data-line="`${ctx.uid}:${index}:${l}`"
          :aria-label="line.description ? `Line ${line.n}: ${line.description}` : `Line ${line.n}`"
          @mouseenter="hover(l, $event)"
          @mouseleave="leave"
          @focus="hover(l, $event)"
          @click="click(l, $event)"
        >
          <span class="ds" aria-hidden="true"></span>
          <span class="n">{{ line.n }}</span>
          <span class="t">{{ line.text }}</span>
          <span v-if="line.chip" class="chip-note">{{ line.chip }}</span>
          <span v-else></span>
        </button>

        <div v-for="edge in edgeLines.get(l) ?? []" :key="edgeId(edge)" class="call">
          <CodeBlockView v-if="edge.kind === 'block'" :index="edge.target" :depth="depth + 1" />
          <ChildCode v-else :node="edge.node" :name="edge.name" />
        </div>
      </template>
    </div>
  </section>
</template>
