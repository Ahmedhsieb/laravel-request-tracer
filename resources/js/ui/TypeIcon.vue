<script setup lang="ts">
import {
  Archive, Box, Boxes, Bug, Database, Globe, Layers, Radio, ScrollText, ShieldCheck, Signpost, Terminal, Timer, Zap,
} from 'lucide-vue-next'
import type { Component } from 'vue'
import type { SpanType } from '../api/types'

defineProps<{ type: SpanType }>()

const ICONS: Record<SpanType, Component> = {
  request: Radio,
  command: Terminal,
  middleware: Layers,
  controller: Signpost,
  validation: ShieldCheck,
  service: Box,
  model: Boxes,
  query: Database,
  http: Globe,
  event: Zap,
  job: Timer,
  cache: Archive,
  log: ScrollText,
  exception: Bug,
}
</script>

<template>
  <component :is="ICONS[type]" class="size-3.5" :style="{ color: `var(--t-${type})` }" aria-hidden="true" />
</template>
