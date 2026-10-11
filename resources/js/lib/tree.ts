import type { SpanRow } from '../api/types'

export interface TreeNode {
  span: SpanRow
  depth: number
  children: TreeNode[]
  parent: TreeNode | null
  /** time spent in the step itself: its duration minus its children's */
  selfUs: number
  /** number of steps below this one */
  descendants: number
  /** is this the last child of its parent (for the elbow connector) */
  last: boolean
}

export interface Row {
  node: TreeNode
  /** for each ancestor level: is a vertical connector line still needed to the left? */
  guides: boolean[]
}

/** Branches below this depth start collapsed. */
export const DEFAULT_EXPANDED_DEPTH = 2

export function buildTree(spans: SpanRow[]): TreeNode[] {
  const byId = new Map<number, TreeNode>()
  for (const span of spans) {
    byId.set(span.id, { span, depth: 0, children: [], parent: null, selfUs: span.duration_us, descendants: 0, last: false })
  }

  const roots: TreeNode[] = []
  for (const node of byId.values()) {
    const parent = node.span.parent_id === null ? undefined : byId.get(node.span.parent_id)
    if (parent === undefined) {
      roots.push(node)
    } else {
      node.parent = parent
      parent.children.push(node)
    }
  }

  const finish = (node: TreeNode, depth: number): number => {
    node.depth = depth
    let below = 0
    let childTime = 0
    node.children.forEach((child, i) => {
      child.last = i === node.children.length - 1
      childTime += child.span.duration_us
      below += 1 + finish(child, depth + 1)
    })
    node.selfUs = Math.max(0, node.span.duration_us - childTime)
    node.descendants = below
    return below
  }
  roots.forEach((root, i) => {
    root.last = i === roots.length - 1
    finish(root, 0)
  })

  return roots
}

/** Steps with a depth below DEFAULT_EXPANDED_DEPTH are open, deeper branches are collapsed. */
export function defaultExpanded(roots: TreeNode[]): Set<number> {
  const open = new Set<number>()
  const visit = (node: TreeNode): void => {
    if (node.depth < DEFAULT_EXPANDED_DEPTH && node.children.length > 0) open.add(node.span.id)
    node.children.forEach(visit)
  }
  roots.forEach(visit)
  return open
}

/** Flatten the visible part of the tree (for the virtual scroller). */
export function visibleRows(roots: TreeNode[], expanded: ReadonlySet<number>, forced: ReadonlySet<number> | null = null): Row[] {
  const rows: Row[] = []
  const walk = (node: TreeNode, guides: boolean[]): void => {
    if (forced !== null && !forced.has(node.span.id)) return
    rows.push({ node, guides })
    const open = forced !== null ? node.children.some((c) => forced.has(c.span.id)) : expanded.has(node.span.id)
    if (!open) return
    node.children.forEach((child) => walk(child, [...guides, !node.last]))
  }
  roots.forEach((root) => walk(root, []))
  return rows
}

/** The span itself plus all its ancestors (used to open the tree down to a step). */
export function ancestorIds(roots: TreeNode[], id: number): number[] {
  const found: number[] = []
  const visit = (node: TreeNode): boolean => {
    if (node.span.id === id) return true
    for (const child of node.children) {
      if (visit(child)) {
        found.push(node.span.id)
        return true
      }
    }
    return false
  }
  roots.some(visit)
  return found
}

/** Ids of the steps whose label, file or summary contains the query, plus all their ancestors (so they stay reachable). */
export function searchIds(roots: TreeNode[], query: string): Set<number> | null {
  const q = query.trim().toLowerCase()
  if (q === '') return null
  const keep = new Set<number>()
  const visit = (node: TreeNode): boolean => {
    const s = node.span
    const hay = `${s.label} ${s.file ?? ''} ${String(s.meta?.['summary'] ?? '')} ${s.connection ?? ''} ${s.type}`.toLowerCase()
    let any = hay.includes(q)
    for (const child of node.children) any = visit(child) || any
    if (any) keep.add(s.id)
    return any
  }
  roots.forEach(visit)
  return keep
}

export function findNode(roots: TreeNode[], id: number): TreeNode | null {
  let result: TreeNode | null = null
  const visit = (node: TreeNode): void => {
    if (result !== null) return
    if (node.span.id === id) result = node
    else node.children.forEach(visit)
  }
  roots.forEach(visit)
  return result
}
