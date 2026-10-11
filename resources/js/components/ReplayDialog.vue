<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { emptyForm, inputFromForm, type ReplayForm } from '../lib/replay'
import { useStore } from '../store'

const store = useStore()
const initial = store.replayInitial
const form = ref<ReplayForm>(initial === null ? emptyForm() : { ...initial })
const mode = ref<'curl' | 'describe'>(initial === null ? 'curl' : 'describe')
const curl = ref('')
const busy = ref(false)
const problem = ref<string | null>(null)
const dialogEl = ref<HTMLElement | null>(null)

const canRun = computed(() => !busy.value && (mode.value === 'curl' ? curl.value.trim() !== '' : form.value.url.trim() !== ''))

async function run(): Promise<void> {
  busy.value = true
  problem.value = null
  try {
    const input = mode.value === 'curl'
      ? { curl: curl.value.trim(), ...(form.value.asUser.trim() !== '' ? { as_user: form.value.asUser.trim() } : {}) }
      : inputFromForm(form.value)
    const result = await store.api.replay(input)
    if (result.error !== null || result.trace_id === null) {
      problem.value = result.error ?? 'The request ran, but no trace was stored.'
      return
    }
    store.closeReplay()
    await store.reload()
    store.open(result.trace_id)
    store.toast(`Replayed: it answered ${result.status ?? '?'}. Database changes were rolled back.`)
  } catch (e) {
    problem.value = e instanceof Error ? e.message : String(e)
  } finally {
    busy.value = false
  }
}

function onKey(event: KeyboardEvent): void {
  if (event.key === 'Escape' && !busy.value) store.closeReplay()
}

onMounted(() => {
  document.addEventListener('keydown', onKey)
  dialogEl.value?.querySelector<HTMLElement>('textarea, input')?.focus()
})
onBeforeUnmount(() => document.removeEventListener('keydown', onKey))
</script>

<template>
  <div class="overlay" @click.self="!busy && store.closeReplay()">
    <div ref="dialogEl" class="dialog" role="dialog" aria-modal="true" aria-labelledby="replay-title">
      <h2 id="replay-title">Replay a request</h2>
      <p class="muted" style="margin: 0">
        Runs a request that already happened again, and traces it from the first line. <strong>Database changes are rolled back</strong>,
        e-mails and queued jobs are not sent. Calls to other services (payments, APIs) are real.
      </p>

      <div class="segmented" role="group" aria-label="How to describe the request" style="margin: 0">
        <button type="button" :aria-pressed="mode === 'curl'" @click="mode = 'curl'">Paste cURL</button>
        <button type="button" :aria-pressed="mode === 'describe'" @click="mode = 'describe'">Describe it</button>
      </div>

      <form @submit.prevent="run">
        <template v-if="mode === 'curl'">
          <label>
            A cURL command (browser: Network tab, right-click the request, <em>Copy → Copy as cURL (bash)</em>)
            <textarea v-model="curl" class="field mono" rows="6" spellcheck="false" placeholder="curl 'https://shop.test/api/orders' -H 'content-type: application/json' --data-raw '{&quot;items&quot;:[1]}'" style="padding: 10px"></textarea>
          </label>
        </template>

        <template v-else>
          <div class="row">
            <label style="flex: 0 0 120px">Method
              <select v-model="form.method" class="field">
                <option v-for="m in ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']" :key="m">{{ m }}</option>
              </select>
            </label>
            <label>URL or path
              <input v-model="form.url" class="field" type="text" spellcheck="false" placeholder="/api/orders?page=2" required>
            </label>
          </div>
          <label>Headers (one “Name: value” per line)
            <textarea v-model="form.headers" class="field mono" rows="3" spellcheck="false" style="padding: 10px"></textarea>
          </label>
          <label>Body (JSON or form data)
            <textarea v-model="form.body" class="field mono" rows="6" spellcheck="false" style="padding: 10px"></textarea>
          </label>
          <p v-if="form.bodyCut" class="tone-warn" role="status" style="margin: 0">
            The body of this request was too large to keep, so it is empty here: paste it if you have it.
          </p>
          <p v-if="form.masked" class="tone-warn" role="status" style="margin: 0">
            Some values of this request were masked when it was noted (passwords, tokens). They are shown as <code>••••••••</code> and would be sent like that:
            replace them before you run it.
          </p>
        </template>

        <label>Act as the user with this id (optional, no login needed)
          <input v-model="form.asUser" class="field" type="text" autocomplete="off" placeholder="42">
        </label>

        <p v-if="problem" class="tone-bad" role="alert" style="margin: 0">{{ problem }}</p>

        <div class="row">
          <button class="btn primary" type="submit" :disabled="!canRun">{{ busy ? 'Running…' : 'Replay it' }}</button>
          <button class="btn" type="button" :disabled="busy" @click="store.closeReplay()">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</template>
