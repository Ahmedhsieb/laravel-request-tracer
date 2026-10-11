import { describe, expect, it } from 'vitest'
import type { LineRow, SpanLines } from '../api/types'
import { buildCode, executionOrder, isCodeLine } from './code'

const source = {
  file: 'app/Services/OrderService.php',
  name: 'OrderService::create',
  start: 10,
  end: 22,
  missing: false,
  changed_since_trace: false,
  lines: [
    'public function create(User $user): Order',
    '{',
    '    if ($user->is_vip) {',
    '        $discount = 10;',
    '    } else {',
    '        $discount = 0;',
    '    }',
    '    foreach ($items as $item) {',
    '        $total += $item->price;',
    '    }',
    '    try { $this->pay(); } catch (PayException $e) {',
    '        return null;',
    '    }',
  ].map((text, i) => ({ n: 10 + i, text })),
}

function row(line: number, kind: LineRow['kind'], hits: number, result: LineRow['result'], snippet = ''): LineRow {
  return { span_id: 1, file: source.file, line, kind, hits, result, snippet }
}

const data: SpanLines = {
  sources: [source],
  lines: [
    row(11, 'assign', 1, { seq: 1, fn: ['OrderService::create', 10, 22], first: { $: 'arr', n: 1, i: [['user', 'u']] }, last: { $: 'arr', n: 1, i: [['user', 'u']] } }, 'arguments'),
    row(12, 'cond', 1, { seq: 2, false: 1, samples: [{ a: false, b: null, op: null, result: false }] }, '$user->is_vip'),
    row(15, 'assign', 1, { seq: 3, name: 'discount', changes: 1, last: 0, first: 0 }, '$discount = 0'),
    row(17, 'loop', 3, { seq: 4, iterations: 3, snapshots: [{ $: 'arr', n: 1, i: [['item', 1]] }] }, '$total += $item->price'),
    row(20, 'block', 1, { seq: 5, caught: { $: 'obj', c: 'PayException', kind: 'exception', i: [['message', 'virtual', 'declined']] } }, 'catch (PayException)'),
    row(21, 'throw', 1, { seq: 6, exception: 'x' }),
  ],
}

describe('buildCode', () => {
  const [block] = buildCode(data)
  const line = (n: number) => block!.lines.find((l) => l.n === n)!

  it('turns a false condition into a purple dot with a plain sentence', () => {
    expect(line(12).state).toBe('false')
    expect(line(12).chip).toBe('false')
    expect(line(12).description).toBe('Condition $user->is_vip was false, so the request did not enter this block.')
  })

  it('marks lines that never ran as not run, but leaves braces and blanks alone', () => {
    expect(line(13).state).toBe('notrun')            // $discount = 10;
    expect(line(13).chip).toBe('not run')
    expect(line(11).state).not.toBe('plain')          // function entry carries the arguments
    expect(line(11).chip).toBe('arguments')
    expect(line(14).state).toBe('plain')              // `} else {`
    expect(line(21).state).toBe('threw')
  })

  it('shows the assigned value and the loop iterations', () => {
    expect(line(15).chip).toBe('0')
    expect(line(15).description).toBe('$discount was set to 0.')
    expect(line(17).state).toBe('loop')
    expect(line(17).chip).toBe('3 iterations')
    expect(line(17).variables[0]!.label).toBe('$item (iteration 1)')
  })

  it('reports a caught exception', () => {
    expect(line(20).state).toBe('caught')
    expect(line(20).description).toContain('PayException: declined')
  })

  it('passes the function arguments to the card of the entry line', () => {
    expect(line(11).variables).toEqual([{ label: '$user', value: 'u' }])
  })

  it('orders executed lines by first execution for the replay', () => {
    const steps = executionOrder([block!])
    expect(steps.map((s) => s.n)).toEqual([11, 12, 15, 17, 20, 21])
  })
})

describe('statements', () => {
  const withStatements: SpanLines = { ...data, sources: [{ ...source, statements: [10, 11, 12, 13, 15, 17, 20, 21] }] }

  it('marks "not run" only on lines that carry a probe', () => {
    const [block] = buildCode({ ...withStatements, lines: data.lines.filter((l) => l.line !== 13) })
    const at = (n: number) => block!.lines.find((l) => l.n === n)!
    expect(at(13).state).toBe('notrun')            // has a probe, never hit
    expect(at(18).state).toBe('plain')             // `}` closing the loop: no probe
    expect(at(19).state).toBe('plain')             // not in the list, although it looks like code
  })

  it('falls back to the text heuristic when the server could not analyse the file', () => {
    const [block] = buildCode({ ...data, sources: [{ ...source, statements: null }] })
    expect(block!.lines.find((l) => l.n === 13)!.state).toBe('notrun')
  })
})

describe('isCodeLine', () => {
  it.each([['{', false], ['}', false], ['} else {', false], ['', false], ['// note', false], ['* @param', false], ['$a = 1;', true], ['return $x;', true], ['if ($a) {', true]])('%j', (text, expected) => {
    expect(isCodeLine(text)).toBe(expected)
  })
})
