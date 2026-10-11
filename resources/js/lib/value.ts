import type { Dump } from '../api/types'
import { shortClass } from './format'

export function isTagged(node: Dump): node is Exclude<Dump, null | boolean | number | string> {
  return typeof node === 'object' && node !== null && '$' in node
}

/** A compact one-line rendering ("Order #7", "array (3)", "'text'") for chips and headers. */
export function inline(node: Dump, max = 60): string {
  return clip(inlineRaw(node), max)
}

function inlineRaw(node: Dump): string {
  if (node === null) return 'null'
  if (typeof node === 'boolean') return node ? 'true' : 'false'
  if (typeof node === 'number') return String(node)
  if (typeof node === 'string') return `'${node}'`
  switch (node.$) {
    case 'str': return `'${node.v}…'`
    case 'mask': return '••••••••'
    case 'type': return node.t + (node.size !== undefined ? ` (${node.size})` : '')
    case 'bin': return `binary (${node.len} B)`
    case 'float': return node.v
    case 'int': return node.v
    case 'arr': return `array (${node.n})`
    case 'obj': {
      if (node.kind === 'model') {
        const key = node.i?.find((p) => p[0] === 'key')?.[2]
        return `${shortClass(node.c)}${key !== undefined && key !== null && typeof key !== 'object' ? ` #${key}` : ''}`
      }
      if (node.kind === 'collection') {
        const count = node.i?.find((p) => p[0] === 'count')?.[2]
        return `Collection (${typeof count === 'number' ? count : '?'})`
      }
      if (node.kind === 'exception') {
        const message = node.i?.find((p) => p[0] === 'message')?.[2]
        return `${shortClass(node.c)}${typeof message === 'string' ? `: ${message}` : ''}`
      }
      return shortClass(node.c)
    }
  }
}

/** True when the value (or anything inside it) was masked / shortened by the server. */
export function flags(node: Dump): { masked: boolean; truncated: boolean } {
  const out = { masked: false, truncated: false }
  const walk = (n: Dump): void => {
    if (n === null || typeof n !== 'object') return
    switch (n.$) {
      case 'mask': out.masked = true; return
      case 'str': out.truncated = true; return
      case 'arr':
        if ((n.cut ?? 0) > 0) out.truncated = true
        for (const item of n.i ?? []) walk(Array.isArray(item) ? (item[1] as Dump) : (item as Dump))
        return
      case 'obj':
        if ((n.cut ?? 0) > 0) out.truncated = true
        for (const prop of n.i ?? []) walk(prop[2])
        return
      case 'type':
        out.truncated = out.truncated || n.size !== undefined
        return
      default:
    }
  }
  walk(node)
  return out
}

/** Plain JavaScript value for copy-to-clipboard (masked values stay masked). */
export function toPlain(node: Dump): unknown {
  if (node === null || typeof node !== 'object') return node
  switch (node.$) {
    case 'str': return node.v
    case 'mask': return '••••••••'
    case 'type': return `[${node.t}]`
    case 'bin': return `[binary ${node.len} B]`
    case 'float': return node.v
    case 'int': return node.v
    case 'arr': {
      if (node.list === true || node.i === undefined) return (node.i ?? []).map((v) => toPlain(v as Dump))
      return Object.fromEntries((node.i as Array<[string | number, Dump]>).map(([k, v]) => [String(k), toPlain(v)]))
    }
    case 'obj': {
      const props = Object.fromEntries((node.i ?? []).map(([name, , v]) => [name, toPlain(v)]))
      return { __class: node.c, ...props }
    }
  }
}

/** dd()-style text of a value, used by the copy button. */
export function toText(node: Dump): string {
  return JSON.stringify(toPlain(node), null, 2) ?? 'null'
}

function clip(text: string, max: number): string {
  return text.length > max ? `${text.slice(0, max - 1)}…` : text
}
