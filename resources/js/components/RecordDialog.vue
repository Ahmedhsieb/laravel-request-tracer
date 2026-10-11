<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import type { IssuedToken, WatchEntry } from '../api/types'
import { useStore } from '../store'

const emit = defineEmits<{ close: [] }>()
const store = useStore()

const ttl = ref(300)
const userId = ref('')
const route = ref('')
const issued = ref<IssuedToken | null>(null)
const watch = ref<WatchEntry[]>([])
const watchUser = ref('')
const watchMinutes = ref(30)
const busy = ref(false)
const problem = ref<string | null>(null)
const dialogEl = ref<HTMLElement | null>(null)

function message(e: unknown): string {
  return e instanceof Error ? e.message : String(e)
}

async function issue(): Promise<void> {
  busy.value = true
  problem.value = null
  try {
    issued.value = await store.api.issueToken({
      ttl: ttl.value,
      user_id: userId.value.trim() === '' ? undefined : userId.value.trim(),
      route: route.value.trim() === '' ? undefined : route.value.trim(),
    })
  } catch (e) {
    problem.value = message(e)
  } finally {
    busy.value = false
  }
}

async function addWatch(): Promise<void> {
  if (watchUser.value.trim() === '') return
  try {
    watch.value = await store.api.watch(watchUser.value.trim(), watchMinutes.value)
    watchUser.value = ''
  } catch (e) {
    problem.value = message(e)
  }
}

async function removeWatch(id: string): Promise<void> {
  try {
    watch.value = await store.api.unwatch(id)
  } catch (e) {
    problem.value = message(e)
  }
}

async function copy(text: string): Promise<void> {
  try {
    await navigator.clipboard.writeText(text)
    store.toast('Copied')
  } catch {
    store.toast('Copy failed: select the text and copy it by hand')
  }
}

function until(epoch: number): string {
  return new Date(epoch * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

function onKey(event: KeyboardEvent): void {
  if (event.key === 'Escape') emit('close')
}

onMounted(async () => {
  document.addEventListener('keydown', onKey)
  dialogEl.value?.querySelector<HTMLElement>('input, button')?.focus()
  try {
    watch.value = await store.api.watchList()
  } catch {
    // the watch list is optional (it can be disabled in the config)
  }
})
onBeforeUnmount(() => document.removeEventListener('keydown', onKey))
</script>

<template>
  <div class="overlay" @click.self="emit('close')">
    <div ref="dialogEl" class="dialog" role="dialog" aria-modal="true" aria-labelledby="record-title">
      <h2 id="record-title">Record a request</h2>
      <p class="muted" style="margin: 0">
        In production nothing is recorded by default. Either send one request with a signed header, or watch a user for a while.
      </p>

      <form @submit.prevent="issue">
        <strong>Signed header (one request, expires)</strong>
        <div class="row">
          <label>Valid for (seconds)<input v-model.number="ttl" class="field" type="number" min="10" max="3600" required></label>
          <label>Only this user id (optional)<input v-model="userId" class="field" type="text" autocomplete="off"></label>
          <label>Only this route (optional)<input v-model="route" class="field" type="text" placeholder="orders.*" autocomplete="off"></label>
        </div>
        <div><button class="btn primary" type="submit" :disabled="busy">Create header</button></div>
      </form>

      <div v-if="issued" class="cards">
        <div>
          <div class="muted" style="margin-bottom: 4px">Send this header ({{ issued.header }}) with your request. It expires at {{ until(issued.expires_at) }}.</div>
          <div class="token mono">{{ issued.token }}</div>
        </div>
        <div>
          <div class="muted" style="margin-bottom: 4px">Example</div>
          <div class="token mono">{{ issued.example }}</div>
        </div>
        <div class="row">
          <button class="btn" type="button" @click="copy(issued.token)">Copy header value</button>
          <button class="btn" type="button" @click="copy(issued.example)">Copy example</button>
        </div>
      </div>

      <section v-if="store.meta?.recording.watch_list">
        <strong>Watch a user</strong>
        <p class="muted" style="margin: 4px 0 8px">Every request of that user is recorded until the time is up.</p>
        <form class="row" @submit.prevent="addWatch">
          <label>User id<input v-model="watchUser" class="field" type="text" autocomplete="off" required></label>
          <label>Minutes<input v-model.number="watchMinutes" class="field" type="number" min="1" max="60"></label>
          <button class="btn" type="submit">Watch</button>
        </form>
        <ul v-if="watch.length > 0" class="watch" style="margin-top: 10px">
          <li v-for="w in watch" :key="w.user_id">
            <span>User <strong>{{ w.user_id }}</strong> until {{ until(w.expires_at) }}</span>
            <button class="btn small ghost" type="button" @click="removeWatch(w.user_id)">Stop</button>
          </li>
        </ul>
      </section>

      <p v-if="problem" class="tone-bad" role="alert" style="margin: 0">{{ problem }}</p>

      <div><button class="btn" type="button" @click="emit('close')">Close</button></div>
    </div>
  </div>
</template>
