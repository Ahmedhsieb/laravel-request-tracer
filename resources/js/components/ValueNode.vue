<script setup lang="ts">
import { computed, ref } from 'vue'
import type { Dump } from '../api/types'
import { shortClass } from '../lib/format'

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
  <div class="dd-row">
    <button v-if="expandable" class="dd-toggle" type="button" :aria-expanded="open" :aria-label="open ? 'Collapse' : 'Expand'" @click="open = !open">{{ open ? '▾' : '▸' }}</button>
    <span v-else class="dd-toggle" aria-hidden="true"></span>

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
      <span class="dd-s">{{ quote(value.v) }}…</span> <span class="marker truncated">+{{ value.cut }} more chars</span>
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

  <div v-if="open && expandable" class="dd-children">
    <ValueNode v-for="(child, i) in children" :key="i" :value="child.value" :label="child.label" :vis="child.vis" :depth="depth + 1" :open-depth="openDepth" />
    <div v-if="more > 0" class="dd-row"><span class="dd-toggle"></span><span class="marker truncated">… {{ more }} more not stored</span></div>
    <div class="dd-row"><span class="dd-toggle"></span><span class="dd-m">{{ tag === 'arr' ? ']' : '}' }}</span></div>
  </div>
</template>
