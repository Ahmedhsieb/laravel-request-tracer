<script setup lang="ts">
import { useStore } from './store'
import AppHeader from './components/AppHeader.vue'
import RequestList from './components/RequestList.vue'
import TraceView from './components/TraceView.vue'

const store = useStore()
</script>

<template>
  <AppHeader />
  <main class="shell" :class="{ selected: store.selected !== null }">
    <aside class="list-pane" aria-label="Recorded requests">
      <RequestList />
    </aside>
    <section class="detail-pane" aria-live="polite">
      <TraceView v-if="store.selected !== null" :key="store.selected" :trace-id="store.selected" />
      <div v-else class="empty">
        <p><strong>Pick a request on the left.</strong></p>
        <p>Every recorded request shows its steps, what went in and out of each one, the tables it wrote and what went wrong.</p>
        <p v-if="store.meta?.mode === 'triggered'" class="muted">
          Recording is <strong>triggered</strong>: use “Record a request” above to get a signed header, then send a request with it.
        </p>
      </div>
    </section>
  </main>
  <div v-if="store.toastText !== null" class="toast" role="status">{{ store.toastText }}</div>
</template>
