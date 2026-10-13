<script setup lang="ts">
import { useMediaQuery } from '@vueuse/core'
import { MotionConfig } from 'motion-v'
import { SplitterGroup, SplitterPanel, SplitterResizeHandle, TooltipProvider } from 'reka-ui'
import { Toaster, toast } from 'vue-sonner'
import { watch } from 'vue'
import CommandPalette from './components/CommandPalette.vue'
import RecordDialog from './components/RecordDialog.vue'
import ReplayDialog from './components/ReplayDialog.vue'
import RequestPanel from './components/RequestPanel.vue'
import TopBar from './components/TopBar.vue'
import TraceView from './components/TraceView.vue'
import Welcome from './components/Welcome.vue'
import { useTheme } from './lib/theme'
import { useStore } from './store'

const store = useStore()
const { mode } = useTheme()
const narrow = useMediaQuery('(max-width: 900px)')

// the store's one-line notices are shown as toasts
watch(() => store.toastText, (text) => {
  if (text !== null) toast(text, { duration: 2600 })
})
</script>

<template>
  <TooltipProvider :delay-duration="300" :skip-delay-duration="150" ignore-non-keyboard-focus>
    <MotionConfig reduced-motion="user">
      <div class="flex h-full flex-col">
        <TopBar />

        <!-- a window too narrow for two panes shows one at a time: the list, or the open request -->
        <main v-if="narrow" class="min-h-0 flex-1">
          <RequestPanel v-if="store.selected === null" />
          <TraceView v-else :key="store.selected" :trace-id="store.selected" />
        </main>

        <SplitterGroup v-else direction="horizontal" auto-save-id="request-tracer:panes" class="min-h-0 flex-1">
          <SplitterPanel :default-size="28" :min-size="20" :max-size="46" class="min-w-0">
            <RequestPanel />
          </SplitterPanel>
          <SplitterResizeHandle class="group relative w-px bg-line outline-none transition-colors hover:bg-accent data-[resize-handle-state=drag]:bg-accent" aria-label="Resize the list">
            <span class="absolute inset-y-0 -left-1.5 -right-1.5"></span>
          </SplitterResizeHandle>
          <SplitterPanel :min-size="40" class="min-w-0">
            <main class="h-full min-h-0" aria-live="polite">
              <Transition name="swap" mode="out-in">
                <TraceView v-if="store.selected !== null" :key="store.selected" :trace-id="store.selected" />
                <Welcome v-else />
              </Transition>
            </main>
          </SplitterPanel>
        </SplitterGroup>
      </div>

      <ReplayDialog />
      <RecordDialog />
      <CommandPalette />
      <Toaster :theme="mode === 'dark' ? 'dark' : 'light'" position="bottom-right" :toast-options="{ class: 'rt-toast' }" />
    </MotionConfig>
  </TooltipProvider>
</template>

<style>
.swap-enter-active { transition: opacity 0.22s ease, transform 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
.swap-leave-active { transition: opacity 0.12s ease; }
.swap-enter-from { opacity: 0; transform: translateY(10px); }
.swap-leave-to { opacity: 0; }
</style>
