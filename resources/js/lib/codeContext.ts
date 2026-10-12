import type { ComputedRef, InjectionKey, Ref } from 'vue'
import type { CodeBlock, CodeLine, UntracedReason } from './code'
import type { TreeNode } from './tree'

/** What a function offers under one of its lines: another function that ran, or a step (a service, a job...) that has code of its own. */
export type Edge =
  | { kind: 'block'; target: number }
  | { kind: 'span'; id: number; name: string; node: TreeNode }

/** The one place the output of a line is shown: a pop-up next to the code, owned by the outermost code view. */
export interface LinePopoverApi {
  /** which line is shown: `<owner>:<block>:<line>` */
  activeKey: Ref<string | null>
  pinned: Ref<boolean>
  show: (key: string, line: CodeLine, element: HTMLElement, pin: boolean) => void
  hide: () => void
  release: () => void
}

export interface CodeContext {
  uid: number
  traceId: string
  blocks: ComputedRef<CodeBlock[]>
  /** the functions that are open (their lines are shown) */
  open: Ref<ReadonlySet<number>>
  main: ComputedRef<number>
  edges: ComputedRef<ReadonlyMap<number, ReadonlyMap<number, Edge[]>>>
  /** why the step's own function has no run data, null when it has some */
  untraced: ComputedRef<UntracedReason | null>
  /** block index -> the block it was reached from */
  parent: ComputedRef<ReadonlyMap<number, number | null>>
  toggle: (block: number) => void
  popover: LinePopoverApi
}

export const CODE_CONTEXT: InjectionKey<CodeContext> = Symbol('request-tracer-code')
export const CODE_POPOVER: InjectionKey<LinePopoverApi> = Symbol('request-tracer-code-popover')
