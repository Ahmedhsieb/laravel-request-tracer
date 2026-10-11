<script setup lang="ts">
import { useStore } from '../store'

const store = useStore()
</script>

<template>
  <div class="list-tabs segmented" role="group" aria-label="What the list shows">
    <button type="button" :aria-pressed="store.view === 'traces'" @click="store.showView('traces')">Traces</button>
    <button type="button" :aria-pressed="store.view === 'journal'" title="Every request that was made, traced or not" @click="store.showView('journal')">Journal</button>
  </div>
</template>
