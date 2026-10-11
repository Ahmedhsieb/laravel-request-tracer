import { describe, expect, it } from 'vitest'
import type { Dump } from '../api/types'
import { flags, inline, toPlain } from './value'

const model: Dump = {
  $: 'obj', c: 'App\\Models\\Order', kind: 'model',
  i: [['key', 'virtual', 1057], ['attributes', 'virtual', { $: 'arr', n: 1, i: [['password', { $: 'mask' }]] }]],
}

describe('inline', () => {
  it.each([
    [null, 'null'], [true, 'true'], [42, '42'], ['x', "'x'"],
    [{ $: 'arr', n: 3 } as Dump, 'array (3)'], [{ $: 'mask' } as Dump, '••••••••'], [{ $: 'type', t: 'Closure' } as Dump, 'Closure'],
    [{ $: 'float', v: 'INF' } as Dump, 'INF'], [{ $: 'bin', len: 12 } as Dump, 'binary (12 B)'],
  ])('%j', (node, text) => {
    expect(inline(node as Dump)).toBe(text)
  })

  it('names models by class and key', () => {
    expect(inline(model)).toBe('Order #1057')
  })

  it('shows an exception with its message', () => {
    const e: Dump = { $: 'obj', c: 'RuntimeException', kind: 'exception', i: [['message', 'virtual', 'boom']] }
    expect(inline(e)).toBe('RuntimeException: boom')
  })

  it('clips long values', () => {
    expect(inline('x'.repeat(200), 20)).toHaveLength(20)
  })
})

describe('flags', () => {
  it('finds masked and truncated markers anywhere in a value', () => {
    expect(flags(model)).toEqual({ masked: true, truncated: false })
    expect(flags({ $: 'arr', n: 100, i: [1, 2], cut: 98 })).toEqual({ masked: false, truncated: true })
    expect(flags({ $: 'str', v: 'abc', cut: 10 }).truncated).toBe(true)
    expect(flags('plain')).toEqual({ masked: false, truncated: false })
  })
})

describe('toPlain', () => {
  it('turns the stored format back into plain data for copying', () => {
    expect(toPlain({ $: 'arr', n: 3, list: true, i: [1, 2, 3] })).toEqual([1, 2, 3])
    expect(toPlain({ $: 'arr', n: 2, i: [['a', 1], ['b', { $: 'mask' }]] })).toEqual({ a: 1, b: '••••••••' })
    expect(toPlain(model)).toMatchObject({ __class: 'App\\Models\\Order', key: 1057 })
  })
})
