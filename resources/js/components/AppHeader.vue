<script setup lang="ts">
import { computed, ref } from 'vue'
import { useStore } from '../store'
import RecordDialog from './RecordDialog.vue'

const store = useStore()
const dialog = ref(false)

const modeLabel = computed(() => {
  switch (store.meta?.mode) {
    case 'always': return 'Recording every request'
    case 'triggered': return 'Recording on trigger'
    case 'off': return 'Recording is off'
    default: return 'Checking…'
  }
})
</script>

<template>
  <header class="topbar">
    <div class="brand">
      <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <rect x="2" y="2" width="20" height="20" rx="6" fill="#151b25" stroke="#5fd8bd" stroke-width="1.5" />
        <path d="M6 8h5M6 12h8M6 16h4" stroke="#5fd8bd" stroke-width="1.6" stroke-linecap="round" />
        <circle cx="17" cy="16" r="2.2" fill="#f6a04d" />
      </svg>
      <span>{{ store.meta?.name ?? 'Request Tracer' }}</span>
    </div>

    <span v-if="store.meta" class="badge env-badge" :class="{ production: store.meta.is_production }" :title="store.meta.is_production ? 'This is a production environment: recording is limited to triggers.' : 'Local environment'">
      {{ store.meta.environment }}
    </span>

    <span v-if="store.api.demo" class="badge" style="--badge: var(--purple)">demo data</span>

    <span v-if="store.meta" class="mode" :class="store.meta.mode" role="status">
      <i class="dot" aria-hidden="true"></i>{{ modeLabel }}
    </span>

    <span class="spacer"></span>

    <label v-if="store.modules.length > 0" class="sr-only" for="module-filter">Module</label>
    <select v-if="store.modules.length > 0" id="module-filter" v-model="store.module" @change="store.refilter()">
      <option value="">All modules</option>
      <option v-for="m in store.modules" :key="m" :value="m">{{ m }}</option>
    </select>

    <button v-if="store.meta?.mode !== 'off'" class="btn primary" type="button" @click="dialog = true">Record a request</button>

    <span v-if="store.meta?.user" class="user">{{ store.meta.user.name }}</span>
  </header>

  <RecordDialog v-if="dialog" @close="dialog = false" />
</template>
