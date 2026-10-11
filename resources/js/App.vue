<script setup lang="ts">
import { useStore } from './store'
import AppHeader from './components/AppHeader.vue'
import JournalList from './components/JournalList.vue'
import ListTabs from './components/ListTabs.vue'
import RequestList from './components/RequestList.vue'
import ReplayDialog from './components/ReplayDialog.vue'
import TraceView from './components/TraceView.vue'

const store = useStore()
</script>

<template>
  <AppHeader />
  <main class="shell" :class="{ selected: store.selected !== null }">
    <aside class="list-pane" aria-label="Recorded requests">
      <ListTabs v-if="store.meta?.journal.enabled" />
      <JournalList v-if="store.view === 'journal' && store.meta?.journal.enabled" />
      <RequestList v-else />
    </aside>
    <section class="detail-pane" aria-live="polite">
      <TraceView v-if="store.selected !== null" :key="store.selected" :trace-id="store.selected" />
      <div v-else class="empty">
        <p><strong>Pick a request on the left.</strong></p>
        <p>Every recorded request shows its steps, what went in and out of each one, the tables it wrote and what went wrong.</p>
        <p v-if="store.meta" class="muted">
          Code looked into: <code>{{ store.meta.code.paths.join(', ') || 'nothing (code.paths is empty)' }}</code>
          <template v-if="store.meta.code.except.length > 0"> except <code>{{ store.meta.code.except.join(', ') }}</code></template>.
          <template v-if="!store.meta.code.line_by_line"> Line by line is switched off.</template>
        </p>
      </div>
    </section>
  </main>
  <ReplayDialog v-if="store.replayOpen" />
  <div v-if="store.toastText !== null" class="toast" role="status">{{ store.toastText }}</div>
</template>
