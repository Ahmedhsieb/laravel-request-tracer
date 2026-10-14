<script setup lang="ts">
import { Copy, Moon, Radar, RotateCcw, Search, Sun } from 'lucide-vue-next'
import { computed } from 'vue'
import { ui } from '../lib/ui'
import { useTheme } from '../lib/theme'
import { useStore } from '../store'
import Btn from '../ui/Btn.vue'
import Pick from '../ui/Pick.vue'
import Pill from '../ui/Pill.vue'
import Tip from '../ui/Tip.vue'
import { copyText } from '../lib/clipboard'

const store = useStore()
const { mode, toggle } = useTheme()

/** listening: the listen command runs now; idle: nothing records until it does (or a signed header / watched user asks) */
const state = computed<'listening' | 'always' | 'off' | 'idle' | 'checking'>(() => {
  const meta = store.meta
  if (meta === null) return 'checking'
  if (meta.mode === 'off') return 'off'
  if (meta.mode === 'always') return 'always'

  return meta.listening ? 'listening' : 'idle'
})

const status = computed(() => ({
  listening: { text: 'Listening', hint: 'Tracing everything now', color: 'var(--ok)', pulse: true },
  always: { text: 'Recording', hint: 'Every request is recorded', color: 'var(--accent)', pulse: true },
  off: { text: 'Recording off', hint: 'Nothing is recorded', color: 'var(--faint)', pulse: false },
  idle: { text: 'Not listening', hint: 'Nothing is traced until you start it', color: 'var(--warn)', pulse: false },
  checking: { text: 'Checking', hint: '', color: 'var(--faint)', pulse: false },
})[state.value])

const initials = computed(() => (store.meta?.user?.name ?? '').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]?.toUpperCase()).join(''))
const moduleOptions = computed(() => [{ value: '__all', label: 'All modules' }, ...store.modules.map((m) => ({ value: m, label: m }))])
const moduleModel = computed({
  get: () => (store.module === '' ? '__all' : store.module),
  set: (value: string) => {
    store.module = value === '__all' ? '' : value
    store.refilter()
  },
})

async function copyCommand(): Promise<void> {
  const command = store.meta?.listen_command ?? 'php artisan request-tracer:listen'
  store.toast((await copyText(command)) ? 'Copied: run it in your project, then use your app' : command)
}
</script>

<template>
  <header class="glass relative z-30 flex h-[54px] shrink-0 items-center gap-3 border-b border-line px-4">
    <!-- brand -->
    <button class="group flex items-center gap-2.5 rounded-lg pr-1" type="button" aria-label="All requests" @click="store.close()">
      <svg viewBox="0 0 32 32" class="size-7 transition-transform duration-500 group-hover:rotate-[-8deg] group-hover:scale-110" aria-hidden="true">
        <defs>
          <linearGradient id="rt-g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#5eead4" /><stop offset="1" stop-color="#818cf8" /></linearGradient>
        </defs>
        <rect x="1.5" y="1.5" width="29" height="29" rx="9" fill="color-mix(in srgb, var(--surface) 85%, transparent)" stroke="url(#rt-g)" stroke-width="1.6" />
        <path d="M7 21 L13 21 L16 11 L20 24 L25 14" fill="none" stroke="url(#rt-g)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" class="animate-draw" style="--len: 60; stroke-dasharray: 60" />
        <circle cx="25" cy="14" r="2.3" fill="#f0abfc" />
      </svg>
      <span class="hidden text-[14px] font-semibold tracking-tight sm:inline"><span class="grad-text">Request</span> Tracer</span>
    </button>

    <Pill v-if="store.meta" :tone="store.meta.is_production ? 'warn' : 'accent'">{{ store.meta.environment }}</Pill>
    <Pill v-if="store.api.demo" color="var(--t-event)">demo data</Pill>
    <Tip v-if="store.meta?.unlimited" text="Unlimited: nothing that is stored is capped (values, steps, lines). Only the memory of the process stops it. For debugging, not for production.">
      <span class="inline-flex"><Pill color="var(--warn)">unlimited</Pill></span>
    </Tip>

    <!-- recording state -->
    <div v-if="store.meta" class="flex items-center gap-2" role="status">
      <span class="relative flex size-2.5 items-center justify-center" aria-hidden="true">
        <span v-if="status.pulse" class="absolute inline-flex size-full animate-pulse-ring rounded-full" :style="{ background: status.color }"></span>
        <span v-if="status.pulse" class="absolute inline-flex size-full animate-pulse-ring rounded-full [animation-delay:1.1s]" :style="{ background: status.color }"></span>
        <span class="relative inline-flex size-2 rounded-full" :style="{ background: status.color, boxShadow: `0 0 10px ${status.color}` }"></span>
      </span>
      <Tip :text="status.hint"><span class="cursor-default text-[12.5px] font-medium" :style="{ color: status.color }">{{ status.text }}</span></Tip>
      <button
        v-if="state === 'idle'"
        class="hidden items-center gap-2 rounded-lg border border-dashed border-line-strong px-2.5 py-1 text-[11.5px] text-muted transition-colors hover:border-accent hover:text-fg lg:flex"
        type="button"
        title="Copy the command that starts tracing"
        @click="copyCommand"
      >
        <code class="text-[11.5px]">{{ store.meta?.listen_command }}</code><Copy class="size-3.5" />
      </button>
    </div>

    <!-- command palette trigger -->
    <button
      class="group mx-auto hidden h-9 min-w-0 max-w-[460px] flex-1 items-center gap-2.5 rounded-[11px] border border-line bg-raised/70 px-3 text-left text-muted transition-all hover:border-line-strong hover:bg-raised md:flex"
      type="button"
      @click="ui.palette = true"
    >
      <Search class="size-4 shrink-0 transition-colors group-hover:text-accent-ink" />
      <span class="min-w-0 flex-1 truncate text-[12.5px]">Search requests, steps and actions</span>
      <kbd class="rounded-md border border-line bg-surface px-1.5 py-0.5 text-[10.5px] text-faint">Ctrl K</kbd>
    </button>
    <span class="flex-1 md:hidden"></span>

    <Pick v-if="store.modules.length > 0" v-model="moduleModel" label="Module" :options="moduleOptions" class="hidden w-[150px] sm:inline-flex" />

    <Btn v-if="store.meta?.replay.enabled" variant="primary" size="sm" @click="store.openReplay()"><RotateCcw class="size-3.5" /><span class="hidden sm:inline">Replay a request</span></Btn>
    <Tip v-if="store.meta && store.meta.mode !== 'off'" text="For a server where you cannot run the listen command: a signed header, or watch a user">
      <Btn variant="soft" size="sm" @click="ui.record = true"><Radar class="size-3.5" /><span class="hidden lg:inline">Record from outside</span></Btn>
    </Tip>
    <Tip :text="mode === 'dark' ? 'Light theme' : 'Dark theme'">
      <Btn variant="ghost" size="icon" :aria-label="mode === 'dark' ? 'Switch to the light theme' : 'Switch to the dark theme'" @click="toggle">
        <Sun v-if="mode === 'dark'" class="size-4" /><Moon v-else class="size-4" />
      </Btn>
    </Tip>
    <Tip v-if="store.meta?.user" :text="store.meta.user.name">
      <span class="grid size-8 place-items-center rounded-full border border-line-strong bg-raised text-[11px] font-semibold text-muted">{{ initials }}</span>
    </Tip>
  </header>
</template>
