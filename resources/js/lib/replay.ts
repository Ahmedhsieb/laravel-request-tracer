import type { Dump, JournalEntry, ReplayInput } from '../api/types'
import { toPlain } from './value'

/** What the replay dialog edits. Headers are one "Name: value" per line. */
export interface ReplayForm {
  method: string
  url: string
  headers: string
  body: string
  asUser: string
  /** fields of the recorded request were masked (passwords, tokens): they would be replayed as the mask itself */
  masked: boolean
  /** the body was too large to be kept in the journal: it is empty here, and the replay will not send it */
  bodyCut: boolean
}

export const emptyForm = (): ReplayForm => ({ method: 'GET', url: '', headers: '', body: '', asUser: '', masked: false, bodyCut: false })

const NOISE = new Set(['host', 'content-length', 'connection', 'accept-encoding'])

/**
 * The replay form for a request that was recorded: its method, path and query, its headers (the allow-listed ones that were stored)
 * and its body as JSON. Secrets were masked before the request was stored, so they cannot be sent again: the form says so.
 */
export function formFromRequest(dump: Dump, userId: string | null): ReplayForm | null {
  const plain = toPlain(dump)
  if (plain === null || typeof plain !== 'object' || Array.isArray(plain)) return null
  const request = plain as Record<string, unknown>
  if (typeof request['method'] !== 'string') return null

  const query = request['query']
  const search = isRecord(query) && Object.keys(query).length > 0 ? `?${new URLSearchParams(flatten(query)).toString()}` : ''
  const path = typeof request['path'] === 'string' ? request['path'] : '/'

  const headerLines: string[] = []
  const headers = request['headers']
  if (isRecord(headers)) {
    for (const [name, value] of Object.entries(headers)) {
      if (NOISE.has(name.toLowerCase())) continue
      const first = Array.isArray(value) ? value[0] : value
      if (typeof first === 'string') headerLines.push(`${name}: ${first}`)
    }
  }

  const input = request['input']
  const hasBody = isRecord(input) ? Object.keys(input).length > 0 : Array.isArray(input) && input.length > 0
  const body = hasBody ? JSON.stringify(input, null, 2) : ''
  if (hasBody && !headerLines.some((l) => l.toLowerCase().startsWith('content-type:'))) headerLines.push('Content-Type: application/json')

  return {
    method: String(request['method']).toUpperCase(),
    url: `${path}${search}`,
    headers: headerLines.join('\n'),
    body,
    asUser: userId ?? '',
    masked: JSON.stringify(request).includes('••••••••'),
    bodyCut: false,
  }
}

/** The replay form for an entry of the request journal: what was kept of the request, and the user it ran as. */
export function formFromJournal(entry: JournalEntry): ReplayForm {
  const headerLines = Object.entries(entry.headers ?? {}).map(([name, value]) => `${name}: ${value}`)
  let body = entry.body ?? ''
  const type = (entry.headers?.['content-type'] ?? '').toLowerCase()
  if (body !== '' && (type.includes('json') || body.trimStart().startsWith('{') || body.trimStart().startsWith('['))) {
    try {
      body = JSON.stringify(JSON.parse(body), null, 2)
    } catch {
      // keep it as it was stored
    }
  }

  return {
    method: entry.method.toUpperCase(),
    url: entry.url,
    headers: headerLines.join('\n'),
    body,
    asUser: entry.user_id ?? '',
    masked: entry.masked,
    bodyCut: entry.body_cut,
  }
}

/** What the server is sent for the "describe it" form. */
export function inputFromForm(form: ReplayForm): ReplayInput {
  const input: ReplayInput = { method: form.method.trim().toUpperCase() || 'GET', url: form.url.trim() }
  const headers = parseHeaders(form.headers)
  if (Object.keys(headers).length > 0) input.headers = headers
  if (form.body.trim() !== '') input.body = form.body
  if (form.asUser.trim() !== '') input.as_user = form.asUser.trim()

  return input
}

export function parseHeaders(text: string): Record<string, string> {
  const headers: Record<string, string> = {}
  for (const line of text.split(/\r?\n/)) {
    const at = line.indexOf(':')
    if (at <= 0) continue
    const name = line.slice(0, at).trim()
    if (name !== '') headers[name] = line.slice(at + 1).trim()
  }

  return headers
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

/** `{a: 1, b: {c: 2}}` -> `{a: '1', 'b[c]': '2'}`, the way a form sends nested query values. */
function flatten(values: Record<string, unknown>, prefix = ''): Record<string, string> {
  const out: Record<string, string> = {}
  for (const [key, value] of Object.entries(values)) {
    const name = prefix === '' ? key : `${prefix}[${key}]`
    if (isRecord(value)) Object.assign(out, flatten(value, name))
    else if (Array.isArray(value)) value.forEach((item, i) => Object.assign(out, flatten({ [String(i)]: item }, name)))
    else out[name] = String(value)
  }

  return out
}
