<script setup lang="ts">
import { TooltipContent, TooltipPortal, TooltipRoot, TooltipTrigger } from 'reka-ui'

withDefaults(defineProps<{ text?: string; side?: 'top' | 'right' | 'bottom' | 'left'; delay?: number }>(), { text: undefined, side: 'bottom', delay: 300 })
</script>

<template>
  <TooltipRoot :delay-duration="delay">
    <TooltipTrigger as-child><slot /></TooltipTrigger>
    <TooltipPortal>
      <TooltipContent
        :side="side"
        :side-offset="8"
        :collision-padding="10"
        class="z-[70] max-w-[320px] rounded-[9px] border border-line-strong bg-surface px-2.5 py-1.5 text-[12px] leading-snug text-fg shadow-xl data-[state=delayed-open]:animate-pop-in data-[state=instant-open]:animate-pop-in"
      >
        <slot name="content">{{ text }}</slot>
      </TooltipContent>
    </TooltipPortal>
  </TooltipRoot>
</template>
