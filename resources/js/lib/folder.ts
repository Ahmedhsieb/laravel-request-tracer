import type { SpanType } from '../api/types'
import { isFiltering, matches, type StepFilter } from './filters'
import type { Range } from './timeline'
import type { TreeNode } from './tree'

/**
 * Steps as folders: a step that has steps inside it is a folder, the request is the top one. The flow shows one folder at a time (its
 * children, full names, no indentation), and the filters look at everything below the open folder or, when the scope is narrowed, at
 * its children only. Pure functions: the open folder and the filters are state of the component, this is what they mean.
 */

/** One line of the open folder. */
export interface Entry {
  node: TreeNode
  /** the folders between the open one and this step (empty for a direct child), shown as the path of a match found deeper down */
  trail: TreeNode[]
  /** true when the step itself matches the filter (always true while nothing is filtered) */
  matched: boolean
}

export interface FolderList {
  entries: Entry[]
  /** how many steps the filter looks at (everything below the folder, or its children) */
  scope: number
  /** how many of them match (the number of entries while filtering) */
  matches: number
  filtering: boolean
  /** matches found anywhere below are listed flat, each with its trail, instead of as the folder's own children */
  flat: boolean
}

/** The path from the top of the request down to a step, the step included. */
export function crumbs(node: TreeNode | null): TreeNode[] {
  const path: TreeNode[] = []
  for (let n = node; n !== null; n = n.parent) path.unshift(n)

  return path
}

/** All steps below a folder (null is the top: every root and everything below). */
export function below(roots: TreeNode[], folder: TreeNode | null): TreeNode[] {
  const out: TreeNode[] = []
  const visit = (node: TreeNode): void => {
    out.push(node)
    node.children.forEach(visit)
  }
  ;(folder === null ? roots : folder.children).forEach(visit)

  return out
}

/** The steps a filter looks at: everything below the folder, or only its children. */
export function scopeNodes(roots: TreeNode[], folder: TreeNode | null, include: boolean): TreeNode[] {
  return include ? below(roots, folder) : (folder === null ? roots : folder.children)
}

export function listFolder(roots: TreeNode[], folder: TreeNode | null, filter: StepFilter, slowMs: number, include: boolean): FolderList {
  const kids = folder === null ? roots : folder.children
  const filtering = isFiltering(filter)
  const scope = include ? below(roots, folder).length : kids.length

  if (!filtering) {
    return { entries: kids.map((node) => ({ node, trail: [], matched: true })), scope, matches: kids.length, filtering, flat: false }
  }

  if (!include) {
    const entries = kids.filter((n) => matches(n.span, filter, slowMs)).map((node): Entry => ({ node, trail: [], matched: true }))

    return { entries, scope, matches: entries.length, filtering, flat: false }
  }

  const entries: Entry[] = []
  const visit = (node: TreeNode, trail: TreeNode[]): void => {
    if (matches(node.span, filter, slowMs)) entries.push({ node, trail, matched: true })
    const next = [...trail, node]
    node.children.forEach((child) => visit(child, next))
  }
  kids.forEach((kid) => visit(kid, []))

  return { entries, scope, matches: entries.length, filtering, flat: true }
}

/** The kinds of step that occur in the scope, with how many steps each has, in the given display order. */
export function scopeTypeCounts(nodes: TreeNode[], order: SpanType[]): Array<{ type: SpanType; count: number }> {
  const counts = new Map<SpanType, number>()
  for (const node of nodes) counts.set(node.span.type, (counts.get(node.span.type) ?? 0) + 1)

  return order.filter((type) => counts.has(type)).map((type) => ({ type, count: counts.get(type) ?? 0 }))
}

/** The time axis for a folder: the folder's own stretch of time with a little air around it (the whole request for the top). */
export function folderRange(folder: TreeNode | null, totalUs: number): Range {
  if (folder === null || folder.parent === null) return { from: 0, to: totalUs }
  const start = folder.span.start_us
  const length = Math.max(folder.span.duration_us, 1000)
  const pad = length * 0.03

  return { from: Math.max(0, start - pad), to: Math.min(totalUs, start + length + pad) }
}

/** Every step with children has a folder, a leaf is a plain row. */
export function isFolder(node: TreeNode): boolean {
  return node.children.length > 0
}

/** How many of a folder's direct children are folders themselves (for the breadcrumb and the folder's summary). */
export function folderCount(node: TreeNode | null, roots: TreeNode[]): number {
  return (node === null ? roots : node.children).filter(isFolder).length
}

/**
 * The steps that "light up" for a filter: those that match it and, when the filter looks inside (`include`), the steps that have a match
 * below them, so a path to a match stays visible. Everything is lit while nothing is filtered. One pass over the trace.
 */
export function litSteps(roots: TreeNode[], filter: StepFilter, slowMs: number, include: boolean): Set<number> | null {
  if (!isFiltering(filter)) return null
  const lit = new Set<number>()
  const visit = (node: TreeNode): boolean => {
    let any = matches(node.span, filter, slowMs)
    let below = false
    for (const child of node.children) below = visit(child) || below
    if (any || (include && below)) lit.add(node.span.id)
    any = any || below

    return any
  }
  roots.forEach(visit)

  return lit
}

/** The columns of the graph for a step: the children of each step on the way down to it, the last ones that fit (`count`), and the path left behind. */
export interface GraphColumn {
  /** the step whose children these are */
  parent: TreeNode | null
  nodes: TreeNode[]
  /** the one of them the path goes through (null in the last column) */
  selected: TreeNode | null
}

export function graphColumns(roots: TreeNode[], focus: TreeNode | null, count: number): { columns: GraphColumn[]; rail: TreeNode[] } {
  const path = crumbs(focus)
  const all: GraphColumn[] = []
  if (path.length === 0) {
    all.push({ parent: null, nodes: roots, selected: null })
  } else {
    path.forEach((step, i) => {
      if (step.children.length > 0) all.push({ parent: step, nodes: step.children, selected: path[i + 1] ?? null })
    })
  }
  const columns = all.slice(Math.max(0, all.length - Math.max(1, count)))
  const first = columns[0]?.parent ?? null

  return { columns, rail: first === null ? [] : crumbs(first) }
}
