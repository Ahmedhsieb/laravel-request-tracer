<script setup lang="ts">
import {
  BookOpen, Copy, CornerDownLeft, FolderTree, List, Moon, Radar, RefreshCw, RotateCcw, Search, Sun, X,
} from 'lucide-vue-next'
import {
  DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle, ListboxContent, ListboxFilter, ListboxGroup, ListboxGroupLabel, ListboxItem, ListboxRoot,
} from 'reka-ui'
import { onKeyStroke } from '@vueuse/core'
import { computed, nextTick, ref, watch, type Component } from 'vue'
import { formatMs, relativeTime } from '../lib/format'
import { statusTone } from '../lib/spans'
import { ui } from '../lib/ui'
import { useTheme } from '../lib/theme'
import { useStore } from '../store'
import TypeIcon from '../ui/TypeIcon.vue'
import type { SpanType } from '../api/types'
import { copyText } from '../lib/clipboard'

const store = useStore()
const { mode, toggle } = useTheme()
const query = ref('')

interface Entry {
  id: string
  group: 'Actions' | 'Requests' | 'Steps of this request'
  title: string
  hint: string
  icon?: Component
  type?: SpanType
  tone?: string
  run: () => void
}

onKeyStroke('k', (event) => {
  if (event.ctrlKey || event.metaKey) {
    event.preventDefault()
    ui.palette = !ui.palette
  }
})

watch(() => ui.palette, (open) => {
  if (open) query.value = ''
})

const actions = computed<Entry[]>(() => {
  const out: Entry[] = []
  if (store.meta?.replay.enabled) out.push({ id: 'a-replay', group: 'Actions', title: 'Replay a request', hint: 'Run a request again, traced', icon: RotateCcw, run: () => store.openReplay() })
  if (store.meta && store.meta.mode !== 'off') out.push({ id: 'a-record', group: 'Actions', title: 'Record from outside', hint: 'A signed header, or watch a user', icon: Radar, run: () => { ui.record = true } })
  out.push(
    { id: 'a-traces', group: 'Actions', title: 'Show the traces', hint: 'The request list', icon: List, run: () => store.showView('traces') },
    { id: 'a-journal', group: 'Actions', title: 'Show the journal', hint: 'Every request that was made, traced or not', icon: BookOpen, run: () => store.showView('journal') },
    { id: 'a-group', group: 'Actions', title: store.grouped ? 'Stop grouping by route' : 'Group requests by route', hint: 'Requests of one route prefix together', icon: FolderTree, run: () => store.setGrouping(!store.grouped) },
    { id: 'a-theme', group: 'Actions', title: mode.value === 'dark' ? 'Switch to the light theme' : 'Switch to the dark theme', hint: 'Remembered in this browser', icon: mode.value === 'dark' ? Sun : Moon, run: toggle },
    { id: 'a-reload', group: 'Actions', title: 'Reload the list', hint: 'Look for new requests now', icon: RefreshCw, run: () => void store.reload() },
    { id: 'a-copy', group: 'Actions', title: 'Copy the listen command', hint: store.meta?.listen_command ?? 'php artisan request-tracer:listen', icon: Copy, run: () => void copyText(store.meta?.listen_command ?? 'php artisan request-tracer:listen').then((ok) => store.toast(ok ? 'Copied' : 'Copy failed: select the text and copy it by hand')) },
  )
  if (store.selected !== null) out.push({ id: 'a-close', group: 'Actions', title: 'Close this request', hint: 'Back to the list', icon: X, run: () => store.close() })

  return out
})

const tokens = computed(() => query.value.toLowerCase().split(/\s+/).filter(Boolean))
const matches = (e: { title: string; hint: string }): boolean => tokens.value.every((t) => `${e.title} ${e.hint}`.toLowerCase().includes(t))

const entries = computed<Entry[]>(() => {
  const requests: Entry[] = store.traces
    .map((t): Entry => ({
      id: `t-${t.id}`,
      group: 'Requests',
      title: t.kind === 'request' ? `${t.method ?? ''} ${t.path ?? ''}`.trim() : `${t.kind} ${t.path ?? ''}`.trim(),
      hint: `${t.status ?? ''} · ${formatMs(t.duration_ms)} · ${relativeTime(t.created_at)}`,
      tone: statusTone(t.status),
      run: () => store.open(t.id),
    }))
    .filter(matches)
    .slice(0, 12)
  const steps: Entry[] = ui.steps
    .map((s): Entry => ({ id: `s-${s.id}`, group: 'Steps of this request', title: s.label, hint: s.hint, type: s.type, run: () => ui.reveal?.(s.id) }))
    .filter(matches)
    .slice(0, 30)

  return [...actions.value.filter(matches), ...requests, ...steps]
})

const groups = computed(() => {
  const order: Entry['group'][] = ['Actions', 'Requests', 'Steps of this request']

  return order.map((name) => ({ name, items: entries.value.filter((e) => e.group === name) })).filter((g) => g.items.length > 0)
})

async function choose(entry: Entry): Promise<void> {
  ui.palette = false
  await nextTick()
  entry.run()
}
</script>

<template>
  <DialogRoot v-model:open="ui.palette">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-[60] bg-black/55 backdrop-blur-[3px] data-[state=open]:animate-overlay-in" />
      <DialogContent
        class="glass fixed left-1/2 top-[11vh] z-[61] w-[min(660px,calc(100vw-24px))] -translate-x-1/2 overflow-hidden rounded-2xl border border-line-strong shadow-[0_30px_80px_-20px_rgba(0,0,0,.65)] outline-none data-[state=open]:animate-pop-in"
      >
        <DialogTitle class="sr-only">Search</DialogTitle>
        <DialogDescription class="sr-only">Search requests, the steps of the open request, and actions</DialogDescription>
        <ListboxRoot highlight-on-hover class="flex max-h-[70vh] flex-col">
          <div class="flex items-center gap-3 border-b border-line px-4">
            <Search class="size-4 shrink-0 text-muted" />
            <ListboxFilter v-model="query" auto-focus class="h-12 min-w-0 flex-1 bg-transparent text-[14px] text-fg outline-none placeholder:text-faint" placeholder="Search requests, steps, actions…" />
            <kbd class="rounded-md border border-line bg-surface px-1.5 py-0.5 text-[10.5px] text-faint">Esc</kbd>
          </div>
          <ListboxContent class="min-h-0 flex-1 overflow-auto p-2">
            <p v-if="groups.length === 0" class="px-3 py-10 text-center text-muted">Nothing matches “{{ query }}”.</p>
            <ListboxGroup v-for="g in groups" :key="g.name" class="mb-1.5">
              <ListboxGroupLabel class="px-3 pb-1 pt-2 text-[10.5px] font-semibold uppercase tracking-[.08em] text-faint">{{ g.name }}</ListboxGroupLabel>
              <ListboxItem
                v-for="e in g.items"
                :key="e.id"
                :value="e.id"
                class="group flex cursor-pointer items-center gap-3 rounded-[10px] px-3 py-2 outline-none data-[highlighted]:bg-hover"
                @select="choose(e)"
              >
                <span class="grid size-7 shrink-0 place-items-center rounded-lg border border-line bg-raised text-muted group-data-[highlighted]:border-line-strong group-data-[highlighted]:text-fg">
                  <TypeIcon v-if="e.type" :type="e.type" />
                  <component :is="e.icon" v-else-if="e.icon" class="size-3.5" />
                  <span v-else class="size-1.5 rounded-full" :style="{ background: e.tone === 'bad' ? 'var(--bad)' : e.tone === 'warn' ? 'var(--warn)' : e.tone === 'info' ? 'var(--info)' : 'var(--ok)' }"></span>
                </span>
                <span class="min-w-0 flex-1 truncate font-medium" :class="e.group === 'Requests' || e.group === 'Steps of this request' ? 'font-mono text-[12.5px]' : ''">{{ e.title }}</span>
                <span class="max-w-[45%] shrink-0 truncate text-[11.5px] text-faint">{{ e.hint }}</span>
                <CornerDownLeft class="size-3.5 shrink-0 text-faint opacity-0 group-data-[highlighted]:opacity-100" />
              </ListboxItem>
            </ListboxGroup>
          </ListboxContent>
          <footer class="flex items-center gap-4 border-t border-line bg-raised/50 px-4 py-2 text-[11px] text-faint">
            <span><kbd class="rounded border border-line bg-surface px-1">↑↓</kbd> move</span>
            <span><kbd class="rounded border border-line bg-surface px-1">↵</kbd> open</span>
            <span class="ml-auto">{{ entries.length }} {{ entries.length === 1 ? 'result' : 'results' }}</span>
          </footer>
        </ListboxRoot>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
