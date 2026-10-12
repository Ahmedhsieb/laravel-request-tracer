import type { Dump, LineRow, SpanLines } from '../api/types'
import { inline } from './value'

/** What happened on a line of code (the colour of its dot). */
export type DotState = 'ran' | 'false' | 'threw' | 'caught' | 'loop' | 'notrun' | 'plain'

export interface Variable {
  label: string
  value: Dump
}

export interface CodeLine {
  n: number
  text: string
  state: DotState
  /** right-aligned note: "true", "false", "caught", "3 iterations", "not run", the assigned value... */
  chip: string
  /** one plain sentence for the hover card */
  description: string
  variables: Variable[]
  /** order of first execution (for the replay), null when the line did not run */
  seq: number | null
  hits: number
}

export interface CodeBlock {
  file: string
  name: string
  start: number
  end: number
  lines: CodeLine[]
  missing: boolean
  changedSinceTrace: boolean
  /** the function of the step itself */
  own: boolean
  /** shown from its text only, nothing was recorded for its lines */
  untraced: boolean
}

/** Lines that hold no executable statement never get a "not run" mark. */
export function isCodeLine(text: string): boolean {
  const t = text.trim()
  if (t === '') return false
  if (/^(\{|\}|\)|\]|\);?|\];?|\},?|\}\);?|\} else \{|else \{?|else|try \{|try|finally \{|\} finally \{)$/.test(t)) return false
  if (/^(\/\/|\/\*|\*|#\[|#)/.test(t)) return false
  return true
}

export function buildCode(data: SpanLines): CodeBlock[] {
  const rowsByLine = new Map<string, LineRow[]>()
  for (const row of data.lines) {
    const key = `${row.file}:${row.line}`
    const list = rowsByLine.get(key)
    if (list === undefined) rowsByLine.set(key, [row])
    else list.push(row)
  }

  return data.sources.map((source) => {
    const statementSet = source.statements === undefined || source.statements === null ? null : new Set(source.statements)

    return {
      file: source.file,
      name: source.name,
      start: source.start,
      end: source.end,
      missing: source.missing,
      changedSinceTrace: source.changed_since_trace,
      own: source.own === true,
      untraced: source.untraced === true,
      lines: source.lines.map((l) => source.untraced === true
        ? { n: l.n, text: l.text, state: 'plain' as const, chip: '', description: '', variables: [], seq: null, hits: 0 }
        : describeLine(l.n, l.text, rowsByLine.get(`${source.file}:${l.n}`) ?? [], statementSet)),
    }
  })
}

function describeLine(n: number, text: string, rows: LineRow[], statements: ReadonlySet<number> | null): CodeLine {
  const base: CodeLine = { n, text, state: 'plain', chip: '', description: '', variables: [], seq: null, hits: 0 }

  if (rows.length === 0) {
    // with the server's list of probe lines only those can be "not run"; without it, guess from the text
    if (statements !== null ? !statements.has(n) : !isCodeLine(text)) return base
    return { ...base, state: 'notrun', chip: 'not run', description: 'This line did not run in this request.' }
  }

  const seqs = rows.map((r) => r.result?.seq).filter((s): s is number => typeof s === 'number')
  const seq = seqs.length > 0 ? Math.min(...seqs) : null
  const hits = Math.max(...rows.map((r) => r.hits))

  const threw = rows.find((r) => r.kind === 'throw' && r.hits > 0)
  const caught = rows.find((r) => r.kind === 'block' && r.result?.caught !== undefined)
  const loop = rows.find((r) => r.kind === 'loop')
  const conds = rows.filter((r) => r.kind === 'cond' && r.result?.samples !== undefined || r.kind === 'cond' && (r.result?.true !== undefined || r.result?.false !== undefined))
  const assigns = rows.filter((r) => r.kind === 'assign')
  const ret = rows.find((r) => r.kind === 'return')

  const line: CodeLine = { ...base, seq, hits }
  line.variables = variablesOf(rows)

  if (threw !== undefined) {
    const what = threw.result?.exception !== undefined ? inline(threw.result.exception) : 'an exception'
    return { ...line, state: 'threw', chip: 'threw', description: `Threw ${what}.` }
  }
  if (caught !== undefined) {
    const what = caught.result?.caught !== undefined ? inline(caught.result.caught) : 'an exception'
    return { ...line, state: 'caught', chip: 'caught', description: `Caught ${what}; the request continued.` }
  }
  if (loop !== undefined) {
    const n = loop.result?.iterations ?? loop.hits
    return { ...line, state: 'loop', chip: `${n} ${n === 1 ? 'iteration' : 'iterations'}`, description: `The loop ran ${n} ${n === 1 ? 'time' : 'times'}.` }
  }
  if (conds.length > 0) {
    const widest = [...conds].sort((a, b) => (b.snippet?.length ?? 0) - (a.snippet?.length ?? 0))[0] as LineRow
    const t = widest.result?.true ?? 0
    const f = widest.result?.false ?? 0
    const cond = widest.snippet ?? 'the condition'
    if (t === 0 && f > 0) {
      return { ...line, state: 'false', chip: f > 1 ? `false ×${f}` : 'false', description: `Condition ${cond} was false, so the request did not enter this block.` }
    }
    if (f === 0 && t > 0) {
      return { ...line, state: 'ran', chip: t > 1 ? `true ×${t}` : 'true', description: `Condition ${cond} was true, so the request entered this block.` }
    }
    if (t > 0 && f > 0) {
      return { ...line, state: 'ran', chip: `true ×${t} · false ×${f}`, description: `Condition ${cond} was true ${t} ${t === 1 ? 'time' : 'times'} and false ${f} ${f === 1 ? 'time' : 'times'}.` }
    }
    // a `match` / `switch` subject: no true/false, but values
    const values = widest.result?.values
    if (values !== undefined) {
      const text = Object.entries(values).map(([k, v]) => `${k}${v > 1 ? ` ×${v}` : ''}`).join(', ')
      return { ...line, state: 'ran', chip: text, description: `The value being matched was ${text}.` }
    }
  }
  if (ret !== undefined && ret.result?.value !== undefined) {
    return { ...line, state: 'ran', chip: `returns ${inline(ret.result.value, 28)}`, description: `Returned ${inline(ret.result.value)}.` }
  }
  const assign = assigns.find((a) => a.snippet !== 'arguments' && a.result?.last !== undefined)
  if (assign !== undefined && assign.result?.last !== undefined) {
    const name = assign.result.name !== undefined ? `$${assign.result.name}` : 'the variable'
    const changes = assign.result.changes ?? 1
    return {
      ...line,
      state: 'ran',
      chip: inline(assign.result.last, 28),
      description: `${name} was set to ${inline(assign.result.last)}${changes > 1 ? ` (changed ${changes} times)` : ''}.`,
    }
  }
  const enter = assigns.find((a) => a.snippet === 'arguments')
  if (enter !== undefined) {
    return { ...line, state: 'ran', chip: 'arguments', description: 'The function was entered with these arguments.' }
  }
  const arm = rows.find((r) => r.kind === 'block' && r.result?.last !== undefined)
  if (arm !== undefined && arm.result?.last !== undefined) {
    return { ...line, state: 'ran', chip: `→ ${inline(arm.result.last, 28)}`, description: `This branch was taken and produced ${inline(arm.result.last)}.` }
  }

  return { ...line, state: 'ran', chip: hits > 1 ? `×${hits}` : '', description: `This line ran${hits > 1 ? ` ${hits} times` : ''}.` }
}

function variablesOf(rows: LineRow[]): Variable[] {
  const out: Variable[] = []
  for (const row of rows) {
    const r = row.result
    if (r === null) continue
    if (row.kind === 'cond' && r.samples !== undefined) {
      const s = r.samples[0]
      if (s !== undefined) {
        out.push({ label: `left of ${s.op ?? '?'}`, value: s.a })
        out.push({ label: 'right', value: s.b })
      }
    } else if (row.kind === 'assign' && r.last !== undefined) {
      if (row.snippet === 'arguments' && isMap(r.first)) {
        for (const [k, v] of r.first.i as Array<[string, Dump]>) out.push({ label: `$${k}`, value: v })
      } else {
        out.push({ label: r.name !== undefined ? `$${r.name}` : 'value', value: r.last })
      }
    } else if (row.kind === 'loop' && r.snapshots !== undefined) {
      r.snapshots.forEach((snap, i) => {
        if (isMap(snap)) {
          for (const [k, v] of snap.i as Array<[string, Dump]>) out.push({ label: `$${k} (iteration ${i + 1})`, value: v })
        }
      })
    } else if (row.kind === 'return' && r.value !== undefined) {
      out.push({ label: 'returned', value: r.value })
    } else if (row.kind === 'throw' && r.exception !== undefined) {
      out.push({ label: 'thrown', value: r.exception })
    } else if (row.kind === 'block' && r.caught !== undefined) {
      out.push({ label: 'caught', value: r.caught })
    }
  }

  return out
}

function isMap(value: Dump | undefined): value is Extract<Dump, { $: 'arr' }> {
  return value !== undefined && value !== null && typeof value === 'object' && value.$ === 'arr' && value.list !== true && value.i !== undefined
}

/** Lines that ran, in the order they first ran: the script of the "Replay" button. */
export function executionOrder(blocks: CodeBlock[]): Array<{ block: number; line: number; n: number }> {
  const steps: Array<{ block: number; line: number; n: number; seq: number }> = []
  blocks.forEach((block, b) => {
    block.lines.forEach((line, l) => {
      if (line.seq !== null && line.state !== 'notrun' && line.state !== 'plain') steps.push({ block: b, line: l, n: line.n, seq: line.seq })
    })
  })

  return steps.sort((x, y) => x.seq - y.seq || x.n - y.n).map(({ block, line, n }) => ({ block, line, n }))
}

// ---------------------------------------------------------------------------------------------------------------------
// who calls whom: from the text of a function and the names of the functions that ran, never from a guess about what *could* run
// ---------------------------------------------------------------------------------------------------------------------

/** "OrderService::create" / "Order@create" -> "create"; a plain function keeps its name. */
export function shortName(name: string): string {
  const m = /(?:::|@)([A-Za-z_]\w*)$/.exec(name)

  return m?.[1] ?? name
}

/** "OrderService::create" -> "OrderService", a plain function or a closure -> null. */
export function classOf(name: string): string | null {
  const m = /([A-Za-z_]\w*)(?:::|@)[A-Za-z_]\w*$/.exec(name)

  return m?.[1] ?? null
}

export interface Call {
  name: string
  /** the class written in the call (`Money::of(`, `new Money(`), null for `$this->x(`, `$a->x(` and plain functions */
  cls: string | null
}

const SAME_CLASS = new Set(['self', 'static', 'parent', 'this'])

/** The calls written in one line of code: `$this->charge(`, `Money::of(`, `helper(`, `new Money(`. Strings and comments are ignored. */
export function callsIn(text: string): Call[] {
  const t = text.trim()
  if (t === '' || /^(\/\/|\/\*|\*|#)/.test(t)) return []
  const code = t.replace(/'(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*"/g, '""').replace(/\/\/.*$/, '')
  const calls: Call[] = []

  for (const m of code.matchAll(/new\s+\\?(?:[A-Za-z_]\w*\\)*([A-Za-z_]\w*)\s*[(;]/g)) calls.push({ name: '__construct', cls: m[1] ?? null })
  for (const m of code.matchAll(/(?:\\?([A-Za-z_]\w*)::|(->|\?->)|(?<![\w$>:\\]))([A-Za-z_]\w*)\s*\(/g)) {
    const name = m[3] ?? ''
    if (/^(if|elseif|while|for|foreach|switch|match|catch|function|fn|array|list|isset|empty|unset|return|echo|print|exit|die|and|or)$/.test(name)) continue
    // a declaration (`function charge(`) or a construction (`new Money(`, handled above) is not a call of that name
    if (/(?:function|new)\s+&?\\?[\w\\]*$/.test(code.slice(0, m.index))) continue
    const cls = m[1] === undefined || SAME_CLASS.has(m[1].toLowerCase()) ? null : m[1]
    calls.push({ name, cls })
  }

  return calls
}

export interface CallLink {
  /** index of the line in the block that makes the call */
  line: number
  /** which candidate it is (a block index, or a step id) */
  target: number
}

/**
 * Where a function calls the other functions that ran: for each candidate the first line that ran and calls it by name (and by class when the
 * call names one). A candidate is linked once, so a helper called ten times is offered at its first call, not ten times.
 */
export function linkCalls(block: CodeBlock, candidates: Array<{ id: number; name: string }>): CallLink[] {
  const links: CallLink[] = []
  const taken = new Set<number>()
  block.lines.forEach((line, l) => {
    if (line.state === 'notrun' || (line.state === 'plain' && !block.untraced)) return
    for (const call of callsIn(line.text)) {
      for (const candidate of candidates) {
        if (taken.has(candidate.id) || shortName(candidate.name) !== call.name) continue
        if (call.cls !== null && classOf(candidate.name) !== null && classOf(candidate.name) !== call.cls) continue
        taken.add(candidate.id)
        links.push({ line: l, target: candidate.id })
      }
    }
  })

  return links
}

/** Index of the function of the step itself: the one the server marked, else the one that contains the step's line, else -1 (its lines are not under this step). */
export function mainBlock(blocks: CodeBlock[], file: string | null, line: number | null): number {
  const marked = blocks.findIndex((b) => b.own)
  if (marked >= 0) return marked
  if (file !== null && line !== null) {
    const inside = blocks
      .map((b, i) => ({ b, i }))
      .filter(({ b }) => b.file === file && line >= b.start - 3 && line <= b.end)
      .sort((x, y) => (x.b.end - x.b.start) - (y.b.end - y.b.start))[0]
    if (inside !== undefined) return inside.i
  }

  return -1
}

/** The functions reachable from `start` through calls between the functions that ran: what is worth showing under it. */
export function reachableBlocks(blocks: CodeBlock[], start: number): Set<number> {
  const seen = new Set<number>([start])
  const queue = [start]
  while (queue.length > 0) {
    const from = blocks[queue.shift() as number]
    if (from === undefined) continue
    const candidates = blocks.map((b, id) => ({ id, name: b.name })).filter((c) => !seen.has(c.id))
    for (const link of linkCalls(from, candidates)) {
      seen.add(link.target)
      queue.push(link.target)
    }
  }

  return seen
}

/**
 * True when the file is certainly not under one of `code.paths`: every path is relative (an absolute one cannot be compared with a
 * relative file here) and none of them contains the file.
 */
export function outsideCodePaths(file: string | null, paths: string[]): boolean {
  if (file === null || paths.length === 0) return false
  const normalized = file.replace(/\\/g, '/')
  for (const raw of paths) {
    const path = raw.replace(/\\/g, '/').replace(/\/+$/, '')
    if (/^([A-Za-z]:)?\//.test(path)) return false
    if (path === '' || path === '.' || normalized === path || normalized.startsWith(`${path}/`)) return false
  }

  return true
}

/** A folder to suggest for `code.paths`: up to and including the `src` folder of a package, else the folder of the file. */
export function suggestPath(file: string): string {
  const parts = file.replace(/\\/g, '/').split('/')
  const src = parts.indexOf('src')
  if (src > 0) return parts.slice(0, src + 1).join('/')

  return parts.slice(0, -1).join('/')
}
