/** "0.4 ms", "12.3 ms", "184 ms", "1.24 s", "2 min 5 s" */
export function formatMs(ms: number): string {
  if (!Number.isFinite(ms)) return '–'
  if (ms < 0.05) return '0 ms'
  if (ms < 10) return `${trim(ms, 2)} ms`
  if (ms < 100) return `${trim(ms, 1)} ms`
  if (ms < 1000) return `${Math.round(ms)} ms`
  if (ms < 60_000) return `${trim(ms / 1000, 2)} s`
  const minutes = Math.floor(ms / 60_000)
  return `${minutes} min ${Math.round((ms % 60_000) / 1000)} s`
}

export function formatUs(us: number): string {
  return formatMs(us / 1000)
}

export function formatBytes(bytes: number): string {
  const abs = Math.abs(bytes)
  const sign = bytes < 0 ? '-' : ''
  if (abs < 1024) return `${bytes} B`
  if (abs < 1024 * 1024) return `${sign}${trim(abs / 1024, 1)} KB`
  if (abs < 1024 * 1024 * 1024) return `${sign}${trim(abs / 1024 / 1024, 1)} MB`
  return `${sign}${trim(abs / 1024 / 1024 / 1024, 2)} GB`
}

/** "just now", "12 s ago", "3 min ago", "2 h ago", "yesterday 14:03", "12 Mar 14:03" */
export function relativeTime(iso: string, now: Date = new Date()): string {
  const then = parseDate(iso)
  if (then === null) return iso
  const seconds = Math.round((now.getTime() - then.getTime()) / 1000)
  if (seconds < 5) return 'just now'
  if (seconds < 60) return `${seconds} s ago`
  if (seconds < 3600) return `${Math.floor(seconds / 60)} min ago`
  if (seconds < 86_400) return `${Math.floor(seconds / 3600)} h ago`
  const hhmm = `${pad(then.getHours())}:${pad(then.getMinutes())}`
  if (seconds < 172_800) return `yesterday ${hhmm}`
  return `${then.getDate()} ${MONTHS[then.getMonth()]} ${hhmm}`
}

export function absoluteTime(iso: string): string {
  const d = parseDate(iso)
  if (d === null) return iso
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`
}

/** The API sends "YYYY-MM-DD HH:MM:SS" (server time, no zone) or ISO strings. */
export function parseDate(value: string): Date | null {
  const normalised = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/.test(value) ? value.replace(' ', 'T') : value
  const date = new Date(normalised)
  return Number.isNaN(date.getTime()) ? null : date
}

export function plural(count: number, singular: string, pluralForm = `${singular}s`): string {
  return `${count} ${count === 1 ? singular : pluralForm}`
}

/** "App\\Services\\OrderService" -> "OrderService" */
export function shortClass(name: string): string {
  const i = name.lastIndexOf('\\')
  return i === -1 ? name : name.slice(i + 1)
}

export function shortFile(file: string | null, line: number | null): string {
  if (file === null) return ''
  const parts = file.replace(/\\/g, '/').split('/')
  const tail = parts.length > 3 ? `…/${parts.slice(-3).join('/')}` : file
  return line !== null ? `${tail}:${line}` : tail
}

export function percent(part: number, total: number): number {
  return total <= 0 ? 0 : (part / total) * 100
}

function trim(value: number, digits: number): string {
  return Number(value.toFixed(digits)).toString()
}

function pad(n: number): string {
  return n.toString().padStart(2, '0')
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
