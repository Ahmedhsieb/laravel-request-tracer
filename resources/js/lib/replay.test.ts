import { describe, expect, it } from 'vitest'
import type { Dump } from '../api/types'
import { emptyForm, formFromRequest, inputFromForm, parseHeaders } from './replay'

const request = (over: Record<string, Dump> = {}): Dump => ({
  $: 'obj', c: 'Illuminate\\Http\\Request', kind: 'request',
  i: Object.entries({
    method: 'POST',
    path: '/api/orders',
    query: { $: 'arr', n: 1, i: [['page', '2']] } as Dump,
    input: { $: 'arr', n: 2, i: [['coupon', 'OLD2020'], ['card_number', { $: 'mask' }]] } as Dump,
    headers: { $: 'arr', n: 3, i: [['content-type', { $: 'arr', n: 1, list: true, i: ['application/json'] }], ['host', { $: 'arr', n: 1, list: true, i: ['shop.test'] }], ['x-request-id', { $: 'arr', n: 1, list: true, i: ['abc'] }]] } as Dump,
    ...over,
  }).map(([k, v]) => [k, 'virtual', v] as [string, string, Dump]),
})

describe('formFromRequest', () => {
  it('turns a recorded request into the form: method, path with query, headers, JSON body, user', () => {
    const form = formFromRequest(request(), '42')!

    expect(form.method).toBe('POST')
    expect(form.url).toBe('/api/orders?page=2')
    expect(form.headers.split('\n')).toEqual(['content-type: application/json', 'x-request-id: abc'])      // `host` is noise
    expect(JSON.parse(form.body)).toEqual({ coupon: 'OLD2020', card_number: '••••••••' })
    expect(form.asUser).toBe('42')
  })

  it('says when something was masked, because the mask cannot be sent back as the secret', () => {
    expect(formFromRequest(request(), null)!.masked).toBe(true)
    expect(formFromRequest(request({ input: { $: 'arr', n: 0, i: [] } }), null)!.masked).toBe(false)
  })

  it('has no body for a request without input, and adds a JSON content type when it has one and none was recorded', () => {
    const empty = formFromRequest(request({ input: { $: 'arr', n: 0, i: [] } }), null)!
    expect(empty.body).toBe('')

    const noType = formFromRequest(request({ headers: { $: 'arr', n: 0, i: [] } }), null)!
    expect(noType.headers).toBe('Content-Type: application/json')
  })

  it('writes nested query values the way a form sends them', () => {
    const nested = request({ query: { $: 'arr', n: 1, i: [['filter', { $: 'arr', n: 2, i: [['status', 'open'], ['tags', { $: 'arr', n: 2, list: true, i: ['a', 'b'] }]] }]] } })

    expect(decodeURIComponent(formFromRequest(nested, null)!.url)).toBe('/api/orders?filter[status]=open&filter[tags][0]=a&filter[tags][1]=b')
  })

  it('gives up on a value that is not a request', () => {
    expect(formFromRequest('text', null)).toBeNull()
    expect(formFromRequest({ $: 'arr', n: 0, i: [] }, null)).toBeNull()
  })
})

describe('inputFromForm', () => {
  it('sends the pieces, trims them, and leaves out what is empty', () => {
    expect(inputFromForm({ ...emptyForm(), method: ' post ', url: ' /x ', headers: 'Accept: application/json\nnot a header\nX-A:  1 ', body: '{"a":1}', asUser: ' 7 ' })).toEqual({
      method: 'POST', url: '/x', headers: { Accept: 'application/json', 'X-A': '1' }, body: '{"a":1}', as_user: '7',
    })
    expect(inputFromForm({ ...emptyForm(), url: '/y' })).toEqual({ method: 'GET', url: '/y' })
  })
})

describe('parseHeaders', () => {
  it('reads "Name: value" lines, keeps colons inside values, and skips lines without a name', () => {
    expect(parseHeaders('Authorization: Bearer a:b\r\n: nameless\nplain')).toEqual({ Authorization: 'Bearer a:b' })
  })
})
