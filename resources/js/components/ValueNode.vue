<script setup lang="ts">
import { computed, ref } from 'vue'
import type { Dump } from '../api/types'
import { shortClass } from '../lib/format'
import Pill from '../ui/Pill.vue'

const props = withDefaults(defineProps<{
  value: Dump
  label?: string | number
  vis?: string
  depth?: number
  /** nodes at a depth below this start open */
  openDepth?: number
}>(), { label: undefined, vis: '', depth: 0, openDepth: 2 })

interface Child {
  label: string | number
  vis: string
  value: Dump
}

const open = ref(props.depth < props.openDepth)

const tag = computed(() => (props.value === null || typeof props.value !== 'object' ? null : props.value.$))

const children = computed<Child[]>(() => {
  const v = props.value
  if (v === null || typeof v !== 'object') return []
  if (v.$ === 'arr') {
    return (v.i ?? []).map((entry, index) => (Array.isArray(entry)
      ? { label: entry[0], vis: '', value: entry[1] }
      : { label: index, vis: '', value: entry as Dump }))
  }
  if (v.$ === 'obj') return (v.i ?? []).map(([name, vis, value]) => ({ label: name, vis, value }))

  return []
})

const more = computed(() => {
  const v = props.value
  return v !== null && typeof v === 'object' && (v.$ === 'arr' || v.$ === 'obj') ? (v.cut ?? 0) : 0
})

const expandable = computed(() => (tag.value === 'arr' || tag.value === 'obj') && (children.value.length > 0 || more.value > 0))

const visSymbol = computed(() => ({ public: '+', protected: '#', private: '-' } as Record<string, string>)[props.vis] ?? '')

const head = computed(() => {
  const v = props.value
  if (v === null || typeof v !== 'object') return ''
  if (v.$ === 'arr') return `array:${v.n}`
  if (v.$ === 'obj') {
    const key = v.kind === 'model' ? v.i?.find((p) => p[0] === 'key')?.[2] : undefined

    return `${shortClass(v.c)}${key !== undefined && key !== null && typeof key !== 'object' ? ` #${key}` : ''}`
  }

  return ''
})

const fullClass = computed(() => (props.value !== null && typeof props.value === 'object' && props.value.$ === 'obj' ? props.value.c : ''))
const kindNote = computed(() => (props.value !== null && typeof props.value === 'object' && props.value.$ === 'obj' && props.value.kind !== undefined ? props.value.kind : ''))
function quote(text: string): string {
  return JSON.stringify(text)
}
</script>

<template>
  <div class="flex min-w-0 items-baseline whitespace-pre font-mono text-[12px] leading-[1.75]">
    <button v-if="expandable" class="mr-0.5 grid size-4 shrink-0 translate-y-[3px] place-items-center rounded text-faint transition-colors hover:bg-hover hover:text-fg" type="button" :aria-expanded="open" :aria-label="open ? 'Collapse' : 'Expand'" @click="open = !open">
      <svg viewBox="0 0 10 10" class="size-2.5 transition-transform duration-150" :class="open ? 'rotate-90' : ''" aria-hidden="true"><path d="M3 1.5l4 3.5-4 3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" /></svg>
    </button>
    <span v-else class="mr-0.5 inline-block size-4 shrink-0" aria-hidden="true"></span>

    <template v-if="label !== undefined">
      <span v-if="visSymbol" class="dd-vis">{{ visSymbol }}</span>
      <span class="dd-k">{{ typeof label === 'number' ? label : quote(label) }}</span>
      <span class="dd-m"> => </span>
    </template>

    <template v-if="value === null"><span class="dd-c">null</span></template>
    <template v-else-if="typeof value === 'boolean'"><span class="dd-c">{{ value ? 'true' : 'false' }}</span></template>
    <template v-else-if="typeof value === 'number'"><span class="dd-n">{{ value }}</span></template>
    <template v-else-if="typeof value === 'string'"><span class="dd-s">{{ quote(value) }}</span></template>
    <template v-else-if="tag === 'str' && 'v' in value && 'cut' in value">
      <span class="dd-s">{{ quote(value.v) }}…</span> <Pill tone="warn">+{{ value.cut }} more chars</Pill>
    </template>
    <template v-else-if="tag === 'mask'"><span class="dd-mask" title="Masked before it was stored">••••••••</span></template>
    <template v-else-if="tag === 'type' && 't' in value"><span class="dd-m">{{ value.t }}<template v-if="value.size !== undefined"> ({{ value.size }})</template></span></template>
    <template v-else-if="tag === 'bin' && 'len' in value"><span class="dd-m">binary ({{ value.len }} B)</span></template>
    <template v-else-if="(tag === 'float' || tag === 'int') && 'v' in value"><span class="dd-n">{{ value.v }}</span></template>
    <template v-else-if="tag === 'arr'">
      <span class="dd-c">{{ head }}</span> <span class="dd-m">[</span><span v-if="!open" class="dd-m"> … ]</span>
    </template>
    <template v-else-if="tag === 'obj'">
      <span class="dd-c" :title="fullClass">{{ head }}</span> <span v-if="kindNote" class="dd-m">({{ kindNote }})</span> <span class="dd-m">{</span><span v-if="!open" class="dd-m"> … }</span>
    </template>
  </div>

  <div v-if="open && expandable" class="ml-[7px] border-l border-line/80 pl-3.5">
    <ValueNode v-for="(child, i) in children" :key="i" :value="child.value" :label="child.label" :vis="child.vis" :depth="depth + 1" :open-depth="openDepth" />
    <div v-if="more > 0" class="flex items-baseline whitespace-pre font-mono text-[12px] leading-[1.75]"><span class="mr-0.5 inline-block size-4 shrink-0"></span><Pill tone="warn">… {{ more }} more not stored</Pill></div>
    <div class="flex items-baseline whitespace-pre font-mono text-[12px] leading-[1.75]"><span class="mr-0.5 inline-block size-4 shrink-0"></span><span class="dd-m">{{ tag === 'arr' ? ']' : '}' }}</span></div>
  </div>
</template>
