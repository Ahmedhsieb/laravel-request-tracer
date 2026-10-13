import { createApp } from 'vue'
import App from './App.vue'
import { HttpApi } from './api/client'
import { DEMO_META, DemoApi } from './api/demo'
import type { Api, Meta } from './api/types'
import { STORE_KEY, createStore } from './store'
import 'vue-sonner/style.css'
import './styles/app.css'

/**
 * Real mode: the Blade view put `window.__REQUEST_TRACER__` on the page (API url, CSRF token, first meta).
 * Demo mode: no bootstrap object (the Vite sandbox, `npm run dev`) or `?demo=1` in the URL: seeded data, nothing leaves the browser.
 */
const bootstrap = window.__REQUEST_TRACER__
const demo = bootstrap === undefined || new URLSearchParams(window.location.search).get('demo') === '1'

let api: Api
let basePath: string
let meta: Meta | null

if (demo || bootstrap === undefined) {
  api = new DemoApi()
  basePath = bootstrap?.basePath ?? ''
  meta = DEMO_META
} else {
  api = new HttpApi(bootstrap.apiUrl, bootstrap.csrf, bootstrap.meta)
  basePath = bootstrap.basePath
  meta = bootstrap.meta
}

const store = createStore(api, basePath, meta)
const app = createApp(App)
app.provide(STORE_KEY, store)
app.mount('#app')
store.start()
