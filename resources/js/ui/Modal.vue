<script setup lang="ts">
import { X } from 'lucide-vue-next'
import { DialogClose, DialogContent, DialogDescription, DialogOverlay, DialogPortal, DialogRoot, DialogTitle } from 'reka-ui'

const open = defineModel<boolean>('open', { required: true })

withDefaults(defineProps<{ title: string; description?: string; width?: number; busy?: boolean }>(), { description: undefined, width: 640, busy: false })
</script>

<template>
  <DialogRoot v-model:open="open">
    <DialogPortal>
      <DialogOverlay class="fixed inset-0 z-50 bg-black/55 backdrop-blur-[3px] data-[state=open]:animate-overlay-in" />
      <DialogContent
        class="glass fixed left-1/2 top-[7vh] z-50 flex max-h-[86vh] -translate-x-1/2 flex-col overflow-hidden rounded-2xl border border-line-strong shadow-[0_30px_80px_-20px_rgba(0,0,0,.6)] outline-none data-[state=open]:animate-pop-in"
        :style="{ width: `min(${width}px, calc(100vw - 24px))` }"
        @interact-outside="(e) => busy && e.preventDefault()"
        @escape-key-down="(e) => busy && e.preventDefault()"
      >
        <header class="flex items-start gap-3 border-b border-line px-5 py-4">
          <div class="min-w-0 flex-1">
            <DialogTitle class="text-[15px] font-semibold tracking-tight text-fg"><slot name="title">{{ title }}</slot></DialogTitle>
            <DialogDescription v-if="description" class="mt-0.5 text-[12.5px] text-muted">{{ description }}</DialogDescription>
            <DialogDescription v-else class="sr-only">{{ title }}</DialogDescription>
          </div>
          <DialogClose :disabled="busy" class="-mr-1.5 -mt-1 grid size-8 place-items-center rounded-lg text-muted transition-colors hover:bg-hover hover:text-fg" aria-label="Close"><X class="size-4" /></DialogClose>
        </header>
        <div class="min-h-0 flex-1 overflow-auto px-5 py-4"><slot /></div>
        <footer v-if="$slots.footer" class="flex flex-wrap items-center gap-2 border-t border-line bg-raised/60 px-5 py-3"><slot name="footer" /></footer>
      </DialogContent>
    </DialogPortal>
  </DialogRoot>
</template>
