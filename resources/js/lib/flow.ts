import { computed, ref, watch, type Ref } from 'vue'
import type { SpanType } from '../api/types'
import type { ResultFilter, SpeedFilter, StepFilter } from './filters'
import { crumbs, listFolder, scopeNodes, scopeTypeCounts } from './folder'
import { TYPE_ORDER } from './spans'
import type { TreeNode } from './tree'

export type FlowView = 'waterfall' | 'graph'

const VIEW_KEY = 'request-tracer:flow-view'

function savedView(): FlowView {
  try {
    const saved = window.localStorage.getItem(VIEW_KEY)
    if (saved === 'waterfall' || saved === 'graph') return saved
  } catch {
    // not remembered
  }

  return 'waterfall'
}

/**
 * The state of the Flow tab that every way of looking at it shares: which folder is open, what the filters are, and which view is on.
 * The waterfall and the graph both draw `list`, so switching view never loses the place or the filters.
 */
export function useFlow(roots: Ref<TreeNode[]>, slowMs: Ref<number>) {
  const folderId = ref<number | null>(null)
  const query = ref('')
  const types = ref<Set<SpanType>>(new Set())
  const speed = ref<SpeedFilter>('all')
  const result = ref<ResultFilter>('all')
  /** the filters look at everything below the open folder (true) or only at its own children (false) */
  const include = ref(true)
  const view = ref<FlowView>(savedView())
  /** which way the last move went, so the list can slide the right way */
  const direction = ref<'in' | 'out'>('in')

  const index = computed(() => {
    const map = new Map<number, TreeNode>()
    const visit = (node: TreeNode): void => {
      map.set(node.span.id, node)
      node.children.forEach(visit)
    }
    roots.value.forEach(visit)

    return map
  })
  const nodeOf = (id: number): TreeNode | null => index.value.get(id) ?? null
  const folder = computed(() => (folderId.value === null ? null : (index.value.get(folderId.value) ?? null)))
  const path = computed(() => crumbs(folder.value))
  const filter = computed<StepFilter>(() => ({ query: query.value, types: types.value, speed: speed.value, result: result.value }))
  const list = computed(() => listFolder(roots.value, folder.value, filter.value, slowMs.value, include.value))
  const kinds = computed(() => scopeTypeCounts(scopeNodes(roots.value, folder.value, include.value), TYPE_ORDER))
  const activeFilters = computed(() => (speed.value !== 'all' ? 1 : 0) + (result.value !== 'all' ? 1 : 0))

  /** A new trace starts at its request: one root means that root is the open folder, several mean the top. */
  function reset(): void {
    folderId.value = roots.value.length === 1 ? (roots.value[0]?.span.id ?? null) : null
    clearFilters()
  }

  /** Open a folder (null is the top). A step that is not a folder opens its parent. */
  function open(id: number | null): void {
    const node = id === null ? null : (index.value.get(id) ?? null)
    const target = node !== null && node.children.length === 0 ? node.parent : node
    const next = target?.span.id ?? null
    if (next === folderId.value) return
    direction.value = (target?.depth ?? -1) >= (folder.value?.depth ?? -1) ? 'in' : 'out'
    folderId.value = next
  }

  function up(): void {
    const parent = folder.value?.parent ?? null
    if (folder.value === null) return
    open(parent?.span.id ?? null)
  }

  function toggleType(type: SpanType): void {
    const next = new Set(types.value)
    if (next.has(type)) next.delete(type)
    else next.add(type)
    types.value = next
  }

  function clearFilters(): void {
    query.value = ''
    speed.value = 'all'
    result.value = 'all'
    types.value = new Set()
  }

  /** Open the folder a step is in (the step itself when `inside` is set and it has steps in it) and clear the filters, so it is on screen. */
  function reveal(id: number, inside = false): void {
    clearFilters()
    const node = index.value.get(id)
    if (node === undefined) return
    open(inside && node.children.length > 0 ? id : (node.parent?.span.id ?? null))
  }

  watch(view, (value) => {
    try {
      window.localStorage.setItem(VIEW_KEY, value)
    } catch {
      // not remembered
    }
  })

  return { nodeOf, folderId, folder, path, query, types, speed, result, include, view, direction, filter, list, kinds, activeFilters, reset, open, up, toggleType, clearFilters, reveal }
}

export type Flow = ReturnType<typeof useFlow>
