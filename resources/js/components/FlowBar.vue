<script setup lang="ts">
import { ChevronRight, CornerLeftUp, Ellipsis, GitFork, ListFilter, Rows3, Search, X } from 'lucide-vue-next'
import { PopoverContent, PopoverPortal, PopoverRoot, PopoverTrigger } from 'reka-ui'
import { computed } from 'vue'
import type { Flow } from '../lib/flow'
import { plural, shortClass } from '../lib/format'
import { TYPE_LABEL } from '../lib/spans'
import Btn from '../ui/Btn.vue'
import Chip from '../ui/Chip.vue'
import Seg from '../ui/Seg.vue'
import Tip from '../ui/Tip.vue'
import TypeIcon from '../ui/TypeIcon.vue'

const props = defineProps<{ flow: Flow }>()
const emit = defineEmits<{ select: [id: number] }>()

const { query, speed, result, include, view, path, list, kinds, folder, activeFilters } = props.flow

/** A long path keeps its first step and the last two; the steps in between sit behind a "…" button. */
const KEEP_TAIL = 2
const head = computed(() => (path.value.length > KEEP_TAIL + 2 ? path.value.slice(0, 1) : path.value.slice(0, Math.max(0, path.value.length - KEEP_TAIL))))
const hidden = computed(() => (path.value.length > KEEP_TAIL + 2 ? path.value.slice(1, path.value.length - KEEP_TAIL) : []))
const tail = computed(() => path.value.slice(head.value.length + hidden.value.length))
const badge = computed(() => activeFilters.value + (include.value ? 0 : 1))
const filtering = computed(() => list.value.filtering)
const scope = computed<'inside' | 'level'>({
  get: () => (include.value ? 'inside' : 'level'),
  set: (value) => {
    include.value = value === 'inside'
  },
})
const scopeName = computed(() => (folder.value === null ? 'the request' : shortClass(folder.value.span.label)))
</script>

<template>
  <div class="shrink-0 border-b border-line">
    <!-- how to look at it, and what to look for -->
    <div class="flex items-center gap-1.5 px-2.5 pt-2">
      <Seg v-model="view" label="View" size="sm" :options="[{ value: 'waterfall', label: 'Waterfall' }, { value: 'graph', label: 'Graph' }]" class="shrink-0" />
      <div class="relative min-w-0 flex-1">
        <Search class="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-faint" />
        <label class="sr-only" for="step-search">Search the steps inside this folder</label>
        <input id="step-search" v-model="query" class="input h-8 pl-8 text-[12.5px]" type="search" :placeholder="`Search inside ${scopeName}`" autocomplete="off">
      </div>

      <PopoverRoot>
        <PopoverTrigger as-child>
          <Btn variant="soft" size="sm" aria-label="Filter by speed, result and scope">
            <ListFilter class="size-3.5" /><span class="hidden xl:inline">Filters</span>
            <span v-if="badge > 0" class="grid size-4 place-items-center rounded-full bg-[var(--accent)] text-[10px] font-bold text-[#04141c]">{{ badge }}</span>
          </Btn>
        </PopoverTrigger>
        <PopoverPortal>
          <PopoverContent align="end" :side-offset="8" class="z-[70] w-[290px] space-y-3 rounded-xl border border-line-strong bg-surface p-3.5 shadow-2xl data-[state=open]:animate-pop-in">
            <div class="space-y-1.5">
              <p class="text-[11px] font-semibold uppercase tracking-wide text-faint">Look in</p>
              <Seg v-model="scope" label="Scope of the filters" size="sm" :options="[{ value: 'inside', label: 'Everything inside' }, { value: 'level', label: 'This level only' }]" />
              <p class="text-[11px] text-faint">Everything inside: every step below the open folder, however deep. This level only: just the folder's own steps.</p>
            </div>
            <div class="space-y-1.5">
              <p class="text-[11px] font-semibold uppercase tracking-wide text-faint">Speed</p>
              <Seg v-model="speed" label="Speed" size="sm" :options="[{ value: 'all', label: 'Any' }, { value: 'slow', label: 'Slow' }, { value: 'fast', label: 'Fast' }]" />
              <p class="text-[11px] text-faint">Slow: flagged slow, or at least as long as the slow-step limit.</p>
            </div>
            <div class="space-y-1.5">
              <p class="text-[11px] font-semibold uppercase tracking-wide text-faint">Result</p>
              <Seg v-model="result" label="Result" size="sm" :options="[{ value: 'all', label: 'Any' }, { value: 'failed', label: 'Failed' }, { value: 'ok', label: 'OK' }]" />
              <p class="text-[11px] text-faint">Failed: threw, was caught after throwing, or an HTTP call answered 4xx/5xx.</p>
            </div>
          </PopoverContent>
        </PopoverPortal>
      </PopoverRoot>
    </div>

    <!-- the kinds of step in this folder: a legend that is also a filter -->
    <div class="flex items-center gap-1.5 overflow-x-auto px-2.5 py-2" role="group" aria-label="Show only these kinds of step">
      <Chip v-for="t in kinds" :key="t.type" :active="flow.types.value.has(t.type)" :color="`var(--t-${t.type})`" class="h-6 px-2.5 text-[11.5px]" @click="flow.toggleType(t.type)">
        <span class="size-1.5 rounded-full" :style="{ background: `var(--t-${t.type})` }"></span>{{ TYPE_LABEL[t.type] }}<span class="font-mono text-[10.5px] text-faint">{{ t.count }}</span>
      </Chip>
      <template v-if="filtering">
        <span class="ml-1 shrink-0 text-[11.5px] text-muted" role="status">{{ list.matches }} of {{ plural(list.scope, 'step') }}{{ include ? ' inside' : ' here' }}</span>
        <button class="flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-[11.5px] text-muted hover:bg-hover hover:text-fg" type="button" @click="flow.clearFilters()"><X class="size-3" />Clear</button>
      </template>
    </div>

    <!-- where you are: the folders from the request down to this one -->
    <nav class="flex items-center gap-0.5 border-t border-line bg-raised/40 px-2 py-1.5" aria-label="Folders">
      <Tip text="Up one folder (Backspace)">
        <Btn variant="ghost" size="icon-sm" aria-label="Up one folder" :disabled="path.length <= 1" @click="flow.up()"><CornerLeftUp class="size-3.5" /></Btn>
      </Tip>
      <button v-if="folder === null" class="flex h-7 items-center gap-1.5 rounded-md px-2 text-[12px] font-medium text-fg" type="button" @click="flow.open(null)"><Rows3 class="size-3.5 text-muted" />Request</button>

      <template v-for="(c, i) in head" :key="c.span.id">
        <ChevronRight v-if="i > 0" class="size-3 shrink-0 text-faint" />
        <button class="flex h-7 min-w-0 max-w-[170px] items-center gap-1.5 rounded-md px-1.5 text-[12px] text-muted transition-colors hover:bg-hover hover:text-fg" type="button" :title="c.span.label" @click="flow.open(c.span.id)">
          <TypeIcon :type="c.span.type" /><span class="truncate font-mono">{{ shortClass(c.span.label) }}</span>
        </button>
      </template>

      <template v-if="hidden.length > 0">
        <ChevronRight class="size-3 shrink-0 text-faint" />
        <PopoverRoot>
          <PopoverTrigger as-child>
            <button class="grid h-7 w-8 shrink-0 place-items-center rounded-md text-muted hover:bg-hover hover:text-fg" type="button" :aria-label="`${hidden.length} more folders in the path`"><Ellipsis class="size-4" /></button>
          </PopoverTrigger>
          <PopoverPortal>
            <PopoverContent align="start" :side-offset="6" class="z-[70] max-h-[320px] w-[300px] overflow-auto rounded-xl border border-line-strong bg-surface p-1.5 shadow-2xl data-[state=open]:animate-pop-in">
              <button v-for="c in hidden" :key="c.span.id" class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-[12.5px] hover:bg-hover" type="button" @click="flow.open(c.span.id)">
                <TypeIcon :type="c.span.type" /><span class="truncate font-mono">{{ shortClass(c.span.label) }}</span>
              </button>
            </PopoverContent>
          </PopoverPortal>
        </PopoverRoot>
      </template>

      <template v-for="(c, i) in tail" :key="c.span.id">
        <ChevronRight v-if="i > 0 || head.length > 0 || hidden.length > 0" class="size-3 shrink-0 text-faint" />
        <button
          class="flex h-7 min-w-0 max-w-[230px] items-center gap-1.5 rounded-md px-1.5 text-[12px] transition-colors hover:bg-hover"
          :class="i === tail.length - 1 ? 'bg-[var(--accent)]/12 font-medium text-fg' : 'text-muted hover:text-fg'"
          type="button"
          :title="c.span.label"
          :aria-current="i === tail.length - 1 ? 'location' : undefined"
          @click="i === tail.length - 1 ? emit('select', c.span.id) : flow.open(c.span.id)"
        >
          <TypeIcon :type="c.span.type" /><span class="truncate font-mono">{{ shortClass(c.span.label) }}</span>
        </button>
      </template>

      <span class="flex-1"></span>
      <span class="hidden shrink-0 items-center gap-1 pr-1.5 text-[11.5px] text-faint sm:flex"><GitFork class="size-3" />{{ plural(list.entries.length, filtering ? 'match' : 'step', filtering ? 'matches' : 'steps') }}</span>
    </nav>
  </div>
</template>
