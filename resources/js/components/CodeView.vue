<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref } from 'vue'
import type { SpanLines } from '../api/types'
import { buildCode, executionOrder, type DotState } from '../lib/code'
import ValueNode from './ValueNode.vue'

const props = defineProps<{ data: SpanLines }>()

const blocks = computed(() => buildCode(props.data))
const steps = computed(() => executionOrder(blocks.value))
const active = ref<{ b: number; l: number } | null>(null)
const position = ref(-1)
const playing = ref(false)
const root = ref<HTMLElement | null>(null)
let timer: ReturnType<typeof setInterval> | null = null

const card = computed(() => {
  const a = active.value
  return a === null ? null : (blocks.value[a.b]?.lines[a.l] ?? null)
})

const legend: Array<{ state: DotState; text: string; color: string }> = [
  { state: 'ran', text: 'ran / true', color: 'var(--green)' },
  { state: 'false', text: 'false', color: 'var(--purple)' },
  { state: 'threw', text: 'threw', color: 'var(--red)' },
  { state: 'caught', text: 'caught', color: 'var(--orange)' },
  { state: 'loop', text: 'loop', color: 'var(--blue)' },
  { state: 'notrun', text: 'not run', color: '#5b667a' },
]

function pick(b: number, l: number): void {
  active.value = { b, l }
}

async function goto(index: number): Promise<void> {
  const step = steps.value[index]
  if (step === undefined) return
  position.value = index
  pick(step.block, step.line)
  await nextTick()
  root.value?.querySelector<HTMLElement>(`[data-line="${step.block}-${step.line}"]`)?.scrollIntoView({ block: 'nearest' })
}

function stop(): void {
  playing.value = false
  if (timer !== null) clearInterval(timer)
  timer = null
}

function next(): void {
  if (position.value < steps.value.length - 1) void goto(position.value + 1)
  else stop()
}

function back(): void {
  stop()
  if (position.value > 0) void goto(position.value - 1)
}

function replay(): void {
  if (playing.value) {
    stop()
    return
  }
  if (position.value >= steps.value.length - 1) position.value = -1
  playing.value = true
  next()
  timer = setInterval(next, 750)
}

const progress = computed(() => (steps.value.length === 0 ? 0 : ((position.value + 1) / steps.value.length) * 100))

onBeforeUnmount(stop)
</script>

<template>
  <div ref="root">
    <div v-for="(block, b) in blocks" :key="block.file + block.start" class="code">
      <header>
        <span class="name">{{ block.name }}</span>
        <span class="muted mono">{{ block.file }}:{{ block.start }}–{{ block.end }}</span>
        <span v-if="block.missing" class="tone-warn">source file not found on this server</span>
        <span v-else-if="block.changedSinceTrace" class="tone-warn">file changed after this request: line numbers may not match</span>
      </header>
      <div class="code-lines">
        <button
          v-for="(line, l) in block.lines"
          :key="line.n"
          class="cl"
          type="button"
          :class="[line.state, { current: active?.b === b && active?.l === l }]"
          :data-line="`${b}-${l}`"
          :aria-label="line.description ? `Line ${line.n}: ${line.description}` : `Line ${line.n}`"
          @mouseenter="pick(b, l)"
          @focus="pick(b, l)"
          @click="pick(b, l)"
        >
          <span class="ds" aria-hidden="true"></span>
          <span class="n">{{ line.n }}</span>
          <span class="t">{{ line.text }}</span>
          <span v-if="line.chip" class="chip-note">{{ line.chip }}</span>
          <span v-else></span>
        </button>
      </div>
    </div>

    <div class="replay" role="group" aria-label="Replay the execution">
      <button class="btn small" type="button" :disabled="steps.length === 0" @click="replay">{{ playing ? 'Pause' : (position >= 0 ? 'Resume' : 'Replay') }}</button>
      <button class="btn small" type="button" :disabled="position <= 0" @click="back">Back</button>
      <button class="btn small" type="button" :disabled="position >= steps.length - 1" @click="stop(); next()">Next</button>
      <div class="progress" role="progressbar" :aria-valuenow="Math.max(0, position + 1)" aria-valuemin="0" :aria-valuemax="steps.length" aria-label="Replay position"><i :style="{ width: `${progress}%` }"></i></div>
      <span class="muted mono">{{ Math.max(0, position + 1) }} / {{ steps.length }}</span>
    </div>

    <div v-if="card" class="line-card" aria-live="polite">
      <h3>Line {{ card.n }}</h3>
      <p v-if="card.description">{{ card.description }}</p>
      <p v-else class="muted">Nothing is recorded for this line.</p>
      <div v-if="card.variables.length > 0" class="vars">
        <div v-for="(v, i) in card.variables" :key="i" class="var">
          <span class="k">{{ v.label }}</span>
          <div class="mono dd" style="max-height: 160px"><ValueNode :value="v.value" :open-depth="1" /></div>
        </div>
      </div>
    </div>
    <p v-else class="hint" style="margin: 10px 0 0">Hover or focus a line to see what happened there, or press Replay.</p>

    <ul class="dots-legend" aria-label="Legend">
      <li v-for="item in legend" :key="item.state" style="list-style: none"><span><i :style="{ '--dc': item.color }"></i>{{ item.text }}</span></li>
    </ul>
  </div>
</template>
