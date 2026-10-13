<script setup lang="ts">
import { SwitchRoot, SwitchThumb } from 'reka-ui'

const model = defineModel<boolean>({ required: true })
defineProps<{ label?: string }>()
</script>

<template>
  <SwitchRoot
    v-model="model"
    :aria-label="label"
    class="relative inline-flex h-[20px] w-[36px] shrink-0 items-center rounded-full border border-line-strong bg-raised outline-none transition-colors data-[state=checked]:border-transparent data-[state=checked]:bg-[var(--accent)]"
  >
    <SwitchThumb class="block size-[14px] translate-x-[2px] rounded-full bg-fg shadow transition-transform duration-200 ease-[var(--ease-spring)] data-[state=checked]:translate-x-[18px] data-[state=checked]:bg-[#04141c]" />
  </SwitchRoot>
</template>
