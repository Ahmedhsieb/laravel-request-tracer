<script setup lang="ts">
import { BookOpen, Copy, RotateCcw, Search } from 'lucide-vue-next'
import { Motion } from 'motion-v'
import { ui } from '../lib/ui'
import { useStore } from '../store'
import Btn from '../ui/Btn.vue'
import Pill from '../ui/Pill.vue'

/** What the workspace shows before a request is picked: where the code is looked into, and the ways in. */
const store = useStore()

async function copy(): Promise<void> {
  const command = store.meta?.listen_command ?? 'php artisan request-tracer:listen'
  try {
    await navigator.clipboard.writeText(command)
    store.toast('Copied: run it in your project, then use your app')
  } catch {
    store.toast(command)
  }
}

// the nodes of the animated trace: where they sit on the line, and the colour of the kind of step they stand for
const nodes = [
  { x: 40, y: 150, c: 'var(--t-request)', d: 0.15 },
  { x: 130, y: 96, c: 'var(--t-middleware)', d: 0.3 },
  { x: 225, y: 150, c: 'var(--t-controller)', d: 0.45 },
  { x: 320, y: 84, c: 'var(--t-service)', d: 0.6 },
  { x: 415, y: 140, c: 'var(--t-query)', d: 0.75 },
  { x: 510, y: 100, c: 'var(--t-http)', d: 0.9 },
  { x: 600, y: 150, c: 'var(--t-event)', d: 1.05 },
]
</script>

<template>
  <div class="relative flex h-full min-h-0 flex-col items-center justify-center overflow-auto px-6 py-10">
    <Motion :initial="{ opacity: 0, y: 18 }" :animate="{ opacity: 1, y: 0 }" :transition="{ type: 'spring', stiffness: 160, damping: 22 }" class="w-full max-w-[680px]">
      <svg viewBox="0 0 640 190" class="mx-auto mb-6 w-full max-w-[600px] animate-float overflow-visible" aria-hidden="true">
        <defs>
          <linearGradient id="rt-line" x1="0" x2="1"><stop offset="0" stop-color="#5eead4" /><stop offset=".55" stop-color="#818cf8" /><stop offset="1" stop-color="#f0abfc" /></linearGradient>
          <filter id="rt-glow" x="-20%" y="-50%" width="140%" height="200%"><feGaussianBlur stdDeviation="5" /></filter>
        </defs>
        <path d="M40 150 C 90 150 90 96 130 96 S 190 150 225 150 S 285 84 320 84 S 380 140 415 140 S 470 100 510 100 S 565 150 600 150" fill="none" stroke="url(#rt-line)" stroke-width="6" stroke-linecap="round" opacity=".5" filter="url(#rt-glow)" class="animate-draw" style="--len: 900; stroke-dasharray: 900" />
        <path d="M40 150 C 90 150 90 96 130 96 S 190 150 225 150 S 285 84 320 84 S 380 140 415 140 S 470 100 510 100 S 565 150 600 150" fill="none" stroke="url(#rt-line)" stroke-width="2.4" stroke-linecap="round" class="animate-draw" style="--len: 900; stroke-dasharray: 900" />
        <g v-for="n in nodes" :key="n.x">
          <circle :cx="n.x" :cy="n.y" r="13" :fill="n.c" opacity=".16" class="animate-pop-in" :style="{ animationDelay: `${n.d}s`, transformOrigin: `${n.x}px ${n.y}px` }" />
          <circle :cx="n.x" :cy="n.y" r="5.5" :fill="n.c" class="animate-pop-in" :style="{ animationDelay: `${n.d}s`, transformOrigin: `${n.x}px ${n.y}px` }" />
        </g>
      </svg>

      <h1 class="text-center text-[26px] font-semibold tracking-tight"><span class="grad-text">Pick a request</span> on the left</h1>
      <p class="mx-auto mt-2 max-w-[520px] text-center text-[13.5px] leading-relaxed text-muted">
        Every traced request shows its steps on a timeline, what went in and out of each one, the tables it wrote and what went wrong.
      </p>

      <div class="mt-6 flex flex-wrap justify-center gap-2.5">
        <Btn variant="soft" size="md" @click="ui.palette = true"><Search class="size-4" />Search<kbd class="rounded border border-line bg-surface px-1 text-[10.5px] text-faint">Ctrl K</kbd></Btn>
        <Btn v-if="store.meta?.replay.enabled" variant="soft" size="md" @click="store.openReplay()"><RotateCcw class="size-4" />Replay a request</Btn>
        <Btn v-if="store.meta?.journal.enabled" variant="soft" size="md" @click="store.showView('journal')"><BookOpen class="size-4" />Open the journal</Btn>
      </div>

      <div v-if="store.meta && !store.meta.listening && store.meta.mode === 'triggered'" class="glass mx-auto mt-7 flex max-w-[560px] items-center gap-3 rounded-xl border border-dashed border-line-strong px-4 py-3">
        <div class="min-w-0 flex-1">
          <p class="text-[12px] text-muted">Nothing is traced until you ask. In your project, run:</p>
          <code class="mt-0.5 block truncate text-[13px] text-accent-ink">{{ store.meta.listen_command }}</code>
        </div>
        <Btn size="sm" variant="soft" @click="copy"><Copy class="size-3.5" />Copy</Btn>
      </div>

      <p v-if="store.meta" class="mt-7 flex flex-wrap items-center justify-center gap-1.5 text-[12px] text-muted">
        Code looked into
        <Pill v-for="p in store.meta.code.paths" :key="p" tone="accent">{{ p }}</Pill>
        <template v-if="store.meta.code.paths.length === 0"><Pill tone="warn">nothing (code.paths is empty)</Pill></template>
        <template v-if="store.meta.code.except.length > 0">except <Pill v-for="p in store.meta.code.except" :key="p" tone="neutral">{{ p }}</Pill></template>
        <Pill v-if="!store.meta.code.line_by_line" tone="warn">line by line is off</Pill>
      </p>
    </Motion>
  </div>
</template>
