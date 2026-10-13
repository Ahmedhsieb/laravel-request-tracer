<script setup lang="ts">
import { Check, ChevronDown } from 'lucide-vue-next'
import { SelectContent, SelectItem, SelectItemIndicator, SelectItemText, SelectPortal, SelectRoot, SelectTrigger, SelectValue, SelectViewport } from 'reka-ui'
import { cn } from '../lib/cn'

/** A select box. Reka's Select forbids an empty-string value, so "no choice" is passed as one of the options with a real value. */
const model = defineModel<string>({ required: true })

defineProps<{ options: Array<{ value: string; label: string }>; label?: string; class?: string }>()
</script>

<template>
  <SelectRoot v-model="model">
    <SelectTrigger
      :aria-label="label"
      :class="cn('inline-flex h-8 items-center justify-between gap-2 rounded-[10px] border border-line bg-surface px-3 text-[12.5px] text-fg outline-none transition-colors hover:border-line-strong focus-visible:border-accent', $props.class)"
    >
      <SelectValue />
      <ChevronDown class="size-3.5 text-muted" />
    </SelectTrigger>
    <SelectPortal>
      <SelectContent position="popper" :side-offset="6" class="z-[80] min-w-[var(--reka-select-trigger-width)] overflow-hidden rounded-[12px] border border-line-strong bg-surface p-1 shadow-2xl data-[state=open]:animate-pop-in">
        <SelectViewport>
          <SelectItem
            v-for="o in options"
            :key="o.value"
            :value="o.value"
            class="relative flex cursor-pointer select-none items-center rounded-[8px] py-1.5 pl-7 pr-3 text-[12.5px] text-fg outline-none data-[highlighted]:bg-hover"
          >
            <SelectItemIndicator class="absolute left-2 grid place-items-center text-[var(--accent-ink)]"><Check class="size-3.5" /></SelectItemIndicator>
            <SelectItemText>{{ o.label }}</SelectItemText>
          </SelectItem>
        </SelectViewport>
      </SelectContent>
    </SelectPortal>
  </SelectRoot>
</template>
