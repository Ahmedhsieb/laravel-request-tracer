import { reactive } from 'vue'
import type { SpanType } from '../api/types'

/** A step of the open trace, as the command palette lists it. */
export interface PaletteStep {
  id: number
  label: string
  hint: string
  type: SpanType
}

/**
 * Small pieces of interface state that several screens share and that are not data: which overlays are open, and what the open request
 * offers to the command palette (its steps, and how to jump to one). The request data itself stays in the store.
 */
export const ui = reactive({
  palette: false,
  record: false,
  steps: [] as PaletteStep[],
  reveal: null as ((id: number) => void) | null,
})
