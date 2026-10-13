<script setup lang="ts">
import { Clock, Copy, Eye, KeyRound, Trash2 } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'
import type { IssuedToken, WatchEntry } from '../api/types'
import { ui } from '../lib/ui'
import { useStore } from '../store'
import Btn from '../ui/Btn.vue'
import Modal from '../ui/Modal.vue'

const store = useStore()

const ttl = ref(300)
const userId = ref('')
const route = ref('')
const issued = ref<IssuedToken | null>(null)
const watchList = ref<WatchEntry[]>([])
const watchUser = ref('')
const watchMinutes = ref(30)
const busy = ref(false)
const problem = ref<string | null>(null)

const open = computed({
  get: () => ui.record,
  set: (value: boolean) => { ui.record = value },
})

function message(e: unknown): string {
  return e instanceof Error ? e.message : String(e)
}

watch(open, async (isOpen) => {
  if (!isOpen) return
  issued.value = null
  problem.value = null
  try {
    watchList.value = await store.api.watchList()
  } catch {
    // the watch list is optional (it can be disabled in the config)
  }
})

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
    watchList.value = await store.api.watch(watchUser.value.trim(), watchMinutes.value)
    watchUser.value = ''
  } catch (e) {
    problem.value = message(e)
  }
}

async function removeWatch(id: string): Promise<void> {
  try {
    watchList.value = await store.api.unwatch(id)
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
</script>

<template>
  <Modal v-model:open="open" title="Record a request" description="In production nothing is recorded by default. Either send one request with a signed header, or watch a user for a while." :width="620">
    <div class="space-y-6">
      <section>
        <h3 class="flex items-center gap-2 font-semibold"><KeyRound class="size-4 text-accent-ink" />Signed header <span class="text-[12px] font-normal text-muted">one request, it expires</span></h3>
        <form class="mt-3 grid gap-2.5 sm:grid-cols-3" @submit.prevent="issue">
          <label class="space-y-1.5"><span class="text-[12px] text-muted">Valid for (seconds)</span><input v-model.number="ttl" class="input" type="number" min="10" max="3600" required></label>
          <label class="space-y-1.5"><span class="text-[12px] text-muted">Only this user id</span><input v-model="userId" class="input" type="text" autocomplete="off" placeholder="optional"></label>
          <label class="space-y-1.5"><span class="text-[12px] text-muted">Only this route</span><input v-model="route" class="input" type="text" placeholder="orders.*" autocomplete="off"></label>
          <div class="sm:col-span-3"><Btn variant="primary" size="md" type="submit" :disabled="busy">Create header</Btn></div>
        </form>

        <div v-if="issued" class="mt-4 space-y-3 animate-pop-in">
          <div>
            <p class="mb-1 text-[12px] text-muted">Send this header (<code>{{ issued.header }}</code>) with your request. It expires at {{ until(issued.expires_at) }}.</p>
            <pre class="overflow-auto rounded-lg border border-line bg-surface p-3 text-[12px] leading-relaxed break-all whitespace-pre-wrap">{{ issued.token }}</pre>
          </div>
          <div>
            <p class="mb-1 text-[12px] text-muted">Example</p>
            <pre class="overflow-auto rounded-lg border border-line bg-surface p-3 text-[12px] leading-relaxed break-all whitespace-pre-wrap">{{ issued.example }}</pre>
          </div>
          <div class="flex gap-2">
            <Btn @click="copy(issued.token)"><Copy class="size-3.5" />Copy header value</Btn>
            <Btn @click="copy(issued.example)"><Copy class="size-3.5" />Copy example</Btn>
          </div>
        </div>
      </section>

      <section v-if="store.meta?.recording.watch_list" class="border-t border-line pt-5">
        <h3 class="flex items-center gap-2 font-semibold"><Eye class="size-4 text-accent-ink" />Watch a user</h3>
        <p class="mt-1 text-[12px] text-muted">Every request of that user is recorded until the time is up.</p>
        <form class="mt-3 flex items-end gap-2.5" @submit.prevent="addWatch">
          <label class="min-w-0 flex-1 space-y-1.5"><span class="text-[12px] text-muted">User id</span><input v-model="watchUser" class="input" type="text" autocomplete="off" required></label>
          <label class="w-24 space-y-1.5"><span class="text-[12px] text-muted">Minutes</span><input v-model.number="watchMinutes" class="input" type="number" min="1" max="60"></label>
          <Btn size="md" type="submit">Watch</Btn>
        </form>
        <ul v-if="watchList.length > 0" class="mt-3 divide-y divide-line overflow-hidden rounded-xl border border-line">
          <li v-for="w in watchList" :key="w.user_id" class="flex items-center gap-3 bg-raised/50 px-3 py-2">
            <span class="flex-1">User <strong>{{ w.user_id }}</strong> <span class="inline-flex items-center gap-1 text-muted"><Clock class="size-3" />until {{ until(w.expires_at) }}</span></span>
            <Btn variant="ghost" size="xs" @click="removeWatch(w.user_id)"><Trash2 class="size-3.5" />Stop</Btn>
          </li>
        </ul>
      </section>

      <p v-if="problem" class="rounded-lg border border-bad/40 bg-bad/10 px-3 py-2 text-[12.5px] text-bad" role="alert">{{ problem }}</p>
    </div>
  </Modal>
</template>
