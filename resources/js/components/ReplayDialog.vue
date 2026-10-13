<script setup lang="ts">
import { AlertTriangle, Check, FlaskConical, Play, Zap } from 'lucide-vue-next'
import { CheckboxIndicator, CheckboxRoot, RadioGroupItem, RadioGroupRoot } from 'reka-ui'
import { computed, ref, watch } from 'vue'
import { emptyForm, inputFromForm, type ReplayForm } from '../lib/replay'
import { useStore } from '../store'
import Btn from '../ui/Btn.vue'
import Modal from '../ui/Modal.vue'
import Pick from '../ui/Pick.vue'
import Seg from '../ui/Seg.vue'

const store = useStore()

const open = computed({
  get: () => store.replayOpen,
  set: (value: boolean) => {
    if (!value) store.closeReplay()
  },
})

const form = ref<ReplayForm>(emptyForm())
const how = ref<'curl' | 'describe'>('curl')
const curl = ref('')
/** simulate = nothing is changed; real = the request does what it does (and what can be undone is recorded) */
const effect = ref<'simulate' | 'real'>('simulate')
const confirmReal = ref(false)
const busy = ref(false)
const problem = ref<string | null>(null)
const realAllowed = computed(() => store.meta?.replay.real === true)

// every time the dialog opens it starts from what it was opened with: a recorded request, or nothing
watch(open, (isOpen) => {
  if (!isOpen) return
  const initial = store.replayInitial
  form.value = initial === null ? emptyForm() : { ...initial }
  how.value = initial === null ? 'curl' : 'describe'
  curl.value = ''
  effect.value = 'simulate'
  confirmReal.value = false
  problem.value = null
})

const canRun = computed(() => !busy.value
  && (how.value === 'curl' ? curl.value.trim() !== '' : form.value.url.trim() !== '')
  && (effect.value === 'simulate' || confirmReal.value))

const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'].map((m) => ({ value: m, label: m }))
const method = computed({ get: () => form.value.method, set: (v: string) => { form.value.method = v } })

async function run(): Promise<void> {
  busy.value = true
  problem.value = null
  try {
    const base = how.value === 'curl'
      ? { curl: curl.value.trim(), ...(form.value.asUser.trim() !== '' ? { as_user: form.value.asUser.trim() } : {}) }
      : inputFromForm(form.value)
    const input = effect.value === 'real' ? { ...base, mode: 'real' as const, confirm_real: true } : { ...base, mode: 'simulate' as const }
    const result = await store.api.replay(input)
    if (result.error !== null || result.trace_id === null) {
      problem.value = result.error ?? 'The request ran, but no trace was stored.'
      return
    }
    store.closeReplay()
    await store.reload()
    store.open(result.trace_id)
    store.toast(effect.value === 'real'
      ? `Ran for real: it answered ${result.status ?? '?'}. Its changes were kept${result.undo?.available === true ? ': open the trace to undo them.' : '.'}`
      : `Simulated: it answered ${result.status ?? '?'}. Nothing was changed.`)
  } catch (e) {
    problem.value = e instanceof Error ? e.message : String(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Modal v-model:open="open" title="Replay a request" description="Run a request that already happened again, traced from the first line. You choose what it may change." :width="680" :busy="busy">
    <form id="replay-form" class="space-y-4" @submit.prevent="run">
      <RadioGroupRoot v-model="effect" class="grid gap-2.5 sm:grid-cols-2" aria-label="What the replay may change">
        <RadioGroupItem
          value="simulate"
          class="group relative rounded-xl border border-line bg-raised/60 p-3.5 text-left outline-none transition-all hover:border-line-strong data-[state=checked]:border-accent data-[state=checked]:bg-accent/[.07] data-[state=checked]:shadow-[0_0_0_3px_color-mix(in_srgb,var(--accent)_16%,transparent)]"
        >
          <span class="flex items-center gap-2 font-semibold"><FlaskConical class="size-4 text-accent-ink" />Simulation</span>
          <span class="mt-0.5 block text-[11.5px] font-medium text-accent-ink">nothing is changed</span>
          <span class="mt-1.5 block text-[11.5px] leading-relaxed text-muted">Database changes are rolled back. E-mails, notifications, jobs and broadcasts do not go out, calls made with Laravel's HTTP client get an empty answer, the cache stays in memory. Not covered: files, and calls an SDK or Guzzle makes by itself.</span>
        </RadioGroupItem>
        <RadioGroupItem
          value="real"
          :disabled="!realAllowed"
          class="group relative rounded-xl border border-line bg-raised/60 p-3.5 text-left outline-none transition-all hover:border-line-strong disabled:opacity-50 data-[state=checked]:border-bad data-[state=checked]:bg-bad/[.06] data-[state=checked]:shadow-[0_0_0_3px_color-mix(in_srgb,var(--bad)_16%,transparent)]"
        >
          <span class="flex items-center gap-2 font-semibold"><Zap class="size-4 text-bad" />Run for real</span>
          <span class="mt-0.5 block text-[11.5px] font-medium text-bad">affects data, can be undone</span>
          <span v-if="realAllowed" class="mt-1.5 block text-[11.5px] leading-relaxed text-muted">The request does what it does: rows are written and kept, e-mails and jobs go out, other services are called. Every Eloquent write is recorded, so you can undo it from the trace for {{ store.meta?.replay.undo_hours ?? 24 }} h. Query-builder and raw SQL writes, e-mails, calls and files cannot be undone.</span>
          <span v-else class="mt-1.5 block text-[11.5px] text-muted">Switched off in this environment (<code>request-tracer.replay.real</code>).</span>
        </RadioGroupItem>
      </RadioGroupRoot>

      <label v-if="effect === 'real'" class="flex cursor-pointer items-start gap-2.5 rounded-xl border border-bad/40 bg-bad/[.07] p-3 text-[12.5px] text-bad">
        <CheckboxRoot v-model="confirmReal" class="mt-0.5 grid size-4 shrink-0 place-items-center rounded border border-bad/70 bg-transparent outline-none data-[state=checked]:bg-bad">
          <CheckboxIndicator><Check class="size-3 text-white" /></CheckboxIndicator>
        </CheckboxRoot>
        <span class="flex items-start gap-1.5"><AlertTriangle class="mt-0.5 size-3.5 shrink-0" />I understand this changes real data{{ store.meta?.is_production ? ' in PRODUCTION' : '' }} and sends real e-mails and calls.</span>
      </label>

      <Seg v-model="how" label="How to describe the request" :options="[{ value: 'curl', label: 'Paste cURL' }, { value: 'describe', label: 'Describe it' }]" class="w-[260px]" />

      <template v-if="how === 'curl'">
        <label class="block space-y-1.5">
          <span class="text-[12px] text-muted">A cURL command (browser: Network tab, right-click the request, <em>Copy → Copy as cURL (bash)</em>)</span>
          <textarea v-model="curl" class="input font-mono text-[12px]" rows="6" spellcheck="false" placeholder="curl 'https://shop.test/api/orders' -H 'content-type: application/json' --data-raw '{&quot;items&quot;:[1]}'"></textarea>
        </label>
      </template>

      <template v-else>
        <div class="flex gap-2.5">
          <div class="w-[120px] shrink-0 space-y-1.5">
            <span class="text-[12px] text-muted">Method</span>
            <Pick v-model="method" label="Method" :options="METHODS" class="w-full" />
          </div>
          <label class="min-w-0 flex-1 space-y-1.5">
            <span class="text-[12px] text-muted">URL or path</span>
            <input v-model="form.url" class="input font-mono text-[12.5px]" type="text" spellcheck="false" placeholder="/api/orders?page=2" required>
          </label>
        </div>
        <label class="block space-y-1.5">
          <span class="text-[12px] text-muted">Headers (one “Name: value” per line)</span>
          <textarea v-model="form.headers" class="input font-mono text-[12px]" rows="3" spellcheck="false"></textarea>
        </label>
        <label class="block space-y-1.5">
          <span class="text-[12px] text-muted">Body (JSON or form data)</span>
          <textarea v-model="form.body" class="input font-mono text-[12px]" rows="6" spellcheck="false"></textarea>
        </label>
        <p v-if="form.bodyCut" class="rounded-lg border border-warn/40 bg-warn/10 px-3 py-2 text-[12px] text-warn" role="status">The body of this request was too large to keep, so it is empty here: paste it if you have it.</p>
        <p v-if="form.masked" class="rounded-lg border border-warn/40 bg-warn/10 px-3 py-2 text-[12px] text-warn" role="status">
          Some values of this request were masked when it was noted (passwords, tokens). They are shown as <code>••••••••</code> and would be sent like that: replace them before you run it.
        </p>
      </template>

      <label class="block space-y-1.5">
        <span class="text-[12px] text-muted">Act as the user with this id (optional, no login needed)</span>
        <input v-model="form.asUser" class="input" type="text" autocomplete="off" placeholder="42">
      </label>

      <p v-if="problem" class="rounded-lg border border-bad/40 bg-bad/10 px-3 py-2 text-[12.5px] text-bad" role="alert">{{ problem }}</p>
    </form>

    <template #footer>
      <Btn :variant="effect === 'real' ? 'danger' : 'primary'" size="md" type="submit" :disabled="!canRun" @click="run">
        <Play class="size-3.5" />{{ busy ? 'Running…' : effect === 'real' ? 'Run it for real' : 'Simulate it' }}
      </Btn>
      <Btn variant="ghost" size="md" :disabled="busy" @click="open = false">Cancel</Btn>
    </template>
  </Modal>
</template>
